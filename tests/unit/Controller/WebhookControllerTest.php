<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Controller;

use OCA\MoodleTalkBridge\Controller\WebhookController;
use OCA\MoodleTalkBridge\Exception\ValidationException;
use OCA\MoodleTalkBridge\Mapper\EventDedupeMapper;
use OCA\MoodleTalkBridge\Service\MembershipService;
use OCA\MoodleTalkBridge\Service\RoomOrchestrator;
use OCA\MoodleTalkBridge\Service\SignatureVerifier;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Test double exposing the raw body without touching php://input.
 */
class TestableWebhookController extends WebhookController {
    public string $body = '';
    protected function rawBody(): string {
        return $this->body;
    }
}

class WebhookControllerTest extends TestCase {
    private IRequest&MockObject $request;
    private SignatureVerifier&MockObject $verifier;
    private EventDedupeMapper&MockObject $dedupe;
    private RoomOrchestrator&MockObject $orchestrator;
    private MembershipService&MockObject $membershipService;
    private IConfig&MockObject $config;
    private TestableWebhookController $controller;

    protected function setUp(): void {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->verifier = $this->createMock(SignatureVerifier::class);
        $this->dedupe = $this->createMock(EventDedupeMapper::class);
        $this->orchestrator = $this->createMock(RoomOrchestrator::class);
        $this->membershipService = $this->createMock(MembershipService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')->willReturnMap([
            ['moodle_talk_bridge', 'shared_secret', '', 's3cr3t'],
            ['moodle_talk_bridge', 'allowed_instances', '', 'https://moodle.local'],
        ]);

        $this->request->method('getHeader')->willReturnMap([
            ['X-Moodle-Signature', 'sha256=deadbeef'],
            ['X-Moodle-Timestamp', '1753180000'],
            ['X-Moodle-Nonce', 'nonce-1'],
            ['X-Moodle-Event-Id', 'evt_1'],
            ['X-Moodle-Instance', 'https://moodle.local'],
        ]);

        $this->controller = new TestableWebhookController(
            'moodle_talk_bridge', $this->request, $this->verifier,
            $this->dedupe, $this->orchestrator, $this->membershipService, $this->config);
        $this->controller->body = $this->validBody();
    }

    private function validBody(): string {
        return json_encode([
            'event' => 'room.ensure', 'event_id' => 'evt_1', 'occurred_at' => 1753180000,
            'payload' => [
                'activity_id' => 7, 'activity_name' => 'W1', 'course_shortname' => 'CS101',
                'teacher' => ['email' => 't@uni.edu', 'displayname' => 'T'],
            ],
        ]);
    }

    public function testAppliedReturns200(): void {
        $this->verifier->method('verify')->willReturn(true);
        $this->dedupe->method('claim')->willReturn(true);
        $this->orchestrator->method('ensureRoom')
            ->willReturn(['status' => 'applied', 'room_token' => 'tok9']);

        $resp = $this->controller->index();
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['status' => 'applied', 'room_token' => 'tok9'], $resp->getData());
    }

    public function testSkippedReturns200(): void {
        $this->verifier->method('verify')->willReturn(true);
        $this->dedupe->method('claim')->willReturn(true);
        $this->orchestrator->method('ensureRoom')
            ->willReturn(['status' => 'skipped', 'room_token' => 'tokExisting']);

        $resp = $this->controller->index();
        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame('skipped', $resp->getData()['status']);
    }

    private function membershipRevokeBody(): string {
        return json_encode([
            'event' => 'membership.revoke', 'event_id' => 'evt_1', 'occurred_at' => 1753180000,
            'payload' => [
                'activity_id' => 7, 'user' => ['email' => 'jane@uni.edu'],
            ],
        ]);
    }

    public function testMembershipRevokeRemovedReturns200(): void {
        $this->verifier->method('verify')->willReturn(true);
        $this->dedupe->method('claim')->willReturn(true);
        $this->membershipService->method('revoke')->willReturn('removed');
        $this->orchestrator->expects($this->never())->method('ensureRoom');

        $this->controller->body = $this->membershipRevokeBody();
        $resp = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['status' => 'applied', 'removed' => 1], $resp->getData());
    }

    public function testMembershipRevokeSkippedReturns200(): void {
        $this->verifier->method('verify')->willReturn(true);
        $this->dedupe->method('claim')->willReturn(true);
        $this->membershipService->method('revoke')->willReturn('skipped');

        $this->controller->body = $this->membershipRevokeBody();
        $resp = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['status' => 'skipped'], $resp->getData());
    }

    private function roomArchiveBody(): string {
        return json_encode([
            'event' => 'room.archive', 'event_id' => 'evt_arch_1',
            'occurred_at' => 1753180000, 'payload' => ['activity_id' => 42],
        ]);
    }

    public function testRoomArchiveDispatchesToOrchestrator(): void {
        $this->verifier->method('verify')->willReturn(true);
        $this->dedupe->method('claim')->willReturn(true);
        $this->orchestrator->expects($this->once())
            ->method('archiveRoom')
            ->with(['activity_id' => 42])
            ->willReturn(['status' => 'archived']);

        $this->controller->body = $this->roomArchiveBody();
        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['status' => 'archived'], $response->getData());
    }

    public function testInvalidSignatureReturns401(): void {
        $this->verifier->method('verify')->willReturn(false);
        $this->dedupe->expects($this->never())->method('claim');
        $this->orchestrator->expects($this->never())->method('ensureRoom');

        $resp = $this->controller->index();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
    }

    public function testUnknownInstanceReturns401(): void {
        // A sender whose X-Moodle-Instance is not in allowed_instances is
        // rejected BEFORE signature verification is trusted.
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnMap([
            ['X-Moodle-Signature', 'sha256=deadbeef'],
            ['X-Moodle-Timestamp', '1753180000'],
            ['X-Moodle-Nonce', 'nonce-1'],
            ['X-Moodle-Event-Id', 'evt_1'],
            ['X-Moodle-Instance', 'https://evil.example'],
        ]);
        $this->verifier->expects($this->never())->method('verify');
        $this->dedupe->expects($this->never())->method('claim');
        $this->orchestrator->expects($this->never())->method('ensureRoom');

        $controller = new TestableWebhookController(
            'moodle_talk_bridge', $request, $this->verifier,
            $this->dedupe, $this->orchestrator, $this->membershipService, $this->config);
        $controller->body = $this->validBody();

        $resp = $controller->index();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
    }

    public function testDuplicateReturns409(): void {
        $this->verifier->method('verify')->willReturn(true);
        $this->dedupe->method('claim')->willReturn(false);
        $this->orchestrator->expects($this->never())->method('ensureRoom');

        $resp = $this->controller->index();
        $this->assertSame(Http::STATUS_CONFLICT, $resp->getStatus());
    }

    public function testValidationFailureReturns422(): void {
        $this->verifier->method('verify')->willReturn(true);
        $this->dedupe->method('claim')->willReturn(true);
        $this->orchestrator->method('ensureRoom')
            ->willThrowException(new ValidationException('missing teacher.email'));

        $resp = $this->controller->index();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $resp->getStatus());
    }
}
