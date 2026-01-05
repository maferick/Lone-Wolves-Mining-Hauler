<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

$defaults = [
  'thresholds' => [
    'highsec_min' => 0.5,
    'lowsec_min' => 0.1,
  ],
  'special' => [
    'pochven' => [
      'enabled' => true,
      'region_names' => ['Pochven'],
      'system_names' => [],
    ],
    'zarzakh' => [
      'enabled' => true,
      'region_names' => [],
      'system_names' => ['Zarzakh'],
    ],
    'thera' => [
      'enabled' => false,
      'region_names' => [],
      'system_names' => ['Thera'],
    ],
  ],
];

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.security_classes' LIMIT 1",
    ['cid' => $corpId]
  );
  if ($row === null && $corpId !== 0) {
    $row = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.security_classes' LIMIT 1"
    );
  }
  $payload = $defaults;
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded)) {
      $payload = array_replace_recursive($payload, $decoded);
    }
  }
  api_send_json(['ok' => true] + $payload);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$thresholds = $payload['thresholds'] ?? [];
$special = $payload['special'] ?? [];

if (!is_array($thresholds)) {
  api_send_json(['ok' => false, 'error' => 'Invalid thresholds'], 400);
}
if (!is_array($special)) {
  api_send_json(['ok' => false, 'error' => 'Invalid special definitions'], 400);
}

$thresholds = array_merge($defaults['thresholds'], $thresholds);
$thresholds['highsec_min'] = (float)($thresholds['highsec_min'] ?? 0.5);
$thresholds['lowsec_min'] = (float)($thresholds['lowsec_min'] ?? 0.1);

$normalizedSpecial = [];
foreach (['pochven', 'zarzakh', 'thera'] as $key) {
  $entry = array_merge($defaults['special'][$key], $special[$key] ?? []);
  $normalizedSpecial[$key] = [
    'enabled' => !empty($entry['enabled']),
    'region_names' => array_values(array_filter(array_map('trim', $entry['region_names'] ?? []))),
    'system_names' => array_values(array_filter(array_map('trim', $entry['system_names'] ?? []))),
  ];
}

$settingJson = Db::jsonEncode([
  'thresholds' => $thresholds,
  'special' => $normalizedSpecial,
]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json)
   VALUES (:cid, 'routing.security_classes', :json)
   ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json)",
  [
    'cid' => $corpId,
    'json' => $settingJson,
  ]
);

$db->audit(
  $corpId,
  (int)($authCtx['user_id'] ?? 0) ?: null,
  (int)($authCtx['character_id'] ?? 0) ?: null,
  'routing.security_classes.update',
  'app_setting',
  'routing.security_classes',
  null,
  ['thresholds' => $thresholds, 'special' => $normalizedSpecial],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'thresholds' => $thresholds, 'special' => $normalizedSpecial]);
