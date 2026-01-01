<?php
declare(strict_types=1);

/**
 * public/index.php
 *
 * Front controller with a consistent include chain:
 * config → dbfunctions → auth → services → route handler
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Services\EsiService;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
  $path = substr($path, strlen($basePath));
}
if ($basePath !== '' && $path === $basePath) { $path = '/'; }
    $appName = $config['app']['name'];
    $title = $appName . ' • Dashboard';
    require __DIR__ . '/../src/Views/home.php';
    break;

  case '/health':
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($health, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  case '/docs':
    $appName = $config['app']['name'];
    $title = $appName . ' • Docs';
    require __DIR__ . '/../src/Views/docs.php';
    break;

  default:
    http_response_code(404);
    $appName = $config['app']['name'];
    $title = $appName . ' • Not Found';
    $body = '<section class="card"><div class="card-header"><h2>404</h2><p class="muted">Route not found.</p></div><div class="content"><a class="btn ghost" href="/">Back to dashboard</a></div></section>';
    require __DIR__ . '/../src/Views/layout.php';
    break;
}
