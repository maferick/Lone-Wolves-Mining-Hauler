<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

api_require_key();

$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login required'], 403);
}
if (!Auth::can($authCtx, 'haul.buyback')) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$payload = api_read_json();
$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

if ($db === null || empty($services['pricing']) || empty($services['haul_request'])) {
  api_send_json(['ok' => false, 'error' => 'service unavailable'], 503);
}

$settingRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'buyback.haulage' LIMIT 1",
  ['cid' => $corpId]
);
$buybackPrice = 0.0;
if ($settingRow && !empty($settingRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$settingRow['setting_json'], []);
  if (is_array($decoded)) {
    $buybackPrice = max(0.0, (float)($decoded['price_isk'] ?? 0.0));
  }
}
if ($buybackPrice <= 0) {
  api_send_json(['ok' => false, 'error' => 'Buyback haulage price not configured'], 400);
}

try {
  /** @var \App\Services\PricingService $pricingService */
  $pricingService = $services['pricing'];
  $quote = $pricingService->quote([
    'pickup' => $payload['pickup'] ?? $payload['pickup_system'] ?? null,
    'destination' => $payload['destination'] ?? $payload['destination_system'] ?? null,
    'volume_m3' => $payload['volume_m3'] ?? $payload['volume'] ?? null,
    'collateral_isk' => $payload['collateral_isk'] ?? $payload['collateral'] ?? null,
    'priority' => $payload['priority'] ?? $payload['profile'] ?? null,
  ], $corpId, [
    'corp_id' => $authCtx['corp_id'] ?? null,
    'actor_user_id' => $authCtx['user_id'] ?? null,
    'actor_character_id' => $authCtx['character_id'] ?? null,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  /** @var \App\Services\HaulRequestService $haulRequest */
  $haulRequest = $services['haul_request'];
  $result = $db->tx(fn($db) => $haulRequest->createFromQuote(
    (int)$quote['quote_id'],
    $authCtx,
    $corpId,
    $buybackPrice,
    'Buyback haulage #' . (string)$quote['quote_id']
  ));

  $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
  $basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
  $baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
  $pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
  $requestKey = (string)($result['request_key'] ?? '');
  $requestUrl = '';
  if ($requestKey !== '') {
    $path = ($pathPrefix ?: '') . '/request?request_key=' . urlencode($requestKey);
    $requestUrl = $baseUrl !== '' ? $baseUrl . $path : $path;
  }

  api_send_json([
    'ok' => true,
    'request_id' => $result['request_id'],
    'request_key' => $requestKey,
    'request_url' => $requestUrl,
    'quote_id' => (int)$quote['quote_id'],
    'reward_isk' => $buybackPrice,
    'total_price_isk' => $buybackPrice,
    'price_total_isk' => $buybackPrice,
    'breakdown' => $result['breakdown'],
    'route' => $result['route'],
  ], 201);
} catch (Throwable $e) {
  api_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
