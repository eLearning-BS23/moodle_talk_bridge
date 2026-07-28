<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Controller;

use OCA\MoodleTalkBridge\Exception\ValidationException;
use OCA\MoodleTalkBridge\Mapper\EventDedupeMapper;
use OCA\MoodleTalkBridge\Service\MembershipService;
use OCA\MoodleTalkBridge\Service\RoomOrchestrator;
use OCA\MoodleTalkBridge\Service\SignatureVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * POST /api/v1/webhook — verify HMAC, dedupe on event_id, dispatch to the
 * orchestrator. Auth is the signature, not CSRF/session.
 */
class WebhookController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private SignatureVerifier $verifier,
        private EventDedupeMapper $dedupe,
        private RoomOrchestrator $orchestrator,
        private MembershipService $membershipService,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /** Injection seam for tests. */
    protected function rawBody(): string {
        return file_get_contents('php://input') ?: '';
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse {
        $body = $this->rawBody();
        $signature = $this->request->getHeader('X-Moodle-Signature');
        $timestamp = (int) $this->request->getHeader('X-Moodle-Timestamp');
        $nonce = $this->request->getHeader('X-Moodle-Nonce');
        $instance = $this->request->getHeader('X-Moodle-Instance');

        // 1. Sender identity allow-list — reject unknown instances BEFORE trusting
        //    signature verification. allowed_instances is a comma-separated setting.
        $allowed = array_filter(array_map('trim', explode(',',
            $this->config->getAppValue('moodle_talk_bridge', 'allowed_instances', ''))));
        if (!in_array($instance, $allowed, true)) {
            return new JSONResponse(['status' => 'error', 'error' => 'unknown instance'],
                Http::STATUS_UNAUTHORIZED);
        }

        // 2. Signature (message = timestamp.nonce.canonical, sha256= prefix).
        $secret = $this->config->getAppValue('moodle_talk_bridge', 'shared_secret', '');
        if (!$this->verifier->verify($body, $signature, $timestamp, $nonce, $secret, time())) {
            return new JSONResponse(['status' => 'error', 'error' => 'invalid signature'],
                Http::STATUS_UNAUTHORIZED);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['event']) || empty($data['event_id'])) {
            return new JSONResponse(['status' => 'error', 'error' => 'malformed envelope'],
                Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        if (!$this->dedupe->claim((string) $data['event_id'])) {
            return new JSONResponse(['status' => 'duplicate'], Http::STATUS_CONFLICT);
        }

        $payload = (array) ($data['payload'] ?? []);
        try {
            $responseData = match ($data['event']) {
                'room.ensure' => $this->dispatchRoomEnsure($payload),
                'membership.revoke' => $this->dispatchMembershipRevoke($payload),
                'room.archive' => $this->orchestrator->archiveRoom($payload),
                default => throw new ValidationException('unknown event: ' . $data['event']),
            };
        } catch (ValidationException $e) {
            return new JSONResponse(['status' => 'error', 'error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return new JSONResponse($responseData, Http::STATUS_OK);
    }

    /** @param array<string,mixed> $payload */
    private function dispatchRoomEnsure(array $payload): array {
        $result = $this->orchestrator->ensureRoom($payload);
        return ['status' => $result['status'], 'room_token' => $result['room_token']];
    }

    /**
     * membership.revoke (Flow 5, D7): 'removed' -> applied + removed:1;
     * 'skipped' (no user/room mapping) -> skipped, still HTTP 200 —
     * idempotent, never an error.
     *
     * @param array<string,mixed> $payload
     */
    private function dispatchMembershipRevoke(array $payload): array {
        $result = $this->membershipService->revoke($payload);
        return $result === 'removed'
            ? ['status' => 'applied', 'removed' => 1]
            : ['status' => 'skipped'];
    }
}
