<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../../../src/bootstrap.php';

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
  api_send_json(['ok' => true, 'items' => []]);
}

$stationPlaceholderPattern = '/^station\\s+\\d+$/i';
$resolvedFromCache = 0;
$resolvedFromEsi = 0;

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

foreach ($rows as $row) {
  $stationId = (int)($row['station_id'] ?? 0);
  if ($stationId <= 0 || isset($seen[$stationId])) {
    continue;
  }

  $stationName = trim((string)($row['station_name'] ?? ''));
  $stationTypeId = isset($row['station_type_id']) ? (int)$row['station_type_id'] : null;
  $systemId = (int)($row['system_id'] ?? 0);
  $systemName = trim((string)($row['system_name'] ?? ''));

  $needsResolve = $stationName === ''
    || preg_match($stationPlaceholderPattern, $stationName) === 1
    || $stationTypeId === null;

  if ($needsResolve) {
    $resp = $services['esi_client']->get("/latest/universe/stations/{$stationId}/", null, null, 86400);
    if (!empty($resp['ok']) && is_array($resp['json'])) {
      $resolvedName = trim((string)($resp['json']['name'] ?? ''));
      $resolvedSystemId = (int)($resp['json']['system_id'] ?? 0);
      $resolvedTypeId = isset($resp['json']['type_id']) ? (int)$resp['json']['type_id'] : null;

      if (!empty($resp['from_cache'])) {
        $resolvedFromCache += 1;
      } else {
        $resolvedFromEsi += 1;
      }

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
        $db->upsertEntity($stationId, 'station', $resolvedName, $resp['json'], null, 'esi');
        $stationName = $resolvedName;
        $systemId = $resolvedSystemId;
        $stationTypeId = $resolvedTypeId;
        $systemName = $fetchSystemName($systemId);
      }
    }
  }

  if ($stationName === '' || preg_match($stationPlaceholderPattern, $stationName) === 1) {
    continue;
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
    'station_type_id' => $stationTypeId,
  ];
  $seen[$stationId] = true;

  if (count($items) >= 10) {
    break;
  }
}

error_log(sprintf(
  '[admin defaults station lookup] q="%s" results=%d resolved_cache=%d resolved_esi=%d',
  $query,
  count($items),
  $resolvedFromCache,
  $resolvedFromEsi
));

api_send_json(['ok' => true, 'items' => $items]);
