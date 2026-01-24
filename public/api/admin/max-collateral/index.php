<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);
$defaultMax = 15000000000.0;

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.max_collateral' LIMIT 1",
    ['cid' => $corpId]
  );
  $setting = ['max_collateral_isk' => $defaultMax];
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded)) {
      $setting = array_merge($setting, $decoded);
    }
  }
  $setting['max_collateral_isk'] = (float)($setting['max_collateral_isk'] ?? $defaultMax);
  if ($setting['max_collateral_isk'] <= 0) {
    $setting['max_collateral_isk'] = $defaultMax;
  }

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'max_collateral_isk' => $setting['max_collateral_isk'],
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$maxCollateral = (float)($payload['max_collateral_isk'] ?? $payload['max'] ?? $payload['value'] ?? $defaultMax);

if ($maxCollateral <= 0) {
  api_send_json(['ok' => false, 'error' => 'Max collateral must be greater than zero'], 400);
}

$settingJson = Db::jsonEncode([
  'max_collateral_isk' => $maxCollateral,
]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'pricing.max_collateral', :json, :uid)
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
  'pricing.max_collateral.update',
  'app_setting',
  'pricing.max_collateral',
  null,
  ['max_collateral_isk' => $maxCollateral],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'max_collateral_isk' => $maxCollateral]);
