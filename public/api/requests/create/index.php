<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;

api_require_key();

$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login required'], 403);
}
if (!Auth::can($authCtx, 'haul.request.create')) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$payload = api_read_json();
$quoteId = (int)($payload['quote_id'] ?? 0);
if ($quoteId <= 0) {
  api_send_json(['ok' => false, 'error' => 'quote_id required'], 400);
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

try {
  /** @var \App\Services\HaulRequestService $haulRequest */
  $haulRequest = $services['haul_request'];
  $result = $db->tx(fn($db) => $haulRequest->createFromQuote($quoteId, $authCtx, $corpId));

  $baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
  $basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
  $baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
  $pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
  $path = ($pathPrefix ?: '') . '/request?request_id=' . (string)$result['request_id'];
  $requestUrl = $baseUrl !== '' ? $baseUrl . $path : $path;

  api_send_json([
    'ok' => true,
    'request_id' => $result['request_id'],
    'request_url' => $requestUrl,
    'quote' => $result['quote'],
    'breakdown' => $result['breakdown'],
    'route' => $result['route'],
  ], 201);
} catch (Throwable $e) {
  api_send_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
