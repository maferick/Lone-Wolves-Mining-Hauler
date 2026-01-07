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

use App\Db\Db;
use App\Services\BuybackHaulageService;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Normalize when hosted under a subdirectory, e.g. /hauling/health
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
if ($basePath !== '' && $path === $basePath) {
  $path = '/';
} elseif ($basePath !== '') {
  while (str_starts_with($path, $basePath . '/')) {
    $path = substr($path, strlen($basePath));
    if ($path === '') {
      $path = '/';
      break;
    }
  }
}

if ($basePath === '') {
  $knownRoutes = [
    '/operations',
    '/my-contracts',
    '/request',
    '/docs',
    '/privacy',
    '/docs/terms',
    '/terms',
    '/rates',
    '/faq',
    '/quote',
    '/hall-of-fame',
    '/health',
    '/login',
    '/logout',
    '/rights',
    '/wiki',
    '/api/ping',
  ];

  foreach ($knownRoutes as $route) {
    if (str_ends_with($path, $route) && $path !== $route) {
      $candidateBase = rtrim(substr($path, 0, -strlen($route)), '/');
      if ($candidateBase !== '' && $candidateBase !== '.') {
        $basePath = $candidateBase;
        $config['app']['base_path'] = $basePath;
        $path = $route;
        break;
      }
    }
  }

  if ($basePath === '' && $path !== '/') {
    $segments = array_values(array_filter(explode('/', $path)));
    if (count($segments) === 1 && !in_array('/' . $segments[0], $knownRoutes, true)) {
      $basePath = '/' . $segments[0];
      $config['app']['base_path'] = $basePath;
      $path = '/';
    }
  }
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

$dbOk = $health['db'] ?? false;

if ($path === '/wiki' || $path === '/wiki/') {
  require __DIR__ . '/wiki/index.php';
  exit;
}
if (preg_match('#^/wiki/([A-Za-z0-9_-]+)(/)?$#', $path, $matches)) {
  $_GET['slug'] = $matches[1];
  require __DIR__ . '/wiki/index.php';
  exit;
}
if (str_starts_with($path, '/wiki')) {
  http_response_code(404);
  $appName = $config['app']['name'] ?? 'Corp Hauling';
  $title = $appName . ' • Wiki';
  $errorTitle = 'Not found';
  $errorMessage = 'The requested wiki page could not be found.';
  require __DIR__ . '/../src/Views/error.php';
  exit;
}

switch ($path) {
  case '/':
    $env = $config['app']['env'];
    $esiCacheEnabled = (bool)($config['esi']['cache']['enabled'] ?? true);
    $appName = $config['app']['name'];
    $title = $appName . ' • Dashboard';
    $basePathForViews = $basePath; // pass-through
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
    $queueStats = [
      'outstanding' => 0,
    ];
    $pendingVolume = 0.0;

    if ($dbOk && $db !== null) {
      $hasHaulingJob = (bool)$db->fetchValue("SHOW TABLES LIKE 'hauling_job'");
      $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
      $hasContracts = (bool)$db->fetchValue("SHOW TABLES LIKE 'esi_corp_contract'");

      if ($hasHaulRequest) {
        $corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
        if ($corpId > 0) {
          $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_lifecycle'");
          $hasContractState = $hasContractLifecycle ? false : (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
          $contractLifecycleColumn = null;
          if ($hasContractLifecycle) {
            $contractLifecycleColumn = 'contract_lifecycle';
          } elseif ($hasContractState) {
            $contractLifecycleColumn = 'contract_state';
          }
          $lifecycleExpr = $contractLifecycleColumn
            ? "UPPER({$contractLifecycleColumn})"
            : "NULL";
          $statsRow = $db->one(
            "SELECT
                SUM(CASE
                      WHEN {$lifecycleExpr} IN ('PICKED_UP','IN_TRANSIT') THEN 0
                      WHEN {$lifecycleExpr} IN ('DELIVERED') THEN 0
                      WHEN {$lifecycleExpr} IN ('FAILED','EXPIRED') THEN 0
                      WHEN status IN ('requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','draft','quoted','submitted','posted') THEN 1
                      ELSE 0
                    END) AS outstanding
              FROM haul_request
             WHERE corp_id = :cid",
            ['cid' => $corpId]
          );
          if ($statsRow) {
            $queueStats['outstanding'] = (int)($statsRow['outstanding'] ?? 0);
          }
          $pendingRow = $db->one(
            "SELECT
                SUM(CASE
                      WHEN {$lifecycleExpr} IN ('PICKED_UP','IN_TRANSIT') THEN 0
                      WHEN {$lifecycleExpr} IN ('DELIVERED') THEN 0
                      WHEN {$lifecycleExpr} IN ('FAILED','EXPIRED') THEN 0
                      WHEN status IN ('requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','draft','quoted','submitted','posted')
                        THEN COALESCE(volume_m3, 0)
                      ELSE 0
                    END) AS pending_volume
              FROM haul_request
             WHERE corp_id = :cid",
            ['cid' => $corpId]
          );
          if ($pendingRow) {
            $pendingVolume = (float)($pendingRow['pending_volume'] ?? 0);
          }
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
    $contractStats['pending_volume'] = $pendingVolume;

    $quoteInput = [
      'pickup_location' => '',
      'delivery_location' => '',
    ];
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

    $buybackHaulageTiers = BuybackHaulageService::defaultTiers();
    $buybackHaulageEnabled = false;
    $corpIdForBuyback = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
    if ($dbOk && $db !== null && $corpIdForBuyback > 0) {
      $settingRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'buyback.haulage' LIMIT 1",
        ['cid' => $corpIdForBuyback]
      );
      if ($settingRow && !empty($settingRow['setting_json'])) {
        $decoded = json_decode((string)$settingRow['setting_json'], true);
        if (is_array($decoded)) {
          $buybackHaulageTiers = BuybackHaulageService::normalizeSetting($decoded);
          $buybackHaulageEnabled = BuybackHaulageService::hasEnabledTier($buybackHaulageTiers);
        }
      }
    }

    require __DIR__ . '/../src/Views/home.php';
    break;

  case '/quote':
    $appName = $config['app']['name'];
    $title = $appName . ' • Quote';
    $basePathForViews = $basePath;
    $quoteInput = [
      'pickup_location' => '',
      'delivery_location' => '',
    ];
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

    $buybackHaulageTiers = BuybackHaulageService::defaultTiers();
    $buybackHaulageEnabled = false;
    $corpIdForBuyback = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
    if ($dbOk && $db !== null && $corpIdForBuyback > 0) {
      $settingRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'buyback.haulage' LIMIT 1",
        ['cid' => $corpIdForBuyback]
      );
      if ($settingRow && !empty($settingRow['setting_json'])) {
        $decoded = json_decode((string)$settingRow['setting_json'], true);
        if (is_array($decoded)) {
          $buybackHaulageTiers = BuybackHaulageService::normalizeSetting($decoded);
          $buybackHaulageEnabled = BuybackHaulageService::hasEnabledTier($buybackHaulageTiers);
        }
      }
    }

    require __DIR__ . '/../src/Views/quote.php';
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

  case '/privacy':
    $appName = $config['app']['name'];
    $title = $appName . ' • Privacy';
    $basePathForViews = $basePath;
    require __DIR__ . '/../src/Views/privacy.php';
    break;
  case '/docs/terms':
  case '/terms':
    $appName = $config['app']['name'];
    $title = $appName . ' • Terms of Service';
    $basePathForViews = $basePath;
    $docTitle = 'Terms of Service';
    $docDescription = 'Discord integration terms for internal corp use.';
    $docPath = __DIR__ . '/../docs/TERMS.md';
    require __DIR__ . '/../src/Views/markdown-doc.php';
    break;

  case '/operations':
    $appName = $config['app']['name'];
    $title = $appName . ' • Operations';
    $basePathForViews = $basePath;
    $queueStats = [
      'outstanding' => 0,
      'in_progress' => 0,
      'delivered' => 0,
      'failed' => 0,
    ];
    $requests = [];
    $requestsAvailable = false;
    $showDispatchSections = true;

    $canViewOps = !empty($authCtx['user_id']) && \App\Auth\Auth::can($authCtx, 'haul.request.read');
    $corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));

    if ($dbOk && $db !== null && $corpId > 0) {
      $settingRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'operations.dispatch_sections' LIMIT 1",
        ['cid' => $corpId]
      );
      if ($settingRow && !empty($settingRow['setting_json'])) {
        $decoded = Db::jsonDecode((string)$settingRow['setting_json'], []);
        if (is_array($decoded) && array_key_exists('show_dispatch', $decoded)) {
          $showDispatchSections = (bool)$decoded['show_dispatch'];
        }
      }
    }

    if ($dbOk && $db !== null && $canViewOps && $corpId > 0) {
      $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
      $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

      if ($hasHaulRequest) {
        $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_lifecycle'");
        $hasContractState = $hasContractLifecycle ? false : (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
        $contractLifecycleColumn = null;
        if ($hasContractLifecycle) {
          $contractLifecycleColumn = 'contract_lifecycle';
        } elseif ($hasContractState) {
          $contractLifecycleColumn = 'contract_state';
        }
        $lifecycleExpr = $contractLifecycleColumn
          ? "UPPER({$contractLifecycleColumn})"
          : "NULL";
        $statsRow = $db->one(
          "SELECT
              SUM(CASE
                    WHEN {$lifecycleExpr} IN ('PICKED_UP','IN_TRANSIT') THEN 0
                    WHEN {$lifecycleExpr} IN ('DELIVERED') THEN 0
                    WHEN {$lifecycleExpr} IN ('FAILED','EXPIRED') THEN 0
                    WHEN status IN ('requested','awaiting_contract','contract_linked','contract_mismatch','in_queue','draft','quoted','submitted','posted') THEN 1
                    ELSE 0
                  END) AS outstanding,
              SUM(CASE
                    WHEN {$lifecycleExpr} IN ('PICKED_UP','IN_TRANSIT') THEN 1
                    WHEN {$lifecycleExpr} IN ('DELIVERED') THEN 0
                    WHEN {$lifecycleExpr} IN ('FAILED','EXPIRED') THEN 0
                    WHEN status IN ('in_progress','accepted','in_transit') THEN 1
                    ELSE 0
                  END) AS in_progress,
              SUM(CASE
                    WHEN {$lifecycleExpr} IN ('DELIVERED') THEN 1
                    WHEN status IN ('completed','delivered') THEN 1
                    ELSE 0
                  END) AS delivered,
              SUM(CASE
                    WHEN {$lifecycleExpr} IN ('FAILED','EXPIRED') THEN 1
                    WHEN status IN ('cancelled','expired','rejected','failed') THEN 1
                    ELSE 0
                  END) AS failed
            FROM haul_request
           WHERE corp_id = :cid",
          ['cid' => $corpId]
        );
        if ($statsRow) {
          $queueStats['outstanding'] = (int)($statsRow['outstanding'] ?? 0);
          $queueStats['in_progress'] = (int)($statsRow['in_progress'] ?? 0);
          $queueStats['delivered'] = (int)($statsRow['delivered'] ?? 0);
          $queueStats['failed'] = (int)($statsRow['failed'] ?? 0);
        }
      }

      if ($hasRequestView) {
        $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_lifecycle'");
        $hasContractState = $hasContractLifecycle ? false : (bool)$db->fetchValue("SHOW COLUMNS FROM v_haul_request_display LIKE 'contract_state'");
        $contractLifecycleColumn = null;
        if ($hasContractLifecycle) {
          $contractLifecycleColumn = 'r.contract_lifecycle';
        } elseif ($hasContractState) {
          $contractLifecycleColumn = 'r.contract_state';
        }
        $contractLifecycleFilter = $contractLifecycleColumn
          ? " AND ({$contractLifecycleColumn} IS NULL OR {$contractLifecycleColumn} NOT IN ('FAILED','EXPIRED','DELIVERED'))"
          : " AND r.status NOT IN ('completed','delivered','cancelled','expired','rejected','failed')";
        $requests = $db->select(
          "SELECT r.request_id, r.status, r.contract_id, r.esi_contract_id, r.contract_status, r.contract_status_esi, r.esi_status,
                  r.contract_lifecycle, r.contract_state, r.esi_acceptor_id, r.esi_acceptor_name, r.ops_assignee_id, r.ops_assignee_name,
                  r.mismatch_reason_json,
                  COALESCE(fs.system_name, r.from_name) AS from_name,
                  COALESCE(ts.system_name, r.to_name) AS to_name,
                  r.volume_m3, r.reward_isk, r.created_at, r.requester_display_name,
                  a.hauler_user_id, u.display_name AS hauler_name
             FROM v_haul_request_display r
             LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
             LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
             LEFT JOIN haul_assignment a ON a.request_id = r.request_id
             LEFT JOIN app_user u ON u.user_id = a.hauler_user_id
            WHERE r.corp_id = :cid{$contractLifecycleFilter}
            ORDER BY r.created_at DESC
            LIMIT 25",
          ['cid' => $corpId]
        );
      } elseif ($hasHaulRequest) {
        $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_lifecycle'");
        $hasContractState = $hasContractLifecycle ? false : (bool)$db->fetchValue("SHOW COLUMNS FROM haul_request LIKE 'contract_state'");
        $contractLifecycleColumn = null;
        if ($hasContractLifecycle) {
          $contractLifecycleColumn = 'r.contract_lifecycle';
        } elseif ($hasContractState) {
          $contractLifecycleColumn = 'r.contract_state';
        }
        $contractLifecycleFilter = $contractLifecycleColumn
          ? " AND ({$contractLifecycleColumn} IS NULL OR {$contractLifecycleColumn} NOT IN ('FAILED','EXPIRED','DELIVERED'))"
          : " AND r.status NOT IN ('completed','delivered','cancelled','expired','rejected','failed')";
        $requests = $db->select(
          "SELECT r.request_id, r.status, r.contract_id, r.esi_contract_id, r.contract_status, r.contract_status_esi, r.esi_status,
                  r.contract_lifecycle, r.contract_state, r.esi_acceptor_id, r.esi_acceptor_name, r.ops_assignee_id, r.ops_assignee_name,
                  r.mismatch_reason_json,
                  r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk, r.created_at,
                  u.display_name AS requester_display_name, a.hauler_user_id,
                  h.display_name AS hauler_name, fs.system_name AS from_name, ts.system_name AS to_name
             FROM haul_request r
             JOIN app_user u ON u.user_id = r.requester_user_id
             LEFT JOIN haul_assignment a ON a.request_id = r.request_id
             LEFT JOIN app_user h ON h.user_id = a.hauler_user_id
             LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
             LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
            WHERE r.corp_id = :cid{$contractLifecycleFilter}
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

  case '/hall-of-fame':
    $appName = $config['app']['name'];
    $title = $appName . ' • Hall of Fame';
    $basePathForViews = $basePath;

    \App\Auth\Auth::requireLogin($authCtx);
    \App\Auth\Auth::requirePerm($authCtx, 'haul.request.read');

    $corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
    $hallOfFameRows = [];
    $hallOfShameRows = [];
    $completedTotals = ['count' => 0, 'volume_m3' => 0.0, 'collateral_isk' => 0.0];
    $failedTotals = ['count' => 0, 'volume_m3' => 0.0, 'collateral_isk' => 0.0];

    if ($dbOk && $db !== null && $corpId > 0) {
      $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");
      $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
      $hasCorpContracts = (bool)$db->fetchValue("SHOW TABLES LIKE 'esi_corp_contract'");
      $table = null;
      $nameExpr = null;

      if ($hasRequestView) {
        $table = 'v_haul_request_display';
        $nameExpr = "COALESCE(NULLIF(TRIM(r.esi_acceptor_display_name), ''), NULLIF(TRIM(r.esi_acceptor_name), ''), NULLIF(TRIM(r.ops_assignee_name), ''), 'Unassigned')";
      } elseif ($hasHaulRequest) {
        $table = 'haul_request';
        $nameExpr = "COALESCE(NULLIF(TRIM(r.esi_acceptor_name), ''), NULLIF(TRIM(r.ops_assignee_name), ''), 'Unassigned')";
      }

      if ($table && $nameExpr) {
        $hasContractLifecycle = (bool)$db->fetchValue("SHOW COLUMNS FROM {$table} LIKE 'contract_lifecycle'");
        $hasContractState = $hasContractLifecycle ? false : (bool)$db->fetchValue("SHOW COLUMNS FROM {$table} LIKE 'contract_state'");
        $contractLifecycleColumn = null;
        if ($hasContractLifecycle) {
          $contractLifecycleColumn = 'r.contract_lifecycle';
        } elseif ($hasContractState) {
          $contractLifecycleColumn = 'r.contract_state';
        }

        $completedStatusFilter = "r.status IN ('completed','delivered')";
        $failedStatusFilter = "r.status IN ('failed','expired','rejected','cancelled')";
        $contractStatusCompletedFilter = "LOWER(COALESCE(r.contract_status_esi, r.contract_status, '')) IN ('finished','finished_issuer','finished_contractor','completed')";
        $contractStatusFailedFilter = "LOWER(COALESCE(r.contract_status_esi, r.contract_status, '')) IN ('failed','cancelled','rejected','expired','deleted','reversed')";
        $completedFilter = $contractLifecycleColumn
          ? "({$completedStatusFilter} OR {$contractLifecycleColumn} = 'DELIVERED' OR {$contractStatusCompletedFilter})"
          : "({$completedStatusFilter} OR {$contractStatusCompletedFilter})";
        $failedFilter = $contractLifecycleColumn
          ? "({$failedStatusFilter} OR {$contractLifecycleColumn} IN ('FAILED','EXPIRED') OR {$contractStatusFailedFilter})"
          : "({$failedStatusFilter} OR {$contractStatusFailedFilter})";

        $hallOfFameRows = $db->select(
          "SELECT {$nameExpr} AS hauler_name,
                  COUNT(*) AS total_count,
                  SUM(COALESCE(r.volume_m3, 0)) AS total_volume_m3,
                  SUM(COALESCE(r.collateral_isk, 0)) AS total_collateral_isk
             FROM {$table} r
            WHERE r.corp_id = :cid
              AND {$completedFilter}
            GROUP BY hauler_name
            ORDER BY total_count DESC, total_volume_m3 DESC",
          ['cid' => $corpId]
        );

        $hallOfShameRows = $db->select(
          "SELECT {$nameExpr} AS hauler_name,
                  COUNT(*) AS total_count,
                  SUM(COALESCE(r.volume_m3, 0)) AS total_volume_m3,
                  SUM(COALESCE(r.collateral_isk, 0)) AS total_collateral_isk
             FROM {$table} r
            WHERE r.corp_id = :cid
              AND {$failedFilter}
            GROUP BY hauler_name
            ORDER BY total_count DESC, total_volume_m3 DESC",
          ['cid' => $corpId]
        );

        if ($hasCorpContracts) {
          $failedContracts = $db->select(
            "SELECT c.contract_id, c.acceptor_id, c.volume_m3, c.collateral_isk, c.reward_isk, c.title, c.raw_json,
                    COALESCE(NULLIF(TRIM(e.name), ''), CONCAT('Character:', c.acceptor_id), 'Unassigned') AS hauler_name
               FROM esi_corp_contract c
               LEFT JOIN haul_request r
                 ON r.corp_id = c.corp_id
                AND (r.esi_contract_id = c.contract_id OR r.contract_id = c.contract_id)
               LEFT JOIN eve_entity e
                 ON e.entity_id = c.acceptor_id
                AND e.entity_type = 'character'
              WHERE c.corp_id = :cid
                AND c.type = 'courier'
                AND c.status IN ('failed','cancelled','rejected','expired','deleted','reversed')
                AND r.request_id IS NULL
              ORDER BY c.date_issued DESC",
            ['cid' => $corpId]
          );

          if ($failedContracts) {
            $quoteIds = [];
            $requestKeys = [];
            foreach ($failedContracts as $contract) {
              $description = '';
              if (!empty($contract['raw_json'])) {
                $decoded = json_decode((string)$contract['raw_json'], true);
                if (is_array($decoded) && !empty($decoded['description'])) {
                  $description = trim((string)$decoded['description']);
                }
              }
              if ($description === '') {
                $description = trim((string)($contract['title'] ?? ''));
              }
              if ($description === '') {
                continue;
              }
              if (preg_match('/Quote\s+#?(\d+)/i', $description, $matches)) {
                $quoteId = (int)$matches[1];
                if ($quoteId > 0) {
                  $quoteIds[$quoteId] = true;
                }
              }
              if (preg_match('/Quote\s+([a-f0-9]{32})/i', $description, $matches)) {
                $requestKeys[$matches[1]] = true;
              }
            }

            $validQuoteIds = [];
            if ($quoteIds) {
              $placeholders = implode(',', array_fill(0, count($quoteIds), '?'));
              $rows = $db->select(
                "SELECT quote_id FROM quote WHERE corp_id = ? AND quote_id IN ({$placeholders})",
                array_merge([$corpId], array_keys($quoteIds))
              );
              foreach ($rows as $row) {
                $validQuoteIds[(int)$row['quote_id']] = true;
              }
            }

            $validRequestKeys = [];
            if ($requestKeys) {
              $placeholders = implode(',', array_fill(0, count($requestKeys), '?'));
              $rows = $db->select(
                "SELECT request_key FROM haul_request WHERE corp_id = ? AND request_key IN ({$placeholders})",
                array_merge([$corpId], array_keys($requestKeys))
              );
              foreach ($rows as $row) {
                $key = (string)($row['request_key'] ?? '');
                if ($key !== '') {
                  $validRequestKeys[$key] = true;
                }
              }
            }

            if ($validQuoteIds || $validRequestKeys) {
              $extraShame = [];
              foreach ($failedContracts as $contract) {
                $description = '';
                if (!empty($contract['raw_json'])) {
                  $decoded = json_decode((string)$contract['raw_json'], true);
                  if (is_array($decoded) && !empty($decoded['description'])) {
                    $description = trim((string)$decoded['description']);
                  }
                }
                if ($description === '') {
                  $description = trim((string)($contract['title'] ?? ''));
                }
                if ($description === '') {
                  continue;
                }

                $matchesSiteQuote = false;
                if (preg_match('/Quote\s+#?(\d+)/i', $description, $matches)) {
                  $quoteId = (int)$matches[1];
                  if ($quoteId > 0 && isset($validQuoteIds[$quoteId])) {
                    $matchesSiteQuote = true;
                  }
                }
                if (!$matchesSiteQuote && preg_match('/Quote\s+([a-f0-9]{32})/i', $description, $matches)) {
                  if (isset($validRequestKeys[$matches[1]])) {
                    $matchesSiteQuote = true;
                  }
                }

                if (!$matchesSiteQuote) {
                  continue;
                }

                $name = trim((string)($contract['hauler_name'] ?? ''));
                if ($name === '') {
                  $name = 'Unassigned';
                }
                if (!isset($extraShame[$name])) {
                  $extraShame[$name] = ['hauler_name' => $name, 'total_count' => 0, 'total_volume_m3' => 0.0, 'total_collateral_isk' => 0.0];
                }
                $extraShame[$name]['total_count'] += 1;
                $extraShame[$name]['total_volume_m3'] += (float)($contract['volume_m3'] ?? 0);
                $extraShame[$name]['total_collateral_isk'] += (float)($contract['collateral_isk'] ?? 0);
              }

              if ($extraShame) {
                $merged = [];
                foreach ($hallOfShameRows as $row) {
                  $name = (string)($row['hauler_name'] ?? '');
                  if ($name === '') {
                    $name = 'Unassigned';
                  }
                  $merged[$name] = [
                    'hauler_name' => $name,
                    'total_count' => (int)($row['total_count'] ?? 0),
                    'total_volume_m3' => (float)($row['total_volume_m3'] ?? 0),
                    'total_collateral_isk' => (float)($row['total_collateral_isk'] ?? 0),
                  ];
                }

                foreach ($extraShame as $name => $row) {
                  if (!isset($merged[$name])) {
                    $merged[$name] = $row;
                    continue;
                  }
                  $merged[$name]['total_count'] += (int)$row['total_count'];
                  $merged[$name]['total_volume_m3'] += (float)$row['total_volume_m3'];
                  $merged[$name]['total_collateral_isk'] += (float)$row['total_collateral_isk'];
                }

                $hallOfShameRows = array_values($merged);
                usort($hallOfShameRows, static function ($a, $b) {
                  $count = (int)($b['total_count'] ?? 0) <=> (int)($a['total_count'] ?? 0);
                  if ($count !== 0) {
                    return $count;
                  }
                  return (float)($b['total_volume_m3'] ?? 0) <=> (float)($a['total_volume_m3'] ?? 0);
                });
              }
            }
          }
        }

        foreach ($hallOfFameRows as $row) {
          $completedTotals['count'] += (int)($row['total_count'] ?? 0);
          $completedTotals['volume_m3'] += (float)($row['total_volume_m3'] ?? 0);
          $completedTotals['collateral_isk'] += (float)($row['total_collateral_isk'] ?? 0);
        }
        foreach ($hallOfShameRows as $row) {
          $failedTotals['count'] += (int)($row['total_count'] ?? 0);
          $failedTotals['volume_m3'] += (float)($row['total_volume_m3'] ?? 0);
          $failedTotals['collateral_isk'] += (float)($row['total_collateral_isk'] ?? 0);
        }
      }
    }

    require __DIR__ . '/../src/Views/hall_of_fame.php';
    break;

  case '/my-contracts':
    $appName = $config['app']['name'];
    $title = $appName . ' • My Contracts';
    $basePathForViews = $basePath;

    \App\Auth\Auth::requireLogin($authCtx);

    $userId = (int)($authCtx['user_id'] ?? 0);
    $corpId = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
    $requests = [];
    $requestsAvailable = false;
    $queuePositions = [];
    $queueTotal = 0;

    if ($dbOk && $db !== null && $userId > 0) {
      $hasHaulRequest = (bool)$db->fetchValue("SHOW TABLES LIKE 'haul_request'");
      $hasRequestView = (bool)$db->fetchValue("SHOW FULL TABLES LIKE 'v_haul_request_display'");

      if ($hasHaulRequest && $corpId > 0) {
        $queueStatuses = [
          'requested',
          'awaiting_contract',
          'contract_linked',
          'contract_mismatch',
          'in_queue',
          'draft',
          'quoted',
          'submitted',
          'posted',
        ];
        $statusPlaceholders = implode(',', array_fill(0, count($queueStatuses), '?'));
        $queueRows = $db->select(
          "SELECT request_id
             FROM haul_request
            WHERE corp_id = ?
              AND status IN ({$statusPlaceholders})
            ORDER BY created_at ASC, request_id ASC",
          array_merge([$corpId], $queueStatuses)
        );
        $position = 1;
        foreach ($queueRows as $row) {
          $rid = (int)($row['request_id'] ?? 0);
          if ($rid > 0) {
            $queuePositions[$rid] = $position;
            $position++;
          }
        }
        $queueTotal = count($queueRows);
      }

      if ($hasRequestView) {
        $requests = $db->select(
          "SELECT r.request_id, r.status, r.contract_id, r.esi_contract_id, r.contract_status, r.contract_status_esi, r.esi_status,
                  r.contract_lifecycle, r.contract_state, r.esi_acceptor_name, r.mismatch_reason_json,
                  COALESCE(fs.system_name, r.from_name) AS from_name,
                  COALESCE(ts.system_name, r.to_name) AS to_name,
                  r.volume_m3, r.reward_isk, r.created_at, hr.request_key
             FROM v_haul_request_display r
             JOIN haul_request hr ON hr.request_id = r.request_id
             LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
             LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
            WHERE r.corp_id = ?
              AND hr.requester_user_id = ?
            ORDER BY r.created_at DESC
            LIMIT 50",
          [$corpId, $userId]
        );
      } elseif ($hasHaulRequest) {
        $requests = $db->select(
          "SELECT r.request_id, r.request_key, r.status, r.contract_id, r.esi_contract_id, r.contract_status, r.contract_status_esi,
                  r.esi_status, r.contract_lifecycle, r.contract_state, r.esi_acceptor_name, r.mismatch_reason_json,
                  r.from_location_id, r.to_location_id, r.volume_m3, r.reward_isk, r.created_at,
                  fs.system_name AS from_name, ts.system_name AS to_name
             FROM haul_request r
             LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
             LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
            WHERE r.corp_id = ?
              AND r.requester_user_id = ?
            ORDER BY r.created_at DESC
            LIMIT 50",
          [$corpId, $userId]
        );
      }

      if ($requests) {
        $requestsAvailable = true;
      }
    }

    require __DIR__ . '/../src/Views/my_contracts.php';
    break;

  case '/request':
    $appName = $config['app']['name'];
    $title = $appName . ' • Contract Instructions';
    $basePathForViews = $basePath;

    \App\Auth\Auth::requireLogin($authCtx);
    $requestKey = trim((string)($_GET['request_key'] ?? ''));
    $error = null;
    $request = null;
    $routeSummary = '';
    $issuerName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');
    $shipClassLabel = '';
    $shipClassMax = 0.0;
    $contractDescription = '';

    if ($requestKey === '') {
      $error = 'Request key is required.';
    } elseif ($db === null || !($health['db'] ?? false)) {
      $error = 'Database unavailable.';
    } else {
      $request = $db->one(
        "SELECT request_id, corp_id, requester_user_id, from_location_id, to_location_id, reward_isk, collateral_isk, volume_m3,
                ship_class, route_policy, route_profile, price_breakdown_json, quote_id, status, contract_id, esi_contract_id,
                contract_status, contract_status_esi, esi_status, contract_lifecycle, contract_state,
                contract_acceptor_name, esi_acceptor_name, ops_assignee_name,
                contract_hint_text, mismatch_reason_json, contract_matched_at, request_key
           FROM haul_request
          WHERE request_key = :rkey
          LIMIT 1",
        ['rkey' => $requestKey]
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

        $hintText = trim((string)($request['contract_hint_text'] ?? ''));
        if ($hintText === '' && !empty($request['request_key'])) {
          $hintText = 'Quote ' . (string)$request['request_key'];
        } elseif ($hintText === '' && !empty($request['quote_id'])) {
          $hintText = 'Quote #' . (string)$request['quote_id'];
        }
        if ($hintText === '') {
          $hintText = 'Request #' . (string)$request['request_id'];
        }
        $contractDescription = $hintText;
      }
    }
    }

    require __DIR__ . '/../src/Views/request.php';
    break;

  case '/rates':
    $appName = $config['app']['name'];
    $title = $appName . ' • Rates';
    $basePathForViews = $basePath;
    $corpId = (int)($config['corp']['id'] ?? 0);
    $ratePlans = $db->all(
      "SELECT service_class, rate_per_jump, collateral_rate, min_price, updated_at
         FROM rate_plan
        WHERE corp_id = :cid
        ORDER BY FIELD(service_class, 'BR', 'DST', 'FREIGHTER', 'JF'), service_class",
      ['cid' => $corpId]
    );

    $priorityFees = ['normal' => 0.0, 'high' => 0.0];
    $priorityRow = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.priority_fee' LIMIT 1",
      ['cid' => $corpId]
    );
    if (!$priorityRow) {
      $priorityRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.priority_fee' LIMIT 1"
      );
    }
    if ($priorityRow && !empty($priorityRow['setting_json'])) {
      $decoded = json_decode((string)$priorityRow['setting_json'], true);
      if (is_array($decoded)) {
        $priorityFees = array_merge($priorityFees, $decoded);
      }
    }

    $securityMultipliers = [
      'high' => 1.0,
      'low' => 1.5,
      'null' => 2.5,
      'pochven' => 3.0,
      'zarzakh' => 3.5,
      'thera' => 3.0,
    ];
    $flatRiskFees = ['lowsec' => 0.0, 'nullsec' => 0.0, 'special' => 0.0];
    $volumePressure = ['enabled' => false, 'thresholds' => []];
    $securityRow = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.security_multipliers' LIMIT 1",
      ['cid' => $corpId]
    );
    if (!$securityRow) {
      $securityRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.security_multipliers' LIMIT 1"
      );
    }
    if ($securityRow && !empty($securityRow['setting_json'])) {
      $decoded = json_decode((string)$securityRow['setting_json'], true);
      if (is_array($decoded)) {
        $securityMultipliers = array_merge($securityMultipliers, $decoded);
      }
    }
    $flatRow = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.flat_risk_fees' LIMIT 1",
      ['cid' => $corpId]
    );
    if (!$flatRow) {
      $flatRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.flat_risk_fees' LIMIT 1"
      );
    }
    if ($flatRow && !empty($flatRow['setting_json'])) {
      $decoded = json_decode((string)$flatRow['setting_json'], true);
      if (is_array($decoded)) {
        $flatRiskFees = array_merge($flatRiskFees, $decoded);
      }
    }
    $volumeRow = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.volume_pressure' LIMIT 1",
      ['cid' => $corpId]
    );
    if (!$volumeRow) {
      $volumeRow = $db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.volume_pressure' LIMIT 1"
      );
    }
    if ($volumeRow && !empty($volumeRow['setting_json'])) {
      $decoded = json_decode((string)$volumeRow['setting_json'], true);
      if (is_array($decoded)) {
        $volumePressure = array_merge($volumePressure, $decoded);
      }
    }
    $ratesUpdatedAt = null;
    foreach ($ratePlans as $plan) {
      if (!empty($plan['updated_at'])) {
        $ratesUpdatedAt = max($ratesUpdatedAt ?? '', (string)$plan['updated_at']);
      }
    }

    ob_start();
    require __DIR__ . '/../src/Views/rates.php';
    $body = ob_get_clean();
    $basePath = $basePath;
    require __DIR__ . '/../src/Views/layout.php';
    break;

  case '/faq':
    $appName = $config['app']['name'];
    $title = $appName . ' • FAQ';
    $basePathForViews = $basePath;
    $dashboardUrl = htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8');
    $body = <<<HTML
      <section class="card">
        <div class="card-header">
          <h2>Frequently Asked Questions</h2>
          <p class="muted">Everything you need to submit a clean courier contract.</p>
        </div>
        <div class="content">
          <div class="stack">
            <h3>How does the hauling process work?</h3>
            <ol>
              <li><strong>Get Quote</strong> – Enter pickup, destination, volume, and options to calculate the price.</li>
              <li><strong>Create Request</strong> – Confirm the quote to create a hauling request.</li>
              <li><strong>Contract Instructions</strong> – Open View Contract Instructions to see the exact values you must use.</li>
              <li><strong>Create In-Game Contract</strong> – Create a private courier contract using those values.</li>
              <li><strong>Execution &amp; Tracking</strong> – A hauler accepts the contract in-game; progress updates automatically.</li>
            </ol>
          </div>
          <div class="stack">
            <h3>What is validated on my contract?</h3>
            <p class="muted">Only these fields are validated against the request:</p>
            <ul>
              <li>Pickup system</li>
              <li>Destination system</li>
              <li>Collateral</li>
              <li>Reward</li>
              <li>Volume limit</li>
            </ul>
            <p>If any of these do not match exactly, the contract may be rejected or delayed.</p>
          </div>
          <div class="stack">
            <h3>What do I need to do in-game?</h3>
            <ul>
              <li>Create a <strong>Private Courier Contract</strong>.</li>
              <li>Set <strong>Start Location</strong> and <strong>End Location</strong> exactly as shown.</li>
              <li>Enter <strong>Collateral</strong> and <strong>Reward</strong> exactly as listed.</li>
              <li>Ensure total item volume is within the <strong>Volume Limit</strong>.</li>
              <li>Copy the <strong>Contract Description Template</strong> into the description field.</li>
            </ul>
            <p class="muted">Everything else is informational.</p>
          </div>
          <div class="stack">
            <h3>What is the Contract Description Template?</h3>
            <p>A reference string (for example: <strong>Quote a6da9f5422cda4afc5574f1f0d2064c8</strong>) used to link your in-game contract to the request. Always copy it exactly into the contract description.</p>
          </div>
          <div class="stack">
            <h3>How do I track my haul?</h3>
            <p>Use <strong>My Contracts</strong> on the site:</p>
            <ul>
              <li>Status updates come from in-game contract state (ESI).</li>
              <li><strong>Picked up / En route</strong> means the contract has been accepted in-game.</li>
              <li>Delivery, failure, or expiry are updated automatically.</li>
            </ul>
          </div>
          <div class="alert alert-warning">
            <strong>Important</strong>
            <ul>
              <li>The Contract Instructions page is the single source of truth.</li>
              <li>Exact matches prevent delays.</li>
              <li>In-game status always overrides manual tracking.</li>
            </ul>
          </div>
          <a class="btn ghost" href="{$dashboardUrl}">Back to dashboard</a>
        </div>
      </section>
      HTML;
    $basePath = $basePath;
    require __DIR__ . '/../src/Views/layout.php';
    break;

  case '/api/quote':
  case '/api/quote/':
    require __DIR__ . '/api/quote/index.php';
    break;
  case '/api/quote/buyback':
  case '/api/quote/buyback/':
    require __DIR__ . '/api/quote/buyback/index.php';
    break;

  case '/api/requests/create':
  case '/api/requests/create/':
    require __DIR__ . '/api/requests/create/index.php';
    break;
  case '/api/requests/buyback':
  case '/api/requests/buyback/':
    require __DIR__ . '/api/requests/buyback/index.php';
    break;
  case '/api/requests/delete':
  case '/api/requests/delete/':
    require __DIR__ . '/api/requests/delete/index.php';
    break;
  case '/api/requests/assign':
  case '/api/requests/assign/':
    require __DIR__ . '/api/requests/assign/index.php';
    break;
  case '/api/requests/update-status':
  case '/api/requests/update-status/':
    require __DIR__ . '/api/requests/update-status/index.php';
    break;

  case '/api/contracts/attach':
  case '/api/contracts/attach/':
    require __DIR__ . '/api/contracts/attach/index.php';
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
