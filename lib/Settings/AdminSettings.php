<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(private IConfig $config) {}

    public function getForm(): TemplateResponse {
        return new TemplateResponse('moodle_talk_bridge', 'admin', [
            'nextcloud_url'     => $this->config->getAppValue('moodle_talk_bridge', 'nextcloud_url', ''),
            'bot_user'          => $this->config->getAppValue('moodle_talk_bridge', 'bot_user', ''),
            'allowed_instances' => $this->config->getAppValue('moodle_talk_bridge', 'allowed_instances', ''),
            'moodle_host'       => $this->config->getAppValue('moodle_talk_bridge', 'moodle_host', ''),
            'has_secret'        => $this->config->getAppValue('moodle_talk_bridge', 'shared_secret', '') !== '',
            'has_bot_password'  => $this->config->getAppValue('moodle_talk_bridge', 'bot_app_password', '') !== '',
        ]);
    }

    public function getSection(): string {
        return 'moodle_talk_bridge'; // Must match AdminSection::getID()
    }

    public function getPriority(): int {
        return 50;
    }
}