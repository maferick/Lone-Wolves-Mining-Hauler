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

if ($db === null || !isset($services['esi_client'], $services['eve_public'])) {
  api_send_json(['ok' => false, 'error' => 'service unavailable'], 503);
}

$query = trim((string)($_GET['q'] ?? ''));
$debug = ($_GET['debug'] ?? '') === '1';
if ($query === '' || mb_strlen($query) < 2) {
  api_send_json(['ok' => true, 'alliances' => []]);
}

$alliances = [];
$seenIds = [];
$warning = null;
$debugInfo = [
  'query' => $query,
  'local_count' => 0,
  'esi_count' => 0,
  'final_count' => 0,
];

$localRows = $db->select(
  "SELECT entity_id, name
     FROM eve_entity
    WHERE entity_type = 'alliance'
      AND name LIKE ?
    ORDER BY name
    LIMIT 10",
  [$query . '%']
);
foreach ($localRows as $row) {
  $allianceId = (int)($row['entity_id'] ?? 0);
  $name = trim((string)($row['name'] ?? ''));
  if ($allianceId <= 0 || $name === '') {
    continue;
  }
  $alliances[] = ['id' => $allianceId, 'name' => $name, 'source' => 'cache'];
  $seenIds[$allianceId] = true;
}
$debugInfo['local_count'] = count($alliances);

if (count($alliances) >= 10 || mb_strlen($query) < 3) {
  $debugInfo['final_count'] = count($alliances);
  if ($debug) {
    error_log('Alliance search debug: ' . json_encode($debugInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }
  $payload = ['ok' => true, 'alliances' => $alliances];
  if ($debug) {
    $payload['debug'] = $debugInfo;
  }
  api_send_json($payload);
}

$resp = $services['esi_client']->get('/latest/search/', [
  'categories' => 'alliance',
  'search' => $query,
  'strict' => 'false',
], null, 300);

if (!$resp['ok'] || !is_array($resp['json'])) {
  $warning = 'ESI search failed; showing cached results.';
  $debugInfo['final_count'] = count($alliances);
  if ($debug) {
    $debugInfo['esi_error'] = $resp['error'] ?? null;
    error_log('Alliance search debug: ' . json_encode($debugInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }
  $payload = [
    'ok' => true,
    'alliances' => $alliances,
    'warning' => $warning,
  ];
  if ($debug) {
    $payload['debug'] = $debugInfo;
  }
  api_send_json($payload);
}

$ids = $resp['json']['alliance'] ?? [];
if (!is_array($ids) || $ids === []) {
  $debugInfo['final_count'] = count($alliances);
  if ($debug) {
    error_log('Alliance search debug: ' . json_encode($debugInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }
  $payload = ['ok' => true, 'alliances' => $alliances];
  if ($debug) {
    $payload['debug'] = $debugInfo;
  }
  api_send_json($payload);
}

$limit = max(0, 10 - count($alliances));
foreach (array_slice($ids, 0, $limit) as $allianceId) {
  $allianceId = (int)$allianceId;
  if ($allianceId <= 0 || isset($seenIds[$allianceId])) {
    continue;
  }
  try {
    $data = $services['eve_public']->alliance($allianceId);
    $name = trim((string)($data['name'] ?? ''));
    if ($name !== '') {
      $alliances[] = ['id' => $allianceId, 'name' => $name, 'source' => 'esi'];
    }
  } catch (Throwable $e) {
    continue;
  }
}

$debugInfo['esi_count'] = max(0, count($alliances) - $debugInfo['local_count']);
$indexed = [];
foreach ($alliances as $idx => $row) {
  $row['_idx'] = $idx;
  $indexed[] = $row;
}
usort($indexed, static function(array $a, array $b): int {
  $nameCmp = strcasecmp($a['name'], $b['name']);
  if ($nameCmp !== 0) {
    return $nameCmp;
  }
  return $a['_idx'] <=> $b['_idx'];
});
$alliances = array_slice(array_map(static function(array $row): array {
  unset($row['_idx']);
  return $row;
}, $indexed), 0, 10);
$debugInfo['final_count'] = count($alliances);

if ($debug) {
  error_log('Alliance search debug: ' . json_encode($debugInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$payload = ['ok' => true, 'alliances' => $alliances];
if ($warning !== null) {
  $payload['warning'] = $warning;
}
if ($debug) {
  $payload['debug'] = $debugInfo;
}
api_send_json($payload);
