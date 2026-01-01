<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../../src/bootstrap.php';

api_require_key();

/** @var \App\Services\RouteService $routeService */
$routeService = $services['route'];
$status = $routeService->getGraphStatus();

api_send_json([
  'ok' => true,
  'ready' => (bool)($status['ready'] ?? false),
  'node_count' => (int)($status['node_count'] ?? 0),
  'edge_count' => (int)($status['edge_count'] ?? 0),
  'graph_loaded' => (bool)($status['graph_loaded'] ?? false),
  'graph_source' => $status['graph_source'] ?? null,
  'reason' => $status['reason'] ?? null,
]);
