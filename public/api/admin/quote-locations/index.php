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
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'quote.location_mode' LIMIT 1",
    ['cid' => $corpId]
  );
  $setting = $row && !empty($row['setting_json']) ? Db::jsonDecode((string)$row['setting_json'], []) : [];
  $allowStructures = true;
  if (is_array($setting) && array_key_exists('allow_structures', $setting)) {
    $allowStructures = (bool)$setting['allow_structures'];
  }

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'allow_structures' => $allowStructures,
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$allowStructures = (bool)($payload['allow_structures'] ?? true);

$settingJson = Db::jsonEncode(['allow_structures' => $allowStructures]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'quote.location_mode', :json, :uid)
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
  'settings.quote_location_mode.update',
  'app_setting',
  'quote.location_mode',
  null,
  ['allow_structures' => $allowStructures],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'allow_structures' => $allowStructures]);
