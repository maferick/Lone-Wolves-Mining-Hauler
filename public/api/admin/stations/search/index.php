<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../bootstrap.php';

use App\Auth\Auth;

api_require_key();

$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
if (empty($authCtx['user_id'])) {
  api_send_json(['ok' => false, 'error' => 'login required'], 403);
}
if (!Auth::can($authCtx, 'corp.manage')) {
  api_send_json(['ok' => false, 'error' => 'forbidden'], 403);
}

if ($db === null || !isset($services['esi_client'])) {
  api_send_json(['ok' => false, 'error' => 'service unavailable'], 503);
}

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '' || mb_strlen($query) < 2) {
  api_send_json(['items' => [], 'warning' => null]);
}

$stationPlaceholderPattern = '/^station\\s+\\d+$/i';
$resolvedFromCache = 0;
$resolvedFromEsi = 0;
$esiAttempts = 0;
$esiSuccess = 0;
$placeholderCount = 0;
$warning = null;
$rateLimitRemaining = null;

$esiBase = rtrim($config['esi']['endpoints']['esi_base'] ?? 'https://esi.evetech.net', '/');
$defaultTtl = (int)($config['esi']['cache']['default_ttl_seconds'] ?? 300);

$parseCacheTtl = static function(array $headers, int $fallback): int {
  $cacheControl = strtolower((string)($headers['cache-control'] ?? ''));
  if ($cacheControl !== '' && preg_match('/max-age=(\\d+)/', $cacheControl, $matches)) {
    $maxAge = (int)$matches[1];
    if ($maxAge > 0) {
      return $maxAge;
    }
  }

  $expires = trim((string)($headers['expires'] ?? ''));
  if ($expires !== '') {
    $expiresTs = strtotime($expires);
    if ($expiresTs !== false) {
      $ttl = $expiresTs - time();
      if ($ttl > 0) {
        return $ttl;
      }
    }
  }

  return $fallback;
};

$parseRateRemaining = static function(array $headers): ?int {
  foreach (['x-ratelimit-remaining', 'x-rate-limit-remaining', 'x-esi-error-limit-remain'] as $name) {
    $key = strtolower($name);
    if (!array_key_exists($key, $headers)) {
      continue;
    }
    $value = trim((string)$headers[$key]);
    if ($value === '') {
      continue;
    }
    return (int)$value;
  }
  return null;
};

$parseRetryAfter = static function(array $headers): ?int {
  foreach (['retry-after', 'x-esi-error-limit-reset', 'x-rate-limit-reset'] as $name) {
    $key = strtolower($name);
    if (!array_key_exists($key, $headers)) {
      continue;
    }
    $value = trim((string)$headers[$key]);
    if ($value === '') {
      continue;
    }
    return max(1, (int)$value);
  }
  return null;
};

$shouldRetry = static function(int $status): bool {
  return in_array($status, [420, 429], true) || $status >= 500;
};

$backoffSleep = static function(array $headers, int $attempt) use ($parseRetryAfter): void {
  $retryAfter = $parseRetryAfter($headers);
  $base = 0.5 * (2 ** $attempt);
  $wait = $retryAfter !== null ? max($retryAfter, $base) : $base;
  $jitter = random_int(0, 250) / 1000;
  $sleepSeconds = min(10.0, $wait + $jitter);
  if ($sleepSeconds > 0) {
    usleep((int)round($sleepSeconds * 1000000));
  }
};

$rows = $db->select(
  "SELECT st.station_id, st.station_name, st.system_id, st.station_type_id,
          COALESCE(ms.system_name, s.system_name) AS system_name
     FROM eve_station st
     LEFT JOIN map_system ms ON ms.system_id = st.system_id
     LEFT JOIN eve_system s ON s.system_id = st.system_id
    WHERE st.station_name LIKE :prefix
       OR st.station_name LIKE :contains
       OR COALESCE(ms.system_name, s.system_name) LIKE :prefix
    ORDER BY system_name, st.station_name, st.station_id
    LIMIT 10",
  [
    'prefix' => $query . '%',
    'contains' => '%' . $query . '%',
  ]
);

$fetchSystemName = static function (int $systemId) use ($db): string {
  if ($systemId <= 0) {
    return '';
  }
  $name = $db->fetchValue(
    "SELECT system_name FROM map_system WHERE system_id = :id LIMIT 1",
    ['id' => $systemId]
  );
  if (!empty($name)) {
    return (string)$name;
  }
  $name = $db->fetchValue(
    "SELECT system_name FROM eve_system WHERE system_id = :id LIMIT 1",
    ['id' => $systemId]
  );
  return $name ? (string)$name : '';
};

$items = [];
$seen = [];

$resolveStation = static function (int $stationId) use (
  $services,
  $db,
  $esiBase,
  $defaultTtl,
  $parseCacheTtl,
  $shouldRetry,
  $backoffSleep,
  &$esiAttempts,
  &$esiSuccess,
  &$resolvedFromCache,
  &$resolvedFromEsi,
  &$warning,
  &$rateLimitRemaining,
  $parseRateRemaining
): ?array {
  $path = "/latest/universe/stations/{$stationId}/";
  $ttlSeconds = max(60, $defaultTtl);
  $maxRetries = 2;
  $attempt = 0;

  while (true) {
    $esiAttempts += 1;
    $resp = $services['esi_client']->get($path, null, null, $ttlSeconds);
    $status = (int)($resp['status'] ?? 0);

    if (!empty($resp['headers']) && is_array($resp['headers'])) {
      $headerRemaining = $parseRateRemaining($resp['headers']);
      if ($headerRemaining !== null) {
        $rateLimitRemaining = $headerRemaining;
      }
    }

    if (!empty($resp['ok']) && is_array($resp['json'])) {
      $esiSuccess += 1;
      if (!empty($resp['from_cache'])) {
        $resolvedFromCache += 1;
      } else {
        $resolvedFromEsi += 1;
      }
      if (!empty($resp['warning']) && $warning === null) {
        $warning = 'ESI temporarily unavailable; showing cached results.';
      }

      if (!empty($resp['headers']) && is_array($resp['headers'])) {
        $ttlSeconds = $parseCacheTtl($resp['headers'], $ttlSeconds);
        $url = $esiBase . $path;
        $cacheKeyBin = App\Db\Db::esiCacheKey('GET', $url, null, null);
        $raw = $resp['raw'] ?? null;
        if (is_string($raw) && $raw !== '') {
          $db->esiCachePut(
            null,
            $cacheKeyBin,
            'GET',
            $url,
            null,
            null,
            200,
            $resp['headers']['etag'] ?? null,
            $resp['headers']['last-modified'] ?? null,
            $ttlSeconds,
            $raw,
            null
          );
        }
      }

      return [
        'data' => $resp['json'],
        'from_cache' => !empty($resp['from_cache']),
      ];
    }

    if ($shouldRetry($status) && $attempt < $maxRetries) {
      $backoffSleep($resp['headers'] ?? [], $attempt);
      $attempt += 1;
      continue;
    }

    if ($warning === null) {
      $warning = 'ESI temporarily unavailable; showing cached results.';
    }
    return null;
  }
};

foreach ($rows as $row) {
  $stationId = (int)($row['station_id'] ?? 0);
  if ($stationId <= 0 || isset($seen[$stationId])) {
    continue;
  }

  $stationName = trim((string)($row['station_name'] ?? ''));
  $stationTypeId = isset($row['station_type_id']) ? (int)$row['station_type_id'] : null;
  $systemId = (int)($row['system_id'] ?? 0);
  $systemName = trim((string)($row['system_name'] ?? ''));

  $isPlaceholder = $stationName === '' || preg_match($stationPlaceholderPattern, $stationName) === 1;
  $needsResolve = $isPlaceholder || $stationTypeId === null;

  $itemSource = 'cache';

  if ($needsResolve) {
    $placeholderCount += 1;
    $resolved = $resolveStation($stationId);
    if (is_array($resolved)) {
      $resolvedData = $resolved['data'] ?? null;
      if (!is_array($resolvedData)) {
        $resolvedData = null;
      }
      $resolvedName = trim((string)($resolvedData['name'] ?? ''));
      $resolvedSystemId = (int)($resolvedData['system_id'] ?? 0);
      $resolvedTypeId = isset($resolvedData['type_id']) ? (int)$resolvedData['type_id'] : null;

      if ($resolvedName !== '' && $resolvedSystemId > 0) {
        $db->execute(
          "INSERT INTO eve_station (station_id, system_id, station_name, station_type_id)
           VALUES (:station_id, :system_id, :station_name, :station_type_id)
           ON DUPLICATE KEY UPDATE
            system_id=VALUES(system_id),
            station_name=VALUES(station_name),
            station_type_id=VALUES(station_type_id)",
          [
            'station_id' => $stationId,
            'system_id' => $resolvedSystemId,
            'station_name' => $resolvedName,
            'station_type_id' => $resolvedTypeId,
          ]
        );
        $db->upsertEntity($stationId, 'station', $resolvedName, $resolvedData, null, 'esi');
        $stationName = $resolvedName;
        $systemId = $resolvedSystemId;
        $stationTypeId = $resolvedTypeId;
        $systemName = $fetchSystemName($systemId);
        $isPlaceholder = false;
        if (empty($resolved['from_cache'])) {
          $itemSource = 'esi';
        }
      }
    }

    usleep(random_int(150000, 250000));
  }

  if ($stationName === '') {
    $stationName = "Station {$stationId}";
    $isPlaceholder = true;
  }

  if ($systemName === '' && $systemId > 0) {
    $systemName = $fetchSystemName($systemId);
  }

  $label = $systemName !== '' ? "{$systemName} — {$stationName}" : $stationName;
  $items[] = [
    'value' => $stationId,
    'label' => $label,
    'station_id' => $stationId,
    'station_name' => $stationName,
    'system_id' => $systemId,
    'system_name' => $systemName,
    'source' => $itemSource,
    'is_placeholder' => $isPlaceholder,
  ];
  $seen[$stationId] = true;

  if (count($items) >= 10) {
    break;
  }
}

error_log(sprintf(
  '[admin defaults station lookup] q="%s" db_count=%d placeholders=%d esi_attempts=%d esi_success=%d final_count=%d ratelimit_remaining=%s resolved_cache=%d resolved_esi=%d',
  $query,
  count($rows),
  $placeholderCount,
  $esiAttempts,
  $esiSuccess,
  count($items),
  $rateLimitRemaining === null ? 'n/a' : (string)$rateLimitRemaining,
  $resolvedFromCache,
  $resolvedFromEsi
));

api_send_json(['items' => $items, 'warning' => $warning]);
