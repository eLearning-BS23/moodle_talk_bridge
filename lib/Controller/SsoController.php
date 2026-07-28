<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Controller;

use OCA\MoodleTalkBridge\Db\SsoNonce;
use OCA\MoodleTalkBridge\Exception\InvalidTicketException;
use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCA\MoodleTalkBridge\Mapper\SsoNonceMapper;
use OCA\MoodleTalkBridge\Service\ProvisioningService;
use OCA\MoodleTalkBridge\Service\TalkService;
use OCA\MoodleTalkBridge\Service\TicketVerifier;
use OC\User\Session;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * D12 seamless authenticated join. PUBLIC browser route: security is the
 * signed single-use ticket, not CSRF/session. See
 * authentication-and-security.md §3 and api-contract.md §2/§6.
 *
 * Session establishment is PINNED against the live NC 35.0.0 server
 * (vendored source + the running `master-nextcloud-1` container, both
 * inspected directly for this task):
 *
 *  - \OCP\IUserSession (the public interface apps are meant to depend on)
 *    declares only login/logout/setUser/getUser/isLoggedIn/impersonation
 *    on this version -- it does NOT declare createSessionToken().
 *  - createSessionToken() is public API only on the concrete
 *    \OC\User\Session (lib/private/User/Session.php), the sole
 *    implementation bound to IUserSession (see Server.php:
 *    registerAlias(IUserSession::class, Session::class)).
 *  - NC core's own passwordless-login path,
 *    OC\Authentication\Login\CreateSessionTokenCommand, type-hints this
 *    same concrete Session class for exactly this reason and calls
 *    createSessionToken(..., password: null, ...) when there is no
 *    password to remember -- precisely our case, since this bridge never
 *    handles (or even knows) the user's NC password.
 *
 * Why setUser()+createSessionToken() and not completeLogin(): setUser()
 * alone only survives the current request (it just writes user_id into
 * the session array). On every *subsequent* request, Session::getUser()
 * re-validates by looking up a device token keyed on the current PHP
 * session id (Session::validateSession() -> validateToken($sessionId)).
 * createSessionToken() is what inserts that device token row (keyed on
 * $this->session->getId()), so without it the browser would appear logged
 * out again on the very next page load. A passwordless token is
 * acceptable here because Talk needs no per-user file-encryption key.
 */
class SsoController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private TicketVerifier $ticketVerifier,
        private ProvisioningService $provisioning,
        private TalkService $talkService,
        private RoomMapper $roomMapper,
        private SsoNonceMapper $nonceMapper,
        private IConfig $config,
        private IUserManager $userManager,
        private Session $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function redirect(string $ticket = ''): Response {
        // 1. Verify signature + TTL. Invalid/expired/malformed -> 403.
        try {
            $secret = $this->config->getAppValue('moodle_talk_bridge', 'shared_secret', '');
            $claims = $this->ticketVerifier->verify($ticket, $secret, time());
        } catch (InvalidTicketException $e) {
            $this->logger->warning('SSO ticket rejected: ' . $e->getMessage());
            return $this->errorPage(Http::STATUS_FORBIDDEN, 'invalid');
        }

        // 2. Resolve room_token by activity_id. Absent/archived -> 404.
        try {
            $room = $this->roomMapper->findByActivityId((int)$claims['activity_id']);
        } catch (DoesNotExistException) {
            return $this->errorPage(Http::STATUS_NOT_FOUND, 'noroom');
        }
        $token = $room->getRoomToken();

        // 3. Resolve/provision the NC user (D1).
        $uid = $this->provisioning->ensureUser(
            (string)$claims['email'], (string)$claims['displayname']
        );

        // 4. Add the participant to the room (idempotent, D7).
        $this->talkService->addParticipant($token, $uid);

        // 5. Consume the nonce (single-use). UNIQUE violation == replay -> 403.
        try {
            $nonce = new SsoNonce();
            $nonce->setNonce((string)$claims['nonce']);
            $nonce->setUid($uid);
            $nonce->setExpires((int)$claims['exp']);
            $this->nonceMapper->insert($nonce);
        } catch (DbException $e) {
            if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $this->logger->warning('SSO nonce replay rejected: ' . (string)$claims['nonce']);
                return $this->errorPage(Http::STATUS_FORBIDDEN, 'replayed');
            }
            throw $e;
        }

        // 6. Establish a passwordless NC session (see class docblock for the
        //    exact API pinned against live NC 35). createSessionToken()
        //    returns bool; if it fails to persist the device token, the
        //    browser would otherwise land unauthenticated at /call/{token}
        //    (a login wall) despite having just cleared ticket + nonce
        //    checks. Fail closed instead of redirecting into that state.
        $user = $this->userManager->get($uid);
        $this->userSession->setUser($user);
        $sessionEstablished = $this->userSession->createSessionToken($this->request, $uid, $uid);
        if (!$sessionEstablished) {
            $this->logger->error('SSO session establishment failed for uid=' . $uid);
            return $this->errorPage(Http::STATUS_INTERNAL_SERVER_ERROR, 'session');
        }

        // 7. Redirect the browser into the call. RedirectResponse defaults
        //    to 303 See Other -- D12 specifies 302, so pin it explicitly.
        // Use the explicit /index.php front controller so the call page
        // resolves whether or not NC pretty-URL rewriting is enabled.
        return new RedirectResponse('/index.php/call/' . $token, Http::STATUS_FOUND);
    }

    private function errorPage(int $status, string $reason): TemplateResponse {
        $response = new TemplateResponse(
            'moodle_talk_bridge', 'sso-error', ['reason' => $reason],
            TemplateResponse::RENDER_AS_GUEST
        );
        $response->setStatus($status);
        return $response;
    }
}
