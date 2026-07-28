<?php

declare(strict_types=1);

namespace OCA\MoodleTalkBridge\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'moodle_talk_bridge';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Services, middleware and jobs are wired here in later milestones.
    }

    public function boot(IBootContext $context): void {
        // No boot-time work in the skeleton.
    }
}
