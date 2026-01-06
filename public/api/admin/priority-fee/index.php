<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.priority_fee' LIMIT 1",
    ['cid' => $corpId]
  );
  $defaults = ['normal' => 0.0, 'high' => 0.0];
  $setting = $defaults;
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded)) {
      $setting = array_merge($setting, $decoded);
    }
  }
  $setting['normal'] = max(0.0, (float)($setting['normal'] ?? 0.0));
  $setting['high'] = max(0.0, (float)($setting['high'] ?? 0.0));

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'priority_fee' => $setting,
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$normal = (float)($payload['normal'] ?? 0.0);
$high = (float)($payload['high'] ?? 0.0);

if ($normal < 0 || $high < 0) {
  api_send_json(['ok' => false, 'error' => 'Priority fees must be zero or higher'], 400);
}

$settingJson = Db::jsonEncode([
  'normal' => $normal,
  'high' => $high,
]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'routing.priority_fee', :json, :uid)
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
  'routing.priority_fee.update',
  'app_setting',
  'routing.priority_fee',
  null,
  ['normal' => $normal, 'high' => $high],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'priority_fee' => ['normal' => $normal, 'high' => $high]]);
