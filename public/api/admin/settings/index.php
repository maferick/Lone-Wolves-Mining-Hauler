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
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.reward_tolerance' LIMIT 1",
    ['cid' => $corpId]
  );
  $setting = $row && !empty($row['setting_json']) ? Db::jsonDecode((string)$row['setting_json'], []) : [];
  $setting = array_merge(['type' => 'percent', 'value' => 0.0], $setting);

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'reward_tolerance' => $setting,
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$type = strtolower(trim((string)($payload['type'] ?? 'percent')));
$value = (float)($payload['value'] ?? 0.0);

if (!in_array($type, ['percent', 'flat'], true)) {
  api_send_json(['ok' => false, 'error' => 'Invalid tolerance type'], 400);
}
if ($value < 0) {
  api_send_json(['ok' => false, 'error' => 'Tolerance value must be zero or higher'], 400);
}

$settingJson = Db::jsonEncode(['type' => $type, 'value' => $value]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'contract.reward_tolerance', :json, :uid)
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
  'settings.reward_tolerance.update',
  'app_setting',
  'contract.reward_tolerance',
  null,
  ['type' => $type, 'value' => $value],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'reward_tolerance' => ['type' => $type, 'value' => $value]]);
