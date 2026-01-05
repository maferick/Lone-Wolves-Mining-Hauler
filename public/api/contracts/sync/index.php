<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Db\Db;
use App\Services\ContractMatchService;
use App\Services\EsiService;

api_require_key();

$payload = api_read_json();
$corpId = (int)($payload['corp_id'] ?? ($_GET['corp_id'] ?? 0));
$charId = (int)($payload['character_id'] ?? ($_GET['character_id'] ?? 0));

if ($corpId <= 0) {
  api_send_json(['ok' => false, 'error' => 'corp_id is required'], 400);
}

if ($charId <= 0) {
  $setting = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
    ['cid' => $corpId]
  );
  $settingJson = $setting ? Db::jsonDecode((string)$setting['setting_json'], []) : [];
  $charId = (int)($settingJson['character_id'] ?? 0);
}

if ($charId <= 0) {
  api_send_json(['ok' => false, 'error' => 'No cron character configured for contract sync.'], 400);
}

/** @var EsiService $esi */
$esi = $services['esi'];

try {
  $pullResult = $db->tx(fn($db) => $esi->contracts()->pull($corpId, $charId));
} catch (Throwable $e) {
  api_send_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

$reconcileResult = $esi->contractReconcile()->reconcile($corpId);
$matcher = new ContractMatchService(
  $db,
  $config,
  $services['discord_webhook'] ?? null,
  $services['discord_events'] ?? null
);
$matchResult = $matcher->matchOpenRequests($corpId);

api_send_json([
  'ok' => true,
  'pull' => $pullResult,
  'reconcile' => $reconcileResult,
  'match' => $matchResult,
]);
