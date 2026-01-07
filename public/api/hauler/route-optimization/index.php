<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

use App\Auth\Auth;

api_require_key();

if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login_required'], 403);
}

if (!Auth::hasRole($authCtx, 'hauler')) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

if ($db === null || empty($services['route_optimization'])) {
  api_send_json(['ok' => false, 'error' => 'service_unavailable'], 503);
}

$corpId = (int)($authCtx['corp_id'] ?? 0);
$userId = (int)$authCtx['user_id'];

/** @var \App\Services\RouteOptimizationService $optimizer */
$optimizer = $services['route_optimization'];

$result = $optimizer->getRouteOptimization($corpId, $userId);
api_send_json($result);
