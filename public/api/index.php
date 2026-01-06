<?php
declare(strict_types=1);

/**
 * public/api/index.php
 *
 * Physical API entrypoint so /hauling/api/* never depends on rewrite rules.
 * All automation (cron, Discord, ESI pulls) should go through this layer.
 *
 * Routes are resolved on PATH_INFO:
 *   /hauling/api/health        -> health
 *   /hauling/api/contracts/sync
 */

require_once __DIR__ . '/bootstrap.php';

$path = $_SERVER['PATH_INFO'] ?? '/';
$path = '/' . trim($path, '/');

header('Content-Type: application/json; charset=utf-8');

switch ($path) {

  case '/':
  case '/health':
    echo json_encode([
      'ok' => true,
      'service' => 'hauling-api',
      'time_utc' => gmdate('c'),
      'db' => $health['db'] ?? false,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  case '/contracts/sync':
    // Example: trigger corp contract pull
    // Protect later via auth token / IP allowlist
    $corpId = isset($_GET['corp_id']) ? (int)$_GET['corp_id'] : 0;
    $charId = isset($_GET['character_id']) ? (int)$_GET['character_id'] : 0;

    if ($corpId <= 0 || $charId <= 0) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'corp_id and character_id required']);
      break;
    }

    try {
      /** @var \App\Services\EsiService $esi */
      $esi = $services['esi'];
      $result = $db->tx(fn($db) => $esi->contracts()->pull($corpId, $charId));
      $reconcile = $esi->contractReconcile()->reconcile($corpId);
      echo json_encode(['ok' => true, 'result' => $result, 'reconcile' => $reconcile], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    break;

  default:
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'API endpoint not found']);
    break;
}
