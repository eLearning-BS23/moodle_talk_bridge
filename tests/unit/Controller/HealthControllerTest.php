<?php

declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit\Controller;

use OCA\MoodleTalkBridge\Controller\HealthController;
use OCA\MoodleTalkBridge\Service\TalkService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * The /health endpoint surfaces the TalkService probe verbatim and appends the
 * configured auth_mode (mirrored from Moodle into the app config, see
 * authentication-and-security.md D3; 'hmac' is the only mode shipped in the
 * MVP). TalkService is mocked so this covers only the wiring: all-green, bot
 * rejected (401), and Talk unreachable.
 *
 * NOTE: the route registered in appinfo/routes.php (Milestone 0) is
 * `health#check`, not `health#index` -- the controller method below is named
 * `check()` to match the already-registered action rather than editing the
 * route.
 *
 * @covers \OCA\MoodleTalkBridge\Controller\HealthController
 */
class HealthControllerTest extends TestCase {
    private TalkService&MockObject $talkService;
    private IConfig&MockObject $config;
    private HealthController $controller;

    protected function setUp(): void {
        parent::setUp();
        $this->talkService = $this->createMock(TalkService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')
            ->with('moodle_talk_bridge', 'auth_mode', 'hmac')
            ->willReturn('hmac');
        $this->controller = new HealthController(
            'moodle_talk_bridge',
            $this->createMock(IRequest::class),
            $this->talkService,
            $this->config,
        );
    }

    public function testAllGreen(): void {
        $this->talkService->method('healthCheck')->willReturn([
            'talk_reachable' => true,
            'bot_authenticated' => true,
        ]);

        self::assertSame([
            'talk_reachable' => true,
            'bot_authenticated' => true,
            'auth_mode' => 'hmac',
        ], $this->controller->check()->getData());
    }

    public function testBotRejectedReportsUnauthenticated(): void {
        // spreed answered 401 -> reachable, but the app-password did not authenticate.
        $this->talkService->method('healthCheck')->willReturn([
            'talk_reachable' => true,
            'bot_authenticated' => false,
        ]);

        $data = $this->controller->check()->getData();
        self::assertTrue($data['talk_reachable']);
        self::assertFalse($data['bot_authenticated']);
        self::assertSame('hmac', $data['auth_mode']);
    }

    public function testTalkUnreachable(): void {
        // Connection-level failure -> both false.
        $this->talkService->method('healthCheck')->willReturn([
            'talk_reachable' => false,
            'bot_authenticated' => false,
        ]);

        $data = $this->controller->check()->getData();
        self::assertFalse($data['talk_reachable']);
        self::assertFalse($data['bot_authenticated']);
    }
}
