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

$resp = $services['esi_client']->get('/v2/search/', [
  'categories' => 'alliance',
  'search' => $query,
  'strict' => 'false',
], null, 300);

if (!$resp['ok'] || !is_array($resp['json'])) {
  api_send_json(['ok' => false, 'error' => 'ESI search failed'], 502);
}

$ids = $resp['json']['alliance'] ?? [];
if (!is_array($ids) || $ids === []) {
  api_send_json(['ok' => true, 'alliances' => []]);
}

$alliances = [];
foreach (array_slice($ids, 0, 10) as $allianceId) {
  $allianceId = (int)$allianceId;
  if ($allianceId <= 0) {
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
