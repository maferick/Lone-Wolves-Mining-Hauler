<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

$defaults = [
  'high' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
  'low' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
  'null' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
  'pochven' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
  'zarzakh' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
  'thera' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
];

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.security_rules' LIMIT 1",
    ['cid' => $corpId]
  );
  if ($row === null && $corpId !== 0) {
    $row = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.security_rules' LIMIT 1"
    );
  }
  $rules = $defaults;
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded)) {
      $rules = array_replace_recursive($rules, $decoded);
    }
  }
  api_send_json(['ok' => true, 'rules' => $rules]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$rules = $payload['rules'] ?? [];
if (!is_array($rules)) {
  api_send_json(['ok' => false, 'error' => 'Invalid rules'], 400);
}

$normalized = [];
foreach ($defaults as $key => $default) {
  $entry = is_array($rules[$key] ?? null) ? $rules[$key] : [];
  $normalized[$key] = [
    'enabled' => array_key_exists('enabled', $entry) ? !empty($entry['enabled']) : $default['enabled'],
    'allow_pickup' => array_key_exists('allow_pickup', $entry) ? !empty($entry['allow_pickup']) : $default['allow_pickup'],
    'allow_delivery' => array_key_exists('allow_delivery', $entry) ? !empty($entry['allow_delivery']) : $default['allow_delivery'],
    'requires_acknowledgement' => array_key_exists('requires_acknowledgement', $entry)
      ? !empty($entry['requires_acknowledgement'])
      : $default['requires_acknowledgement'],
  ];
}

$settingJson = Db::jsonEncode($normalized);
$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json)
   VALUES (:cid, 'routing.security_rules', :json)
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
  'routing.security_rules.update',
  'app_setting',
  'routing.security_rules',
  null,
  $normalized,
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'rules' => $normalized]);
