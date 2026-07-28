<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Inside the Nextcloud container (apps-extra/moodle_talk_bridge is mounted at
// the same depth as core apps/*), boot the real server so tests exercise the
// real OCP\AppFramework\Controller, IRequest, IConfig, IDBConnection, etc.
// On the host (no NC install available) fall back to the minimal stubs below.
$ncBase = __DIR__ . '/../../../lib/base.php';
if (!defined('PHPUNIT_RUN') && file_exists($ncBase)) {
    define('PHPUNIT_RUN', 1);
    require_once $ncBase;
    require_once __DIR__ . '/../../../tests/autoload.php';
    \OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('moodle_talk_bridge');
}

// Minimal OCP stubs so unit tests run without a full Nextcloud runtime.
// At runtime Nextcloud provides the real implementations of these symbols.
if (!class_exists(\OCP\AppFramework\App::class)) {
    eval(<<<'PHP'
namespace OCP\AppFramework {
    class App {
        private string $appName;
        public function __construct(string $appName, array $urlParams = []) {
            $this->appName = $appName;
        }
        public function getAppName(): string {
            return $this->appName;
        }
    }
}
namespace OCP\AppFramework\Bootstrap {
    interface IRegistrationContext {}
    interface IBootContext {}
    interface IBootstrap {
        public function register(IRegistrationContext $context): void;
        public function boot(IBootContext $context): void;
    }
}
PHP);
}
