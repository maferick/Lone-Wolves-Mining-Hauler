<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'contract.attach_enabled' LIMIT 1",
    ['cid' => $corpId]
  );
  $setting = $row && !empty($row['setting_json']) ? Db::jsonDecode((string)$row['setting_json'], []) : [];
  $enabled = true;
  if (is_array($setting) && array_key_exists('enabled', $setting)) {
    $enabled = (bool)$setting['enabled'];
  }

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'attach_enabled' => $enabled,
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$enabled = (bool)($payload['attach_enabled'] ?? true);

$settingJson = Db::jsonEncode(['enabled' => $enabled]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'contract.attach_enabled', :json, :uid)
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
  'settings.contract_attach.update',
  'app_setting',
  'contract.attach_enabled',
  null,
  ['enabled' => $enabled],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'attach_enabled' => $enabled]);
