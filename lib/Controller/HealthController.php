<?php

declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Controller;

use OCA\MoodleTalkBridge\Service\TalkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Connectivity/auth probe backing the Moodle admin "Test connection" button and
 * CI smoke tests. Returns booleans only -- no secrets. Public (no NC session):
 * the caller is Moodle's server or a smoke test, not a browser session.
 *
 * The route registered in appinfo/routes.php (Milestone 0) is `health#check`,
 * so this method is named check() -- there is no separate index() action to
 * match, and renaming the route would be a wider change than this task calls
 * for.
 */
class HealthController extends Controller {
    private const APP_ID = 'moodle_talk_bridge';

    public function __construct(
        string $appName,
        IRequest $request,
        private TalkService $talkService,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function check(): JSONResponse {
        $probe = $this->talkService->healthCheck();

        return new JSONResponse([
            'talk_reachable' => $probe['talk_reachable'],
            'bot_authenticated' => $probe['bot_authenticated'],
            // Mirrored from Moodle's auth_mode setting (D3); 'hmac' is the
            // only mode shipped in the MVP, so it's also the default here.
            'auth_mode' => $this->config->getAppValue(self::APP_ID, 'auth_mode', 'hmac'),
        ]);
    }
}
