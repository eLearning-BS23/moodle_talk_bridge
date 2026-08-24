<?php

declare(strict_types=1);

// OCS webhook (server-to-server, HMAC-signed):
//   /ocs/v2.php/apps/moodle_talk_bridge/api/v1/webhook
// Public browser/probe routes:
//   /apps/moodle_talk_bridge/sso     (signed-ticket authenticated join, D12)
//   /apps/moodle_talk_bridge/health  (connectivity + auth probe)
// See specs/02-integration/api-contract.md.
return [
    'ocs' => [
        ['name' => 'webhook#index', 'url' => '/api/v1/webhook', 'verb' => 'POST'],
    ],
    'routes' => [
        ['name' => 'sso#redirect', 'url' => '/sso', 'verb' => 'GET'],
        ['name' => 'health#check', 'url' => '/health', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/settings/save', 'verb' => 'POST'],
    ],
];
