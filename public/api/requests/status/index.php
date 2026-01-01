<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

api_require_key();

$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login required'], 403);
}

$requestId = (int)($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
  api_send_json(['ok' => false, 'error' => 'request_id required'], 400);
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

$request = $db->one(
  "SELECT request_id, corp_id, requester_user_id, status, contract_id, reward_isk, collateral_isk, volume_m3, ship_class,
          from_location_id, to_location_id, route_policy, expected_jumps, price_breakdown_json, quote_id
     FROM haul_request
    WHERE request_id = :rid AND corp_id = :cid
    LIMIT 1",
  ['rid' => $requestId, 'cid' => $corpId]
);

if (!$request) {
  api_send_json(['ok' => false, 'error' => 'request not found'], 404);
}

$canRead = Auth::can($authCtx, 'haul.request.read') || (int)$request['requester_user_id'] === (int)$authCtx['user_id'];
if (!$canRead) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$breakdown = [];
if (!empty($request['price_breakdown_json'])) {
  $breakdown = Db::jsonDecode((string)$request['price_breakdown_json'], []);
}

api_send_json([
  'ok' => true,
  'request' => $request,
  'breakdown' => $breakdown,
]);
