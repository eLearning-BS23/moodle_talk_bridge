<?php

declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Tests\Unit;

use OCA\MoodleTalkBridge\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IBootstrap;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase {

    public function testAppIdConstant(): void {
        $this->assertSame('moodle_talk_bridge', Application::APP_ID);
    }

    public function testApplicationBootsAsBootstrap(): void {
        $app = new Application();
        $this->assertInstanceOf(IBootstrap::class, $app);
    }
}
