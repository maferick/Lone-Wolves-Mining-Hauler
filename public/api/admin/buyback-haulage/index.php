<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Db\Db;
use App\Services\BuybackHaulageService;

api_require_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$corpId = (int)($_GET['corp_id'] ?? 0);

if ($method === 'GET') {
  $row = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'buyback.haulage' LIMIT 1",
    ['cid' => $corpId]
  );
  $setting = $row && !empty($row['setting_json']) ? Db::jsonDecode((string)$row['setting_json'], []) : [];
  $tiers = is_array($setting) ? BuybackHaulageService::normalizeSetting($setting) : BuybackHaulageService::defaultTiers();

  api_send_json([
    'ok' => true,
    'corp_id' => $corpId,
    'tiers' => $tiers,
  ]);
}

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? $corpId);
$tiersPayload = $payload['tiers'] ?? null;
$price = isset($payload['price_isk']) ? (float)$payload['price_isk'] : null;

if (!is_array($tiersPayload) && $price !== null && $price < 0) {
  api_send_json(['ok' => false, 'error' => 'Buyback haulage price must be zero or higher'], 400);
}

$tiers = BuybackHaulageService::normalizeTiers(is_array($tiersPayload) ? $tiersPayload : null, $price);
$tiers = array_values(array_slice($tiers, 0, BuybackHaulageService::TIER_COUNT));
$errors = [];
if (count($tiers) !== BuybackHaulageService::TIER_COUNT) {
  $errors[] = 'Four tiers are required.';
}
$prevMax = 0.0;
foreach ($tiers as $idx => $tier) {
  $max = (float)($tier['max_m3'] ?? 0.0);
  $priceValue = (float)($tier['price_isk'] ?? 0.0);
  if ($max <= 0) {
    $errors[] = 'Each tier must have a max volume greater than zero.';
    break;
  }
  if ($max <= $prevMax) {
    $errors[] = 'Tier max volumes must increase.';
    break;
  }
  if ($max > BuybackHaulageService::MAX_VOLUME_M3) {
    $errors[] = 'Tier max volume cannot exceed 950,000 m³.';
    break;
  }
  if ($priceValue < 0) {
    $errors[] = 'Tier price must be zero or higher.';
    break;
  }
  $prevMax = $max;
}
if (abs($prevMax - BuybackHaulageService::MAX_VOLUME_M3) > 0.0001) {
  $errors[] = 'Final tier must end at 950,000 m³.';
}
if ($errors) {
  api_send_json(['ok' => false, 'error' => $errors[0]], 400);
}

$settingJson = Db::jsonEncode(['tiers' => $tiers]);

$db->execute(
  "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
   VALUES (:cid, 'buyback.haulage', :json, :uid)
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
  'settings.buyback_haulage.update',
  'app_setting',
  'buyback.haulage',
  null,
  ['tiers' => $tiers],
  $_SERVER['REMOTE_ADDR'] ?? null,
  $_SERVER['HTTP_USER_AGENT'] ?? null
);

api_send_json(['ok' => true, 'corp_id' => $corpId, 'tiers' => $tiers]);
