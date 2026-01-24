<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);
$defaultChecks = [
  'type' => true,
  'start_system' => true,
  'end_system' => true,
  'volume' => true,
];

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.link_validation' LIMIT 1",
    ['cid' => $corpId]
  );
  $setting = $row && !empty($row['setting_json']) ? Db::jsonDecode((string)$row['setting_json'], []) : [];
  $checks = $defaultChecks;
  if (is_array($setting) && isset($setting['checks']) && is_array($setting['checks'])) {
    $checks = array_replace($checks, array_intersect_key($setting['checks'], $defaultChecks));
  }

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'checks' => $checks,
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$incoming = isset($payload['checks']) && is_array($payload['checks']) ? $payload['checks'] : [];
$checks = [];
foreach ($defaultChecks as $key => $default) {
  $checks[$key] = isset($incoming[$key]) ? (bool)$incoming[$key] : $default;
}

$settingJson = Db::jsonEncode(['checks' => $checks]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'contract.link_validation', :json, :uid)
   ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_by_user_id = VALUES(updated_by_user_id)",
  [
    'cid' => $corpId,
    'json' => $settingJson,
    'uid' => (int)($authCtx['user_id'] ?? 0) ?: null,
  ]
);

$db->audit(
  $corpId,
  (int)($authCtx['user_id'] ?? 0) ?: null,
  (int)($authCtx['character_id'] ?? 0) ?: null,
  'settings.contract_link_validation.update',
  'app_setting',
  'contract.link_validation',
  null,
  ['checks' => $checks],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'checks' => $checks]);
