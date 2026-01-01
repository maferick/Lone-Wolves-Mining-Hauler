<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

api_require_key();

if ($db === null || !isset($services['route'])) {
  api_send_json([
    'ok' => false,
    'error' => 'database_unavailable',
  ], 503);
}

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$profile = (string)($_GET['profile'] ?? '');

if ($from === '' || $to === '') {
  api_send_json(['ok' => false, 'error' => 'from and to are required'], 400);
}

$profile = strtolower(trim($profile));
if (!in_array($profile, ['balanced', 'normal', 'high'], true)) {
  $profile = 'balanced';
}

try {
  /** @var \App\Services\RouteService $routeService */
  $routeService = $services['route'];
  $route = $routeService->findRoute($from, $to, $profile, [
    'corp_id' => $authCtx['corp_id'] ?? null,
    'actor_user_id' => $authCtx['user_id'] ?? null,
    'actor_character_id' => $authCtx['character_id'] ?? null,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  api_send_json([
    'ok' => true,
    'route' => $route,
  ]);
} catch (Throwable $e) {
  api_send_json([
    'ok' => false,
    'error' => $e->getMessage(),
  ], 400);
}
