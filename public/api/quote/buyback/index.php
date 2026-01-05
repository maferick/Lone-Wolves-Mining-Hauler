<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;
use App\Services\BuybackHaulageService;

api_require_key();

if ($db === null || !isset($services['pricing'])) {
  api_send_json([
    'ok' => false,
    'error' => 'database_unavailable',
  ], 503);
}

if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login_required'], 403);
}
if (!Auth::can($authCtx, 'haul.buyback')) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$payload = api_read_json();

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

$settingRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'buyback.haulage' LIMIT 1",
  ['cid' => $corpId]
);
$buybackPrice = 0.0;
$tiers = [];
if ($settingRow && !empty($settingRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$settingRow['setting_json'], []);
  if (is_array($decoded)) {
    $tiers = BuybackHaulageService::normalizeSetting($decoded);
  }
}
$tiers = $tiers ?: BuybackHaulageService::defaultTiers();
if (!BuybackHaulageService::hasEnabledTier($tiers)) {
  api_send_json(['ok' => false, 'error' => 'Buyback haulage price not configured'], 400);
}

$defaultPriority = 'normal';
$prioritySetting = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.default_profile' LIMIT 1",
  ['cid' => $corpId]
);
if ($prioritySetting === null && $corpId !== 0) {
  $prioritySetting = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.default_profile' LIMIT 1"
  );
}
if ($prioritySetting && !empty($prioritySetting['setting_json'])) {
  $decoded = Db::jsonDecode((string)$prioritySetting['setting_json'], null);
  if (is_array($decoded)) {
    $defaultPriority = (string)($decoded['priority'] ?? $decoded['profile'] ?? $defaultPriority);
  } elseif (is_string($decoded)) {
    $defaultPriority = $decoded;
  }
}
$normalizePriority = static function (string $value): string {
  $value = strtolower(trim($value));
  if ($value === 'high') {
    return 'high';
  }
  return 'normal';
};
if (empty($payload['priority']) && empty($payload['profile'])) {
  $payload['priority'] = $normalizePriority($defaultPriority);
}

try {
  $volume = isset($payload['volume_m3']) ? (float)$payload['volume_m3'] : (float)($payload['volume'] ?? 0);
  if ($volume <= 0) {
    api_send_json(['ok' => false, 'error' => 'Volume must be greater than zero'], 400);
  }
  if ($volume > BuybackHaulageService::MAX_VOLUME_M3) {
    api_send_json(['ok' => false, 'error' => 'Buyback haulage volume exceeds 950,000 m³'], 400);
  }
  $buybackPrice = BuybackHaulageService::priceForVolume($tiers, $volume);
  if ($buybackPrice <= 0) {
    api_send_json(['ok' => false, 'error' => 'Buyback haulage price not configured'], 400);
  }
  /** @var \App\Services\PricingService $pricingService */
  $pricingService = $services['pricing'];
  $quote = $pricingService->quote([
    'pickup' => $payload['pickup'] ?? $payload['pickup_system'] ?? null,
    'destination' => $payload['destination'] ?? $payload['destination_system'] ?? null,
    'pickup_location_id' => $payload['pickup_location_id'] ?? null,
    'pickup_location_type' => $payload['pickup_location_type'] ?? null,
    'pickup_system_id' => $payload['pickup_system_id'] ?? null,
    'destination_location_id' => $payload['destination_location_id'] ?? null,
    'destination_location_type' => $payload['destination_location_type'] ?? null,
    'destination_system_id' => $payload['destination_system_id'] ?? null,
    'volume_m3' => $payload['volume_m3'] ?? $payload['volume'] ?? null,
    'collateral_isk' => $payload['collateral_isk'] ?? $payload['collateral'] ?? null,
    'priority' => $payload['priority'] ?? $payload['profile'] ?? $defaultPriority,
  ], $corpId, [
    'corp_id' => $authCtx['corp_id'] ?? null,
    'actor_user_id' => $authCtx['user_id'] ?? null,
    'actor_character_id' => $authCtx['character_id'] ?? null,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  api_send_json([
    'ok' => true,
    'quote_id' => $quote['quote_id'],
    'buyback_price_isk' => $buybackPrice,
    'reward_isk' => $buybackPrice,
    'total_price_isk' => $buybackPrice,
    'price_total_isk' => $buybackPrice,
    'breakdown' => $quote['breakdown'],
    'route' => $quote['route'],
  ]);
} catch (\App\Services\RouteException $e) {
  $details = $e->getDetails();
  $responseDetails = [
    'pickup' => $payload['pickup'] ?? $payload['pickup_system'] ?? null,
    'destination' => $payload['destination'] ?? $payload['destination_system'] ?? null,
    'priority' => $payload['priority'] ?? $payload['profile'] ?? $defaultPriority,
    'resolved_ids' => $details['resolved_ids'] ?? null,
    'graph_loaded' => (bool)($details['graph']['graph_loaded'] ?? false),
    'reason' => $details['reason'] ?? 'no_viable_route',
    'blocked_count_hard' => $details['blocked_count_hard'] ?? null,
    'blocked_count_soft' => $details['blocked_count_soft'] ?? null,
    'message' => $e->getMessage(),
  ];
  error_log('Route failure: ' . json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  api_send_json([
    'ok' => false,
    'error' => 'no_viable_route',
    'details' => $responseDetails,
  ], 400);
} catch (Throwable $e) {
  api_send_json([
    'ok' => false,
    'error' => $e->getMessage(),
  ], 400);
}
