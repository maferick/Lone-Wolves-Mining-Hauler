<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Services\RouteException;

api_require_key();

$payload = api_read_json();

$pickup = trim((string)($payload['pickup'] ?? $payload['pickup_system'] ?? ''));
$destination = trim((string)($payload['destination'] ?? $payload['destination_system'] ?? ''));
$profile = strtolower(trim((string)($payload['profile'] ?? 'balanced')));
if (in_array($profile, ['normal', 'high'], true)) {
  $profile = 'balanced';
}

/** @var \App\Services\RouteService $routeService */
$routeService = $services['route'];
$graphStatus = $routeService->getGraphStatus();

$resolved = [
  'pickup' => null,
  'destination' => null,
];
$resolutionErrors = [];

try {
  if ($pickup !== '') {
    $resolved['pickup'] = $routeService->resolveSystemIdByName($pickup);
  }
} catch (RouteException $e) {
  $resolutionErrors['pickup'] = $e->getDetails();
}

try {
  if ($destination !== '') {
    $resolved['destination'] = $routeService->resolveSystemIdByName($destination);
  }
} catch (RouteException $e) {
  $resolutionErrors['destination'] = $e->getDetails();
}

$pickupDegree = $resolved['pickup'] ? $routeService->getNodeDegree((int)$resolved['pickup']) : 0;
$destinationDegree = $resolved['destination'] ? $routeService->getNodeDegree((int)$resolved['destination']) : 0;

$routeInfo = null;
$routeFailure = null;

if ($resolved['pickup'] && $resolved['destination'] && !empty($graphStatus['ready'])) {
  try {
    $route = $routeService->findRoute($pickup, $destination, $profile);
    $path = $route['path'] ?? [];
    $routeInfo = [
      'jumps' => (int)($route['jumps'] ?? 0),
      'hs_count' => (int)($route['hs_count'] ?? 0),
      'ls_count' => (int)($route['ls_count'] ?? 0),
      'ns_count' => (int)($route['ns_count'] ?? 0),
      'first_systems' => array_map(
        fn($node) => (string)($node['system_name'] ?? ''),
        array_slice($path, 0, 10)
      ),
      'blocked_count_hard' => (int)($route['blocked_count_hard'] ?? 0),
      'blocked_count_soft' => (int)($route['blocked_count_soft'] ?? 0),
      'used_soft_dnf' => (bool)($route['used_soft_dnf'] ?? false),
    ];
  } catch (RouteException $e) {
    $details = $e->getDetails();
    $routeFailure = [
      'reason' => $details['reason'] ?? 'no_viable_route',
      'message' => $e->getMessage(),
      'blocked_count_hard' => $details['blocked_count_hard'] ?? null,
      'blocked_count_soft' => $details['blocked_count_soft'] ?? null,
      'blocked_by_dnf' => in_array(($details['reason'] ?? ''), ['blocked_by_dnf', 'pickup_destination_blocked'], true),
    ];
  }
} elseif (empty($graphStatus['graph_loaded'])) {
  $routeFailure = [
    'reason' => 'graph_empty',
    'message' => 'Graph tables are empty.',
  ];
}

api_send_json([
  'ok' => $routeInfo !== null,
  'graph' => [
    'ready' => (bool)($graphStatus['ready'] ?? false),
    'node_count' => (int)($graphStatus['node_count'] ?? 0),
    'edge_count' => (int)($graphStatus['edge_count'] ?? 0),
    'graph_source' => $graphStatus['graph_source'] ?? null,
    'reason' => $graphStatus['reason'] ?? null,
  ],
  'resolved_ids' => $resolved,
  'nodes' => [
    'pickup' => [
      'exists' => $resolved['pickup'] !== null,
      'degree' => $pickupDegree,
    ],
    'destination' => [
      'exists' => $resolved['destination'] !== null,
      'degree' => $destinationDegree,
    ],
  ],
  'route' => $routeInfo,
  'failure' => $routeFailure,
  'resolution_errors' => $resolutionErrors,
]);
