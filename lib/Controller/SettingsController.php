<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

class SettingsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    public function save(): RedirectResponse {
        foreach (['nextcloud_url', 'bot_user', 'allowed_instances', 'moodle_host'] as $key) {
            $value = trim((string) $this->request->getParam($key, ''));
            $this->config->setAppValue('moodle_talk_bridge', $key, $value);
        }

        foreach (['shared_secret', 'bot_app_password'] as $secretKey) {
            $value = trim((string) $this->request->getParam($secretKey, ''));
            if ($value !== '') {
                $this->config->setAppValue('moodle_talk_bridge', $secretKey, $value);
            }
        }

        return new RedirectResponse(
            $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'moodle_talk_bridge'])
        );
    }
}