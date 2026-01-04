<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../../src/bootstrap.php';

api_require_key();

if ($db === null) {
  api_send_json([
    'ok' => false,
    'error' => 'database_unavailable',
  ], 503);
}

$prefix = trim((string)($_GET['prefix'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? 'pickup')));
$limit = (int)($_GET['limit'] ?? 30);

if (!in_array($type, ['pickup', 'destination'], true)) {
  $type = 'pickup';
}

if ($limit < 20) {
  $limit = 20;
} elseif ($limit > 50) {
  $limit = 50;
}

if (mb_strlen($prefix) < 3) {
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
$allowedSystemIds = array_values(array_filter($allowedSystemIds));
$allowedRegionIds = array_values(array_filter($allowedRegionIds));
$hasAccessAllowlist = !empty($allowedSystemIds) || !empty($allowedRegionIds);

$systemRows = $db->select(
  "SELECT system_id, system_name, region_id FROM map_system WHERE system_name LIKE ? ORDER BY system_name LIMIT ?",
  [$prefix . '%', $limit * 2]
);
if (!$systemRows) {
  $systemRows = $db->select(
    "SELECT s.system_id, s.system_name, c.region_id
       FROM eve_system s
       JOIN eve_constellation c ON c.constellation_id = s.constellation_id
      WHERE s.system_name LIKE ?
      ORDER BY s.system_name
      LIMIT ?",
    [$prefix . '%', $limit * 2]
  );
}

$items = [];
foreach ($systemRows as $row) {
  $systemId = (int)($row['system_id'] ?? 0);
  $name = trim((string)($row['system_name'] ?? ''));
  if ($name === '') {
    continue;
  }
  if ($hasAccessAllowlist) {
    $regionId = (int)($row['region_id'] ?? 0);
    $allowed = in_array($systemId, $allowedSystemIds, true)
      || in_array($regionId, $allowedRegionIds, true);
    if (!$allowed) {
      continue;
    }
  }
  $items[] = ['name' => $name, 'label' => 'System'];
  if (count($items) >= $limit) {
    break;
  }
}

if ($allowStructureLocations && count($items) < $limit) {
  $structureRules = $accessRules['structures'] ?? [];
  $structureIds = [];
  foreach ($structureRules as $rule) {
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
      $structureIds[] = $id;
    }
  }

  $structureNameById = [];
  if ($structureIds) {
    $structureIds = array_values(array_unique($structureIds));
    $placeholders = implode(',', array_fill(0, count($structureIds), '?'));
    $structureRows = $db->select(
      "SELECT structure_id, structure_name FROM eve_structure WHERE structure_id IN ($placeholders)",
      $structureIds
    );
    foreach ($structureRows as $row) {
      $structureNameById[(int)$row['structure_id']] = (string)$row['structure_name'];
    }
  }

  $prefixLower = mb_strtolower($prefix);
  foreach ($structureRules as $rule) {
    if (empty($rule['allowed'])) {
      continue;
    }
    if ($type === 'pickup' && empty($rule['pickup_allowed'])) {
      continue;
    }
    if ($type === 'destination' && empty($rule['delivery_allowed'])) {
      continue;
    }
    $name = trim((string)($rule['name'] ?? ''));
    $id = (int)($rule['id'] ?? 0);
    if ($id > 0 && !empty($structureNameById[$id])) {
      $name = (string)$structureNameById[$id];
    }
    if ($name === '') {
      continue;
    }
    if (!str_starts_with(mb_strtolower($name), $prefixLower)) {
      continue;
    }
    $items[] = ['name' => $name, 'label' => 'Structure'];
    if (count($items) >= $limit) {
      break;
    }
  }
}

if ($allowStructureLocations && count($items) < $limit) {
  $stationRows = $db->select(
    "SELECT st.station_id, st.station_name, st.system_id,
            COALESCE(ms.region_id, c.region_id) AS region_id
       FROM eve_station st
       LEFT JOIN map_system ms ON ms.system_id = st.system_id
       LEFT JOIN eve_system s ON s.system_id = st.system_id
       LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
      WHERE st.station_name LIKE ?
      ORDER BY st.station_name
      LIMIT ?",
    [$prefix . '%', $limit * 2]
  );
  foreach ($stationRows as $row) {
    $stationName = trim((string)($row['station_name'] ?? ''));
    if ($stationName === '') {
      continue;
    }
    if ($hasAccessAllowlist) {
      $systemId = (int)($row['system_id'] ?? 0);
      $regionId = (int)($row['region_id'] ?? 0);
      $allowed = in_array($systemId, $allowedSystemIds, true)
        || in_array($regionId, $allowedRegionIds, true);
      if (!$allowed) {
        continue;
      }
    }
    $items[] = ['name' => $stationName, 'label' => 'Station'];
    if (count($items) >= $limit) {
      break;
    }
  }
}

api_send_json([
  'ok' => true,
  'items' => $items,
]);
