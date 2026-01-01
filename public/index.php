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
    $apiKey = (string)($config['security']['api_key'] ?? '');

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
                  WHEN status IN ('requested','awaiting_contract','in_queue','draft','quoted','submitted','posted') THEN 'outstanding'
                  WHEN status IN ('in_progress','accepted','in_transit') THEN 'in_progress'
                  WHEN status IN ('completed','delivered') THEN 'completed'
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
    ];
    $pickupLocationOptions = [];
    $destinationLocationOptions = [];
    $defaultPriority = 'normal';
    $corpIdForProfile = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
    if ($dbOk && $db !== null && $corpIdForProfile > 0) {
      $settingRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.default_profile' LIMIT 1",
        ['cid' => $corpIdForProfile]
      );
      if ($settingRow === null) {
        $settingRow = $db->one(
          "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.default_profile' LIMIT 1"
        );
      }
      if ($settingRow && !empty($settingRow['setting_json'])) {
        $decoded = json_decode((string)$settingRow['setting_json'], true);
        if (is_array($decoded)) {
          $defaultPriority = (string)($decoded['priority'] ?? $decoded['profile'] ?? $defaultPriority);
        } elseif (is_string($decoded)) {
          $defaultPriority = $decoded;
        }
      }
    }
    $defaultPriority = strtolower(trim($defaultPriority)) === 'high' ? 'high' : 'normal';

    if ($dbOk && $db !== null) {
      $accessRules = [
        'systems' => [],
        'regions' => [],
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

      $systemRows = $db->select("SELECT system_id, system_name, region_id FROM map_system ORDER BY system_name");
      if (!$systemRows) {
        $systemRows = $db->select(
          "SELECT s.system_id, s.system_name, c.region_id
             FROM eve_system s
             JOIN eve_constellation c ON c.constellation_id = s.constellation_id
            ORDER BY s.system_name"
        );
      }

      $allowedSystemIds = [];
      foreach ($accessRules['systems'] ?? [] as $rule) {
        if (!empty($rule['allowed'])) {
          $allowedSystemIds[] = (int)($rule['id'] ?? 0);
        }
      }
      $allowedRegionIds = [];
      foreach ($accessRules['regions'] ?? [] as $rule) {
        if (!empty($rule['allowed'])) {
          $allowedRegionIds[] = (int)($rule['id'] ?? 0);
        }
      }
      $allowedSystemIds = array_values(array_filter($allowedSystemIds));
      $allowedRegionIds = array_values(array_filter($allowedRegionIds));
      $hasAccessAllowlist = !empty($allowedSystemIds) || !empty($allowedRegionIds);

      $systemOptions = [];
      foreach ($systemRows as $row) {
        $systemId = (int)($row['system_id'] ?? 0);
        $name = trim((string)($row['system_name'] ?? ''));
        if ($name === '') continue;
        if ($hasAccessAllowlist) {
          $regionId = (int)($row['region_id'] ?? 0);
          $allowed = in_array($systemId, $allowedSystemIds, true)
            || in_array($regionId, $allowedRegionIds, true);
          if (!$allowed) {
            continue;
          }
        }
        $systemOptions[] = ['name' => $name, 'label' => 'System'];
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

  case '/operations':
    $appName = $config['app']['name'];
    $title = $appName . ' • Operations';
    $basePathForViews = $basePath;
    $queueStats = [
      'outstanding' => 0,
      'in_progress' => 0,
      'delivered' => 0,
    ];
    $requests = [];
    $requestsAvailable = false;

    $canViewOps = !empty($authCtx['user_id']) && \App\Auth\Auth::can($authCtx, 'haul.request.read');
    $corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));

    if ($dbOk && $db !== null && $canViewOps && $corpId > 0) {
      $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
      $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

      if ($hasHaulRequest) {
        $statsRow = $db->one(
          "SELECT
              SUM(CASE WHEN status IN ('requested','awaiting_contract','in_queue','draft','quoted','submitted','posted') THEN 1 ELSE 0 END) AS outstanding,
              SUM(CASE WHEN status IN ('in_progress','accepted','in_transit') THEN 1 ELSE 0 END) AS in_progress,
              SUM(CASE WHEN status IN ('completed','delivered') THEN 1 ELSE 0 END) AS delivered
            FROM haul_request
           WHERE corp_id = :cid",
          ['cid' => $corpId]
        );
        if ($statsRow) {
          $queueStats['outstanding'] = (int)($statsRow['outstanding'] ?? 0);
          $queueStats['in_progress'] = (int)($statsRow['in_progress'] ?? 0);
          $queueStats['delivered'] = (int)($statsRow['delivered'] ?? 0);
        }
      }

      if ($hasRequestView) {
        $requests = $db->select(
          "SELECT r.request_id, r.status, r.from_name, r.to_name, r.volume_m3, r.reward_isk,
                  r.created_at, r.requester_display_name, a.hauler_user_id, u.display_name AS hauler_name
             FROM v_haul_request_display r
             LEFT JOIN haul_assignment a ON a.request_id = r.request_id
             LEFT JOIN app_user u ON u.user_id = a.hauler_user_id
            WHERE r.corp_id = :cid
            ORDER BY r.created_at DESC
            LIMIT 25",
          ['cid' => $corpId]
        );
      } elseif ($hasHaulRequest) {
        $requests = $db->select(
          "SELECT r.request_id, r.status, r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk,
                  r.created_at, u.display_name AS requester_display_name, a.hauler_user_id,
                  h.display_name AS hauler_name
             FROM haul_request r
             JOIN app_user u ON u.user_id = r.requester_user_id
             LEFT JOIN haul_assignment a ON a.request_id = r.request_id
             LEFT JOIN app_user h ON h.user_id = a.hauler_user_id
            WHERE r.corp_id = :cid
            ORDER BY r.created_at DESC
            LIMIT 25",
          ['cid' => $corpId]
        );
      }

      if ($requests) {
        $requestsAvailable = true;
      }
    }

    require __DIR__ . '/../src/Views/operations.php';
    break;

  case '/request':
    $appName = $config['app']['name'];
    $title = $appName . ' • Contract Instructions';
    $basePathForViews = $basePath;
    $apiKey = (string)($config['security']['api_key'] ?? '');

    \App\Auth\Auth::requireLogin($authCtx);
    $requestId = (int)($_GET['request_id'] ?? 0);
    $error = null;
    $request = null;
    $routeSummary = '';
    $issuerName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');
    $shipClassLabel = '';
    $shipClassMax = 0.0;
    $contractDescription = '';

    if ($requestId <= 0) {
      $error = 'Request ID is required.';
    } elseif ($db === null || !($health['db'] ?? false)) {
      $error = 'Database unavailable.';
    } else {
      $request = $db->one(
        "SELECT request_id, corp_id, requester_user_id, from_location_id, to_location_id, reward_isk, collateral_isk, volume_m3,
                ship_class, route_policy, price_breakdown_json, quote_id, status
           FROM haul_request
          WHERE request_id = :rid
          LIMIT 1",
        ['rid' => $requestId]
      );

      if (!$request) {
        $error = 'Request not found.';
      } else {
        $corpId = (int)$request['corp_id'];
        $canRead = !empty($authCtx['user_id'])
          && (\App\Auth\Auth::can($authCtx, 'haul.request.read') || (int)$request['requester_user_id'] === (int)$authCtx['user_id']);
        if (!$canRead) {
          http_response_code(403);
          $error = 'You do not have access to this request.';
        } else {
          $quote = null;
          if (!empty($request['quote_id'])) {
            $quote = $db->one(
              "SELECT route_json, breakdown_json FROM quote WHERE quote_id = :qid LIMIT 1",
              ['qid' => (int)$request['quote_id']]
            );
          }
          $route = $quote && !empty($quote['route_json']) ? json_decode((string)$quote['route_json'], true) : [];
          $path = is_array($route['path'] ?? null) ? $route['path'] : [];
          if ($path) {
            $first = $path[0]['system_name'] ?? 'Start';
            $last = $path[count($path) - 1]['system_name'] ?? 'Destination';
            $routeSummary = trim((string)$first) . ' → ' . trim((string)$last);
          } else {
            $routeSummary = 'Route unavailable';
          }

          $breakdown = [];
          if (!empty($request['price_breakdown_json'])) {
            $breakdown = json_decode((string)$request['price_breakdown_json'], true);
          } elseif ($quote && !empty($quote['breakdown_json'])) {
            $breakdown = json_decode((string)$quote['breakdown_json'], true);
          }

          $shipClass = (string)($breakdown['ship_class']['service_class'] ?? ($request['ship_class'] ?? ''));
          $shipClassMax = (float)($breakdown['ship_class']['max_volume'] ?? 0);
          $shipClassLabel = $shipClass !== '' ? $shipClass : 'N/A';

          $dnfNotes = [];
          $softRules = $route['used_soft_dnf_rules'] ?? [];
          if (is_array($softRules)) {
            foreach ($softRules as $rule) {
              if (!empty($rule['reason'])) {
                $dnfNotes[] = (string)$rule['reason'];
              }
            }
          }
          $dnfText = $dnfNotes ? implode('; ', $dnfNotes) : 'None';

          $contractDescription = sprintf(
            "Quote #%s | Priority: %s | DNF: %s | Note: assembled containers/wraps are OK (mention in contract).",
            (string)($request['quote_id'] ?? 'N/A'),
            (string)($request['route_policy'] ?? 'normal'),
            $dnfText
          );
        }
      }
    }

    require __DIR__ . '/../src/Views/request.php';
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
