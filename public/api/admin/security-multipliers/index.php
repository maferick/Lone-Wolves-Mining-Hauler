<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

$defaults = [
  'high' => 1.0,
  'low' => 1.5,
  'null' => 2.5,
  'pochven' => 3.0,
  'zarzakh' => 3.5,
  'thera' => 3.0,
];

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.security_multipliers' LIMIT 1",
    ['cid' => $corpId]
  );
  if ($row === null && $corpId !== 0) {
    $row = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.security_multipliers' LIMIT 1"
    );
  }
  $multipliers = $defaults;
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded)) {
      $multipliers = array_merge($multipliers, $decoded);
    }
  }
  api_send_json(['ok' => true, 'multipliers' => $multipliers]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$multipliers = $payload['multipliers'] ?? [];
if (!is_array($multipliers)) {
  api_send_json(['ok' => false, 'error' => 'Invalid multipliers'], 400);
}

$normalized = [];
foreach ($defaults as $key => $default) {
  $value = isset($multipliers[$key]) ? (float)$multipliers[$key] : $default;
  $normalized[$key] = max(0.0, $value);
}

$settingJson = Db::jsonEncode($normalized);
$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json)
   VALUES (:cid, 'pricing.security_multipliers', :json)
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
  'pricing.security_multipliers.update',
  'app_setting',
  'pricing.security_multipliers',
  null,
  $normalized,
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'multipliers' => $normalized]);
