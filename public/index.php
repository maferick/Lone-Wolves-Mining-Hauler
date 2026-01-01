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
    $contractStats = [
      'total' => 0,
      'outstanding' => 0,
      'in_progress' => 0,
      'completed' => 0,
      'en_route_volume' => 0,
      'pending_volume' => 0,
      'last_fetched_at' => null,
    ];
    $contractStatsAvailable = false;

    if ($dbOk && $db !== null) {
      $hasHaulingJob = (bool)$db->fetchValue("SHOW TABLES LIKE 'hauling_job'");
      $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
      $hasContracts = (bool)$db->fetchValue("SHOW TABLES LIKE 'esi_corp_contract'");

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

      $contractCorpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
      if ($hasContracts && $contractCorpId > 0) {
        $contractRow = $db->one(
          "SELECT
              COUNT(*) AS total,
              SUM(CASE WHEN status = 'outstanding' THEN 1 ELSE 0 END) AS outstanding,
              SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
              SUM(CASE WHEN status IN ('finished','completed') THEN 1 ELSE 0 END) AS completed,
              SUM(CASE WHEN status = 'in_progress' THEN COALESCE(volume_m3, 0) ELSE 0 END) AS en_route_volume,
              SUM(CASE WHEN status = 'outstanding' THEN COALESCE(volume_m3, 0) ELSE 0 END) AS pending_volume,
              MAX(last_fetched_at) AS last_fetched_at
            FROM esi_corp_contract
           WHERE corp_id = :cid
             AND type = 'courier'",
          ['cid' => $contractCorpId]
        );
        if ($contractRow) {
          $contractStats = [
            'total' => (int)($contractRow['total'] ?? 0),
            'outstanding' => (int)($contractRow['outstanding'] ?? 0),
            'in_progress' => (int)($contractRow['in_progress'] ?? 0),
            'completed' => (int)($contractRow['completed'] ?? 0),
            'en_route_volume' => (float)($contractRow['en_route_volume'] ?? 0),
            'pending_volume' => (float)($contractRow['pending_volume'] ?? 0),
            'last_fetched_at' => $contractRow['last_fetched_at'] ?? null,
          ];
          $contractStatsAvailable = true;
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
    $pickupLocationOptions = [];
    $destinationLocationOptions = [];

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

    if ($dbOk && $db !== null) {
      $systemRows = $db->select("SELECT system_name FROM eve_system ORDER BY system_name");
      $systemOptions = [];
      foreach ($systemRows as $row) {
        $name = trim((string)($row['system_name'] ?? ''));
        if ($name === '') continue;
        $systemOptions[] = ['name' => $name, 'label' => 'System'];
      }

      $accessRules = [
        'structures' => [],
      ];
      $corpIdForAccess = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
      if ($corpIdForAccess > 0) {
        $accessRow = $db->one(
          "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'access.rules' LIMIT 1",
          ['cid' => $corpIdForAccess]
        );
        if ($accessRow && !empty($accessRow['setting_json'])) {
          $decoded = json_decode((string)$accessRow['setting_json'], true);
          if (is_array($decoded)) {
            $accessRules = array_replace_recursive($accessRules, $decoded);
          }
        }
      }

      $structureRules = $accessRules['structures'] ?? [];
      $structureIds = [];
      foreach ($structureRules as $rule) {
        $id = (int)($rule['id'] ?? 0);
        if ($id > 0) $structureIds[] = $id;
      }
      $structureNameById = [];
      if ($structureIds) {
        $placeholders = implode(',', array_fill(0, count($structureIds), '?'));
        $structureRows = $db->select(
          "SELECT structure_id, structure_name FROM eve_structure WHERE structure_id IN ($placeholders)",
          $structureIds
        );
        foreach ($structureRows as $row) {
          $structureNameById[(int)$row['structure_id']] = (string)$row['structure_name'];
        }
      }

      $pickupStructures = [];
      $deliveryStructures = [];
      foreach ($structureRules as $rule) {
        if (empty($rule['allowed'])) continue;
        $name = trim((string)($rule['name'] ?? ''));
        $id = (int)($rule['id'] ?? 0);
        if ($id > 0 && !empty($structureNameById[$id])) {
          $name = (string)$structureNameById[$id];
        }
        if ($name === '') continue;
        if (!empty($rule['pickup_allowed'])) {
          $pickupStructures[] = ['name' => $name, 'label' => 'Structure'];
        }
        if (!empty($rule['delivery_allowed'])) {
          $deliveryStructures[] = ['name' => $name, 'label' => 'Structure'];
        }
      }

      $pickupLocationOptions = array_merge($systemOptions, $pickupStructures);
      $destinationLocationOptions = array_merge($systemOptions, $deliveryStructures);
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
