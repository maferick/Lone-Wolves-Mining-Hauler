<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Db\Db;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

$defaults = ['lowsec' => 0.0, 'nullsec' => 0.0, 'special' => 0.0];

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.flat_risk_fees' LIMIT 1",
    ['cid' => $corpId]
  );
  if ($row === null && $corpId !== 0) {
    $row = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.flat_risk_fees' LIMIT 1"
    );
  }
  $fees = $defaults;
  if ($row && !empty($row['setting_json'])) {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded)) {
      $fees = array_merge($fees, $decoded);
    }
  }
  api_send_json(['ok' => true, 'fees' => $fees]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$fees = [
  'lowsec' => max(0.0, (float)($payload['lowsec'] ?? 0.0)),
  'nullsec' => max(0.0, (float)($payload['nullsec'] ?? 0.0)),
  'special' => max(0.0, (float)($payload['special'] ?? 0.0)),
];

$settingJson = Db::jsonEncode($fees);
$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json)
   VALUES (:cid, 'pricing.flat_risk_fees', :json)
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
  'pricing.flat_risk_fees.update',
  'app_setting',
  'pricing.flat_risk_fees',
  null,
  $fees,
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'fees' => $fees]);
