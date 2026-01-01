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

// Serve static assets when the front controller is used as the router.
if (str_starts_with($path, '/assets/')) {
  $publicRoot = realpath(__DIR__);
  $candidate = $publicRoot ? realpath($publicRoot . $path) : false;
  $assetsRoot = $publicRoot ? $publicRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR : null;
  if ($candidate && $assetsRoot && str_starts_with($candidate, $assetsRoot) && is_file($candidate)) {
    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $contentTypes = [
      'css' => 'text/css; charset=utf-8',
      'js' => 'application/javascript; charset=utf-8',
      'png' => 'image/png',
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'svg' => 'image/svg+xml',
      'webp' => 'image/webp',
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
    ];
    header('Content-Type: ' . ($contentTypes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($candidate));
    readfile($candidate);
    exit;
  }
}

switch ($path) {
  case '/':
    $env = $config['app']['env'];
    $dbOk = $health['db'] ?? false;
    $esiCacheEnabled = (bool)($config['esi']['cache']['enabled'] ?? true);
    $appName = $config['app']['name'];
    $title = $appName . ' • Dashboard';
    $basePathForViews = $basePath; // pass-through
    $queueStats = [
      'outstanding' => 0,
      'in_progress' => 0,
      'completed' => 0,
    ];

    if ($dbOk && $db !== null) {
      $hasHaulingJob = (bool)$db->fetchValue("SHOW TABLES LIKE 'hauling_job'");
      $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");

      if ($hasHaulingJob) {
        $queueStats['outstanding'] = (int)$db->fetchValue("SELECT COUNT(*) FROM hauling_job WHERE status = 'outstanding'");
        $queueStats['in_progress'] = (int)$db->fetchValue("SELECT COUNT(*) FROM hauling_job WHERE status = 'in_progress'");
        $queueStats['completed'] = (int)$db->fetchValue("SELECT COUNT(*) FROM hauling_job WHERE status = 'completed' AND completed_at >= (NOW() - INTERVAL 1 DAY)");
      } elseif ($hasHaulRequest) {
        $row = $db->one(
          "SELECT
              SUM(CASE WHEN derived.status = 'outstanding' THEN 1 ELSE 0 END) AS outstanding,
              SUM(CASE WHEN derived.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
              SUM(CASE WHEN derived.status = 'completed' AND derived.completed_at >= (NOW() - INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS completed
            FROM (
              SELECT
                CASE
                  WHEN status IN ('draft','quoted','submitted','posted') THEN 'outstanding'
                  WHEN status IN ('accepted','in_transit') THEN 'in_progress'
                  WHEN status = 'delivered' THEN 'completed'
                  ELSE 'other'
                END AS status,
                delivered_at AS completed_at
              FROM haul_request
            ) AS derived"
        );
        if ($row) {
          $queueStats['outstanding'] = (int)($row['outstanding'] ?? 0);
          $queueStats['in_progress'] = (int)($row['in_progress'] ?? 0);
          $queueStats['completed'] = (int)($row['completed'] ?? 0);
        }
      }
    }

    $quoteInput = [
      'pickup_system' => '',
      'destination_system' => '',
      'volume' => '',
      'collateral' => '',
    ];
    $quoteResult = null;
    $quoteErrors = [];

    $parseCollateral = static function (string $value): ?float {
      $clean = strtolower(trim($value));
      if ($clean === '') {
        return null;
      }
      $clean = str_replace([',', ' '], '', $clean);
      if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)([kmb])?$/', $clean, $matches)) {
        return null;
      }
      $amount = (float)$matches[1];
      $suffix = $matches[2] ?? '';
      $multiplier = 1;
      if ($suffix === 'k') {
        $multiplier = 1000;
      } elseif ($suffix === 'm') {
        $multiplier = 1000000;
      } elseif ($suffix === 'b') {
        $multiplier = 1000000000;
      }
      return $amount * $multiplier;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
      $quoteInput['pickup_system'] = trim((string)($_POST['pickup_system'] ?? ''));
      $quoteInput['destination_system'] = trim((string)($_POST['destination_system'] ?? ''));
      $quoteInput['volume'] = (string)($_POST['volume'] ?? '');
      $quoteInput['collateral'] = trim((string)($_POST['collateral'] ?? ''));

      $allowedVolumes = ['12500', '62500', '360000', '950000'];
      if ($quoteInput['pickup_system'] === '') {
        $quoteErrors[] = 'Pickup system is required.';
      }
      if ($quoteInput['destination_system'] === '') {
        $quoteErrors[] = 'Destination system is required.';
      }
      if (!in_array($quoteInput['volume'], $allowedVolumes, true)) {
        $quoteErrors[] = 'Please select a valid volume.';
      }

      $parsedCollateral = $parseCollateral($quoteInput['collateral']);
      if ($parsedCollateral === null || $parsedCollateral <= 0) {
        $quoteErrors[] = 'Collateral must be a valid ISK amount (e.g. 300m, 2.65b, 400,000,000).';
      }

      if (!$quoteErrors) {
        $quoteResult = [
          'volume' => (int)$quoteInput['volume'],
          'collateral' => $parsedCollateral,
          'quote' => 'TBD',
        ];
      }
    }

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

  case '/rates':
    $appName = $config['app']['name'];
    $title = $appName . ' • Rates';
    $basePathForViews = $basePath;
    $body = '<section class="card"><div class="card-header"><h2>Rates</h2><p class="muted">Coming soon.</p></div><div class="content"><a class="btn ghost" href="' . htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') . '">Back to dashboard</a></div></section>';
    $basePath = $basePath;
    require __DIR__ . '/../src/Views/layout.php';
    break;

  case '/faq':
    $appName = $config['app']['name'];
    $title = $appName . ' • FAQ';
    $basePathForViews = $basePath;
    $body = '<section class="card"><div class="card-header"><h2>FAQ</h2><p class="muted">Coming soon.</p></div><div class="content"><a class="btn ghost" href="' . htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') . '">Back to dashboard</a></div></section>';
    $basePath = $basePath;
    require __DIR__ . '/../src/Views/layout.php';
    break;

  case '/api/ping':
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => true,
      'service' => 'hauling',
      'time_utc' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
