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

if ($db === null || !isset($services['esi_client'], $services['eve_public'])) {
  api_send_json(['ok' => false, 'error' => 'service unavailable'], 503);
}

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '' || mb_strlen($query) < 2) {
  api_send_json(['ok' => true, 'alliances' => []]);
}

$alliances = [];
$seenIds = [];

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
  $alliances[] = ['id' => $allianceId, 'name' => $name];
  $seenIds[$allianceId] = true;
}

if (count($alliances) >= 10 || mb_strlen($query) < 3) {
  api_send_json(['ok' => true, 'alliances' => $alliances]);
}

$resp = $services['esi_client']->get('/v2/search/', [
  'categories' => 'alliance',
  'search' => $query,
  'strict' => 'false',
], null, 300);

if (!$resp['ok'] || !is_array($resp['json'])) {
  api_send_json([
    'ok' => true,
    'alliances' => $alliances,
    'warning' => 'ESI search failed',
  ]);
}

$ids = $resp['json']['alliance'] ?? [];
if (!is_array($ids) || $ids === []) {
  api_send_json(['ok' => true, 'alliances' => $alliances]);
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
      $alliances[] = ['id' => $allianceId, 'name' => $name];
    }
  } catch (Throwable $e) {
    continue;
  }
}

usort($alliances, static fn($a, $b) => strcmp($a['name'], $b['name']));

api_send_json(['ok' => true, 'alliances' => $alliances]);
