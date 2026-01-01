<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Db\Db;

api_require_key();

$payload = api_read_json();

$corpId = (int)($authCtx['corp_id'] ?? 0);
$defaultProfile = 'shortest';
$settingRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.default_profile' LIMIT 1",
  ['cid' => $corpId]
);
if ($settingRow === null && $corpId !== 0) {
  $settingRow = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.default_profile' LIMIT 1"
  );
}
if ($settingRow && !empty($settingRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$settingRow['setting_json'], null);
  if (is_array($decoded) && isset($decoded['profile'])) {
    $defaultProfile = (string)$decoded['profile'];
  } elseif (is_string($decoded)) {
    $defaultProfile = $decoded;
  }
}
if (empty($payload['profile'])) {
  $payload['profile'] = $defaultProfile;
}

try {
  /** @var \App\Services\PricingService $pricingService */
  $pricingService = $services['pricing'];
  $quote = $pricingService->quote([
    'pickup' => $payload['pickup'] ?? $payload['pickup_system'] ?? null,
    'destination' => $payload['destination'] ?? $payload['destination_system'] ?? null,
    'volume_m3' => $payload['volume_m3'] ?? $payload['volume'] ?? null,
    'collateral_isk' => $payload['collateral_isk'] ?? $payload['collateral'] ?? null,
    'profile' => $payload['profile'] ?? $defaultProfile,
  ], $corpId);

  api_send_json([
    'ok' => true,
    'quote_id' => $quote['quote_id'],
    'total_price_isk' => $quote['price_total'],
    'price_total_isk' => $quote['price_total'],
    'breakdown' => $quote['breakdown'],
    'route' => $quote['route'],
  ]);
} catch (Throwable $e) {
  api_send_json([
    'ok' => false,
    'error' => $e->getMessage(),
  ], 400);
}
