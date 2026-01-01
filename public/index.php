<?php
declare(strict_types=1);

/**
 * public/index.php
 *
 * Front controller with a consistent include chain:
 * config → db → auth → services → route handler
 *
 * Supports subdirectory deployments via config['app']['base_path'] (e.g. /hauling).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Normalize when hosted under a subdirectory, e.g. /hauling/health
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
if ($basePath !== '' && $path === $basePath) {
  $path = '/';
} elseif ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
  $path = substr($path, strlen($basePath));
  if ($path === '') $path = '/';
}

switch ($path) {
  case '/':
    $env = $config['app']['env'];
    $dbOk = $health['db'] ?? false;
    $esiCacheEnabled = (bool)($config['esi']['cache']['enabled'] ?? true);
    $appName = $config['app']['name'];
    $title = $appName . ' • Dashboard';
    $basePathForViews = $basePath; // pass-through
    require __DIR__ . '/../src/Views/home.php';
    break;

  case '/health':
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($health, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    break;

  case '/docs':
    $appName = $config['app']['name'];
    $title = $appName . ' • Docs';
    $basePathForViews = $basePath;
    require __DIR__ . '/../src/Views/docs.php';
    break;

  default:
    http_response_code(404);
    $appName = $config['app']['name'];
    $title = $appName . ' • Not Found';
    $basePathForViews = $basePath;
    $body = '<section class="card"><div class="card-header"><h2>404</h2><p class="muted">Route not found.</p></div><div class="content"><a class="btn ghost" href="' . htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') . '">Back to dashboard</a></div></section>';
    // layout reads $config and $basePath (see layout.php)
    $basePath = $basePath; // keep variable name for layout
    require __DIR__ . '/../src/Views/layout.php';
    break;
}
