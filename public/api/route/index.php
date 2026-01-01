<?php
declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Db\Db;

api_require_key();

if ($db === null || !isset($services['route'])) {
  api_send_json([
    'ok' => false,
    'error' => 'database_unavailable',
  ], 503);
}

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$profile = (string)($_GET['profile'] ?? '');

if ($from === '' || $to === '') {
  api_send_json(['ok' => false, 'error' => 'from and to are required'], 400);
}

$corpId = (int)($authCtx['corp_id'] ?? 0);

$defaultProfile = 'shortest';
$settingRow = $db->one(
  "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.default_profile' LIMIT 1",
  ['cid' => $corpId]
);
if ($settingRow === null && $corpId !== 0) {
  $settingRow = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.default_profile' LIMIT 1"
  );
}
if ($settingRow && !empty($settingRow['setting_json'])) {
  $decoded = Db::jsonDecode((string)$settingRow['setting_json'], null);
  if (is_array($decoded) && isset($decoded['profile'])) {
    $defaultProfile = (string)$decoded['profile'];
  } elseif (is_string($decoded)) {
    $defaultProfile = $decoded;
  }
}

$profile = $profile !== '' ? $profile : $defaultProfile;

try {
  /** @var \App\Services\RouteService $routeService */
  $routeService = $services['route'];
  $route = $routeService->findRoute($from, $to, $profile, [
    'corp_id' => $authCtx['corp_id'] ?? null,
    'actor_user_id' => $authCtx['user_id'] ?? null,
    'actor_character_id' => $authCtx['character_id'] ?? null,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  api_send_json([
    'ok' => true,
    'route' => $route,
  ]);
} catch (Throwable $e) {
  api_send_json([
    'ok' => false,
    'error' => $e->getMessage(),
  ], 400);
}
