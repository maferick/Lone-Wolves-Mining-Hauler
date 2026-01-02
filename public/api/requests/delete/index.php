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

$payload = api_read_json();
$requestId = (int)($payload['request_id'] ?? 0);
if ($requestId <= 0) {
  api_send_json(['ok' => false, 'error' => 'request_id required'], 400);
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp context missing'], 400);
}

$request = $db->one(
  "SELECT request_id, requester_user_id, status, contract_id
     FROM haul_request
    WHERE request_id = :rid AND corp_id = :cid
    LIMIT 1",
  ['rid' => $requestId, 'cid' => $corpId]
);

if (!$request) {
  api_send_json(['ok' => false, 'error' => 'request not found'], 404);
}

$canManage = Auth::can($authCtx, 'haul.request.manage');
$isOwner = (int)$request['requester_user_id'] === (int)$authCtx['user_id'];
if (!$canManage && !$isOwner) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$db->tx(function ($db) use ($request, $corpId, $authCtx): void {
  $db->execute(
    "DELETE FROM haul_request WHERE request_id = :rid AND corp_id = :cid LIMIT 1",
    ['rid' => (int)$request['request_id'], 'cid' => $corpId]
  );

  $db->audit(
    $corpId,
    (int)$authCtx['user_id'],
    (int)($authCtx['character_id'] ?? 0),
    'haul.request.delete',
    'haul_request',
    (string)$request['request_id'],
    $request,
    null,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $_SERVER['HTTP_USER_AGENT'] ?? null
  );
});

api_send_json(['ok' => true, 'request_id' => $requestId]);
