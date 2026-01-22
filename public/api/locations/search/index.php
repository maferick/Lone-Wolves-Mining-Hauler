<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../bootstrap.php';

api_require_key();

if ($db === null) {
  api_send_json([
    'ok' => false,
    'error' => 'database_unavailable',
  ], 503);
}

$query = trim((string)($_GET['q'] ?? $_GET['prefix'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? 'pickup')));
$limit = (int)($_GET['limit'] ?? 10);

if (!in_array($type, ['pickup', 'destination'], true)) {
  $type = 'pickup';
}

if ($limit <= 0) {
  $limit = 10;
} elseif ($limit > 10) {
  $limit = 10;
}

if (mb_strlen($query) < 3) {
  api_send_json([
    'ok' => true,
    'items' => [],
  ]);
}

$accessRules = [
  'systems' => [],
  'regions' => [],
  'structures' => [],
];
$corpIdForAccess = (int)($authCtx['corp_id'] ?? ($config['corp']['id'] ?? 0));
$allowStructureLocations = true;
if ($corpIdForAccess > 0) {
  $accessRow = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'access.rules' LIMIT 1",
    ['cid' => $corpIdForAccess]
  );
  if ($accessRow === null) {
    $accessRow = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'access.rules' LIMIT 1"
    );
  }
  if ($accessRow && !empty($accessRow['setting_json'])) {
    $decoded = json_decode((string)$accessRow['setting_json'], true);
    if (is_array($decoded)) {
      $accessRules = array_replace_recursive($accessRules, $decoded);
    }
  }

  $locationRow = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'quote.location_mode' LIMIT 1",
    ['cid' => $corpIdForAccess]
  );
  if ($locationRow === null && $corpIdForAccess !== 0) {
    $locationRow = $db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'quote.location_mode' LIMIT 1"
    );
  }
  if ($locationRow && !empty($locationRow['setting_json'])) {
    $decoded = json_decode((string)$locationRow['setting_json'], true);
    if (is_array($decoded) && array_key_exists('allow_structures', $decoded)) {
      $allowStructureLocations = (bool)$decoded['allow_structures'];
    }
  }
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
$allowedLocationIds = [];
foreach ($accessRules['structures'] ?? [] as $rule) {
  if (empty($rule['allowed'])) {
    continue;
  }
  if ($type === 'pickup' && empty($rule['pickup_allowed'])) {
    continue;
  }
  if ($type === 'destination' && empty($rule['delivery_allowed'])) {
    continue;
  }
  $id = (int)($rule['id'] ?? 0);
  if ($id > 0) {
    $allowedLocationIds[] = $id;
  }
}

$allowedSystemIds = array_values(array_filter($allowedSystemIds));
$allowedRegionIds = array_values(array_filter($allowedRegionIds));
$allowedLocationIds = array_values(array_filter(array_unique($allowedLocationIds)));
$hasAllowlist = !empty($allowedSystemIds) || !empty($allowedRegionIds) || !empty($allowedLocationIds);

$allowedSystemScope = $allowedSystemIds;
if (!empty($allowedRegionIds)) {
  $regionPlaceholders = implode(',', array_fill(0, count($allowedRegionIds), '?'));
  $systemRows = $db->select(
    "SELECT ms.system_id,
            COALESCE(ms.system_name, s.system_name) AS system_name
       FROM map_system ms
       LEFT JOIN eve_system s ON s.system_id = ms.system_id
       LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
      WHERE COALESCE(ms.region_id, c.region_id) IN ({$regionPlaceholders})",
    $allowedRegionIds
  );
  foreach ($systemRows as $row) {
    $systemId = (int)($row['system_id'] ?? 0);
    if ($systemId > 0) {
      $allowedSystemScope[] = $systemId;
    }
  }
}
$allowedSystemScope = array_values(array_unique(array_filter($allowedSystemScope)));

$prefixLower = mb_strtolower($query);
$likePrefix = $query . '%';
$likeContains = '%' . $query . '%';

$stationPlaceholderPattern = '/^station\s+\d+$/i';
$resolveStationName = static function (string $stationName, string $aliasName, string $entityName) use ($stationPlaceholderPattern): string {
  $stationName = trim($stationName);
  $aliasName = trim($aliasName);
  $entityName = trim($entityName);
  $isPlaceholder = $stationName === '' || preg_match($stationPlaceholderPattern, $stationName) === 1;
  if ($isPlaceholder) {
    if ($aliasName !== '') {
      return $aliasName;
    }
    if ($entityName !== '' && preg_match($stationPlaceholderPattern, $entityName) !== 1) {
      return $entityName;
    }
  }
  return $stationName;
};

$matchScore = static function (string $systemName, string $locationName) use ($prefixLower): ?int {
  $systemLower = mb_strtolower($systemName);
  $locationLower = mb_strtolower($locationName);
  if ($systemLower !== '' && str_starts_with($systemLower, $prefixLower)) {
    return 0;
  }
  if ($locationLower !== '' && str_starts_with($locationLower, $prefixLower)) {
    return 1;
  }
  if (($systemLower !== '' && str_contains($systemLower, $prefixLower))
    || ($locationLower !== '' && str_contains($locationLower, $prefixLower))) {
    return 2;
  }
  return null;
};

$buildDisplayName = static function (string $systemName, string $locationName): string {
  $systemName = trim($systemName);
  $locationName = trim($locationName);
  if ($systemName !== '' && $locationName !== '') {
    return $systemName . ' — ' . $locationName;
  }
  return $locationName !== '' ? $locationName : $systemName;
};

$items = [];
$seen = [];

$addItem = static function (array $item, int $score, bool $pinned) use (&$items, &$seen): void {
  $key = ($item['location_type'] ?? '') . ':' . (string)($item['location_id'] ?? 0);
  if ($key === ':' || isset($seen[$key])) {
    return;
  }
  $item['sort_score'] = $score;
  $item['sort_pinned'] = $pinned ? 0 : 1;
  $item['sort_system'] = mb_strtolower((string)($item['system_name'] ?? ''));
  $item['sort_name'] = mb_strtolower((string)($item['location_name'] ?? ''));
  $items[] = $item;
  $seen[$key] = true;
};

$locationIdSet = $allowedLocationIds;
if ($locationIdSet) {
  $placeholders = implode(',', array_fill(0, count($locationIdSet), '?'));
  $stationRows = $db->select(
    "SELECT st.station_id AS location_id,
            st.station_name,
            ea.alias AS alias_name,
            ee.name AS entity_name,
            st.system_id,
            COALESCE(ms.system_name, s.system_name) AS system_name,
            COALESCE(ms.region_id, c.region_id) AS region_id
       FROM eve_station st
       LEFT JOIN (
         SELECT entity_id, MIN(alias) AS alias
           FROM eve_entity_alias
          WHERE entity_type = 'station'
          GROUP BY entity_id
       ) ea ON ea.entity_id = st.station_id
       LEFT JOIN eve_entity ee ON ee.entity_id = st.station_id AND ee.entity_type = 'station'
       LEFT JOIN map_system ms ON ms.system_id = st.system_id
       LEFT JOIN eve_system s ON s.system_id = st.system_id
       LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
      WHERE st.station_id IN ({$placeholders})",
    $locationIdSet
  );
  foreach ($stationRows as $row) {
    $locationName = $resolveStationName(
      (string)($row['station_name'] ?? ''),
      (string)($row['alias_name'] ?? ''),
      (string)($row['entity_name'] ?? '')
    );
    $systemName = (string)($row['system_name'] ?? '');
    $score = $matchScore($systemName, $locationName);
    if ($score === null) {
      continue;
    }
    $displayName = $buildDisplayName($systemName, $locationName);
    $addItem([
      'name' => $displayName,
      'label' => 'Station',
      'location_type' => 'npc_station',
      'location_id' => (int)$row['location_id'],
      'location_name' => $locationName,
      'system_id' => (int)($row['system_id'] ?? 0),
      'system_name' => $systemName,
    ], $score, true);
  }

  if ($allowStructureLocations) {
    $structureRows = $db->select(
      "SELECT es.structure_id AS location_id,
              es.structure_name,
              es.system_id,
              COALESCE(ms.system_name, s.system_name) AS system_name,
              COALESCE(ms.region_id, c.region_id) AS region_id
         FROM eve_structure es
         LEFT JOIN map_system ms ON ms.system_id = es.system_id
         LEFT JOIN eve_system s ON s.system_id = es.system_id
         LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
        WHERE es.structure_id IN ({$placeholders})",
      $locationIdSet
    );
    foreach ($structureRows as $row) {
      $locationName = trim((string)($row['structure_name'] ?? ''));
      if ($locationName === '') {
        continue;
      }
      $systemName = (string)($row['system_name'] ?? '');
      $score = $matchScore($systemName, $locationName);
      if ($score === null) {
        continue;
      }
      $displayName = $buildDisplayName($systemName, $locationName);
      $addItem([
        'name' => $displayName,
        'label' => 'Structure',
        'location_type' => 'structure',
        'location_id' => (int)$row['location_id'],
        'location_name' => $locationName,
        'system_id' => (int)($row['system_id'] ?? 0),
        'system_name' => $systemName,
      ], $score, true);
    }
  }
}

$scopeSystemIds = $allowedSystemIds;
$scopeRegionIds = $allowedRegionIds;
$buildScopeClause = static function (string $systemColumn, string $regionExpr) use ($scopeSystemIds, $scopeRegionIds): array {
  $parts = [];
  $params = [];
  if (!empty($scopeSystemIds)) {
    $systemPlaceholders = implode(',', array_fill(0, count($scopeSystemIds), '?'));
    $parts[] = "{$systemColumn} IN ({$systemPlaceholders})";
    $params = array_merge($params, $scopeSystemIds);
  }
  if (!empty($scopeRegionIds)) {
    $regionPlaceholders = implode(',', array_fill(0, count($scopeRegionIds), '?'));
    $parts[] = "{$regionExpr} IN ({$regionPlaceholders})";
    $params = array_merge($params, $scopeRegionIds);
  }
  if (!$parts) {
    return ['', []];
  }
  return [' AND (' . implode(' OR ', $parts) . ')', $params];
};

$stationScope = $buildScopeClause('st.system_id', 'COALESCE(ms.region_id, c.region_id)');
$structureScope = $buildScopeClause('es.system_id', 'COALESCE(ms.region_id, c.region_id)');

if (!$hasAllowlist || !empty($scopeSystemIds) || !empty($scopeRegionIds)) {
  $stationRows = $db->select(
    "SELECT st.station_id AS location_id,
            st.station_name,
            ea.alias AS alias_name,
            ee.name AS entity_name,
            st.system_id,
            COALESCE(ms.system_name, s.system_name) AS system_name,
            COALESCE(ms.region_id, c.region_id) AS region_id
       FROM eve_station st
       LEFT JOIN (
         SELECT entity_id, MIN(alias) AS alias
           FROM eve_entity_alias
          WHERE entity_type = 'station'
          GROUP BY entity_id
       ) ea ON ea.entity_id = st.station_id
       LEFT JOIN eve_entity ee ON ee.entity_id = st.station_id AND ee.entity_type = 'station'
       LEFT JOIN map_system ms ON ms.system_id = st.system_id
       LEFT JOIN eve_system s ON s.system_id = st.system_id
       LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
      WHERE (st.station_name LIKE ? OR st.station_name LIKE ? OR COALESCE(ms.system_name, s.system_name) LIKE ?)
        {$stationScope[0]}
      ORDER BY system_name, st.station_name, st.station_id
      LIMIT ?",
    array_merge([$likePrefix, $likeContains, $likePrefix], $stationScope[1], [$limit * 6])
  );

  foreach ($stationRows as $row) {
    $locationName = $resolveStationName(
      (string)($row['station_name'] ?? ''),
      (string)($row['alias_name'] ?? ''),
      (string)($row['entity_name'] ?? '')
    );
    if ($locationName === '') {
      continue;
    }
    $systemName = (string)($row['system_name'] ?? '');
    $score = $matchScore($systemName, $locationName);
    if ($score === null) {
      continue;
    }
    $displayName = $buildDisplayName($systemName, $locationName);
    $addItem([
      'name' => $displayName,
      'label' => 'Station',
      'location_type' => 'npc_station',
      'location_id' => (int)$row['location_id'],
      'location_name' => $locationName,
      'system_id' => (int)($row['system_id'] ?? 0),
      'system_name' => $systemName,
    ], $score, false);
  }

  if ($allowStructureLocations) {
    $structureRows = $db->select(
      "SELECT es.structure_id AS location_id,
              es.structure_name,
              es.system_id,
              COALESCE(ms.system_name, s.system_name) AS system_name,
              COALESCE(ms.region_id, c.region_id) AS region_id
         FROM eve_structure es
         LEFT JOIN map_system ms ON ms.system_id = es.system_id
         LEFT JOIN eve_system s ON s.system_id = es.system_id
         LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
        WHERE (es.structure_name LIKE ? OR es.structure_name LIKE ? OR COALESCE(ms.system_name, s.system_name) LIKE ?)
          {$structureScope[0]}
        ORDER BY system_name, es.structure_name, es.structure_id
        LIMIT ?",
      array_merge([$likePrefix, $likeContains, $likePrefix], $structureScope[1], [$limit * 6])
    );

    foreach ($structureRows as $row) {
      $locationName = trim((string)($row['structure_name'] ?? ''));
      if ($locationName === '') {
        continue;
      }
      $systemName = (string)($row['system_name'] ?? '');
      $score = $matchScore($systemName, $locationName);
      if ($score === null) {
        continue;
      }
      $displayName = $buildDisplayName($systemName, $locationName);
      $addItem([
        'name' => $displayName,
        'label' => 'Structure',
        'location_type' => 'structure',
        'location_id' => (int)$row['location_id'],
        'location_name' => $locationName,
        'system_id' => (int)($row['system_id'] ?? 0),
        'system_name' => $systemName,
      ], $score, false);
    }
  }
}

usort($items, static function (array $a, array $b): int {
  $pinned = ($a['sort_pinned'] ?? 1) <=> ($b['sort_pinned'] ?? 1);
  if ($pinned !== 0) {
    return $pinned;
  }
  $score = ($a['sort_score'] ?? 99) <=> ($b['sort_score'] ?? 99);
  if ($score !== 0) {
    return $score;
  }
  $system = ($a['sort_system'] ?? '') <=> ($b['sort_system'] ?? '');
  if ($system !== 0) {
    return $system;
  }
  return ($a['sort_name'] ?? '') <=> ($b['sort_name'] ?? '');
});

$systemFilter = '';
$systemParams = [];
if ($hasAllowlist) {
  if (!empty($allowedSystemScope)) {
    $systemPlaceholders = implode(',', array_fill(0, count($allowedSystemScope), '?'));
    $systemFilter = " AND ms.system_id IN ({$systemPlaceholders})";
    $systemParams = $allowedSystemScope;
  } else {
    $systemFilter = ' AND 1=0';
  }
}
if (!$hasAllowlist || $systemFilter !== ' AND 1=0') {
  $systemRows = $db->select(
    "SELECT ms.system_id,
            COALESCE(ms.system_name, s.system_name) AS system_name
       FROM map_system ms
       LEFT JOIN eve_system s ON s.system_id = ms.system_id
      WHERE COALESCE(ms.system_name, s.system_name) LIKE ?
        {$systemFilter}
      ORDER BY system_name
      LIMIT ?",
    array_merge([$likePrefix], $systemParams, [$limit])
  );
  foreach ($systemRows as $row) {
    $systemName = trim((string)($row['system_name'] ?? ''));
    if ($systemName === '') {
      continue;
    }
    $score = $matchScore($systemName, '');
    if ($score === null) {
      continue;
    }
    $addItem([
      'name' => $systemName,
      'label' => 'System',
      'location_type' => 'system',
      'location_id' => (int)($row['system_id'] ?? 0),
      'location_name' => $systemName,
      'system_id' => (int)($row['system_id'] ?? 0),
      'system_name' => $systemName,
    ], $score, false);
  }
}

$items = array_slice($items, 0, $limit);
foreach ($items as &$item) {
  unset($item['sort_score'], $item['sort_pinned'], $item['sort_system'], $item['sort_name']);
}
unset($item);

api_send_json([
  'ok' => true,
  'items' => $items,
]);
