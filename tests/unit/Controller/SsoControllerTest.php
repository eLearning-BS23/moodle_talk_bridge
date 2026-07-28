<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Controller;

use OCA\MoodleTalkBridge\Controller\SsoController;
use OCA\MoodleTalkBridge\Db\RoomMap;
use OCA\MoodleTalkBridge\Db\SsoNonce;
use OCA\MoodleTalkBridge\Exception\InvalidTicketException;
use OCA\MoodleTalkBridge\Mapper\SsoNonceMapper;
use OCA\MoodleTalkBridge\Db\RoomMapper;
use OCA\MoodleTalkBridge\Service\ProvisioningService;
use OCA\MoodleTalkBridge\Service\TalkService;
use OCA\MoodleTalkBridge\Service\TicketVerifier;
use OC\User\Session;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;
use Psr\Log\LoggerInterface;

/**
 * NOTE on the session double: \OCP\IUserSession (the public interface) does
 * NOT declare createSessionToken() on live NC 35 -- only the concrete
 * \OC\User\Session implementation does (confirmed against the vendored
 * server source and the running container; see SsoController's class
 * docblock and task-12-report.md). NC core's own login chain
 * (OC\Authentication\Login\CreateSessionTokenCommand) type-hints the same
 * concrete class for the same reason, so SsoController does too and this
 * test mocks that concrete class rather than the interface.
 */
class SsoControllerTest extends TestCase {
    private IRequest&MockObject $request;
    private TicketVerifier&MockObject $ticketVerifier;
    private ProvisioningService&MockObject $provisioning;
    private TalkService&MockObject $talkService;
    private RoomMapper&MockObject $roomMapper;
    private SsoNonceMapper&MockObject $nonceMapper;
    private IConfig&MockObject $config;
    private IUserManager&MockObject $userManager;
    private Session&MockObject $userSession;
    private SsoController $controller;

    protected function setUp(): void {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->ticketVerifier = $this->createMock(TicketVerifier::class);
        $this->provisioning = $this->createMock(ProvisioningService::class);
        $this->talkService = $this->createMock(TalkService::class);
        $this->roomMapper = $this->createMock(RoomMapper::class);
        $this->nonceMapper = $this->createMock(SsoNonceMapper::class);
        $this->config = $this->createMock(IConfig::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(Session::class);
        $this->config->method('getAppValue')
            ->with('moodle_talk_bridge', 'shared_secret', '')
            ->willReturn('s3cr3t-shared');

        $this->controller = new SsoController(
            'moodle_talk_bridge', $this->request, $this->ticketVerifier,
            $this->provisioning, $this->talkService, $this->roomMapper,
            $this->nonceMapper, $this->config, $this->userManager,
            $this->userSession, $this->createMock(LoggerInterface::class),
        );
    }

    /** @return array<string,mixed> */
    private function claims(): array {
        return [
            'email' => 'jane@uni.edu', 'displayname' => 'Jane Doe',
            'activity_id' => 42, 'role' => 'participant',
            'exp' => 2000000000, 'nonce' => 'n-1',
        ];
    }

    /**
     * RoomMap's getters/setters are magic (Entity::__call), so PHPUnit
     * cannot mock/configure them directly -- construct a real entity
     * instead, matching the existing RoomMapperTest convention.
     */
    private function roomFor(string $token): RoomMap {
        $room = new RoomMap();
        $room->setRoomToken($token);
        return $room;
    }

    public function testHappyPathRedirectsAndAddsParticipant(): void {
        $this->ticketVerifier->method('verify')->willReturn($this->claims());
        $this->roomMapper->method('findByActivityId')->with(42)->willReturn($this->roomFor('tok123'));
        $this->provisioning->method('ensureUser')->with('jane@uni.edu', 'Jane Doe')->willReturn('nc-jane');
        $this->talkService->expects($this->once())->method('addParticipant')->with('tok123', 'nc-jane');
        $this->nonceMapper->expects($this->once())->method('insert')
            ->with($this->isInstanceOf(SsoNonce::class));
        $this->userManager->method('get')->with('nc-jane')->willReturn($this->createMock(IUser::class));
        $this->userSession->method('createSessionToken')->willReturn(true);

        $response = $this->controller->redirect('valid.ticket');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/index.php/call/tok123', $response->getRedirectURL());
        $this->assertSame(Http::STATUS_FOUND, $response->getStatus());
    }

    public function testSessionEstablishedWithResolvedUid(): void {
        $this->ticketVerifier->method('verify')->willReturn($this->claims());
        $this->roomMapper->method('findByActivityId')->willReturn($this->roomFor('tok123'));
        $this->provisioning->method('ensureUser')->willReturn('nc-jane');
        $user = $this->createMock(IUser::class);
        $this->userManager->expects($this->once())->method('get')->with('nc-jane')->willReturn($user);
        $this->userSession->expects($this->once())->method('setUser')->with($user);
        $this->userSession->expects($this->once())->method('createSessionToken')
            ->with($this->request, 'nc-jane', 'nc-jane')->willReturn(true);

        $response = $this->controller->redirect('valid.ticket');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * createSessionToken() returns bool. If session establishment fails,
     * redirecting to /call/{token} anyway would drop the browser at NC's
     * login wall despite a fully verified ticket -- fail closed instead.
     */
    public function testSessionEstablishmentFailureReturnsErrorNotRedirect(): void {
        $this->ticketVerifier->method('verify')->willReturn($this->claims());
        $this->roomMapper->method('findByActivityId')->willReturn($this->roomFor('tok123'));
        $this->provisioning->method('ensureUser')->willReturn('nc-jane');
        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with('nc-jane')->willReturn($user);
        $this->userSession->expects($this->once())->method('setUser')->with($user);
        $this->userSession->expects($this->once())->method('createSessionToken')
            ->with($this->request, 'nc-jane', 'nc-jane')->willReturn(false);

        $response = $this->controller->redirect('valid.ticket');

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }

    public function testInvalidTicketReturns403(): void {
        $this->ticketVerifier->method('verify')
            ->willThrowException(new InvalidTicketException('bad signature'));
        $response = $this->controller->redirect('bad.ticket');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testUnknownActivityReturns404(): void {
        $this->ticketVerifier->method('verify')->willReturn($this->claims());
        $this->roomMapper->method('findByActivityId')
            ->willThrowException(new DoesNotExistException('no room'));
        $response = $this->controller->redirect('valid.ticket');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testReplayedNonceReturns403(): void {
        $this->ticketVerifier->method('verify')->willReturn($this->claims());
        $this->roomMapper->method('findByActivityId')->willReturn($this->roomFor('tok123'));
        $this->provisioning->method('ensureUser')->willReturn('nc-jane');
        $dbEx = $this->createMock(DbException::class);
        $dbEx->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->nonceMapper->method('insert')->willThrowException($dbEx);

        // Regression lock: the nonce-claim (step 5) MUST happen before
        // session establishment (step 6). A replayed ticket must never
        // reach setUser()/createSessionToken() -- otherwise a replay could
        // still leave the browser logged in as the victim even though the
        // request is rejected with 403.
        $this->userSession->expects($this->never())->method('setUser');
        $this->userSession->expects($this->never())->method('createSessionToken');

        $response = $this->controller->redirect('valid.ticket');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }
}
