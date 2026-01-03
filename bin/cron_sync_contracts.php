#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/cron_sync_contracts.php
 *
 * Usage:
 *   php bin/cron_sync_contracts.php <corp_id> [character_id_with_token]
 *
 * Pulls corp courier contracts from ESI and matches them against open haul requests.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Services\ContractMatchService;
use App\Services\EsiService;

if ($argc < 2) {
  fwrite(STDERR, "Usage: php bin/cron_sync_contracts.php <corp_id> [character_id]\n");
  exit(2);
}

$corpId = (int)$argv[1];
$charId = $argc >= 3 ? (int)$argv[2] : 0;

if ($charId <= 0) {
  $setting = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
    ['cid' => $corpId]
  );
  $settingJson = $setting ? \App\Db\Db::jsonDecode($setting['setting_json'], []) : [];
  $charId = (int)($settingJson['character_id'] ?? 0);
}

if ($charId <= 0) {
  fwrite(STDERR, "No cron character configured. Provide character_id or set in admin panel.\n");
  exit(2);
}

try {
  /** @var EsiService $esi */
  $esi = $services['esi'];
  $pullResult = $db->tx(fn($db) => $esi->contracts()->pull($corpId, $charId));
  $reconcileResult = $esi->contractReconcile()->reconcile($corpId);

  $matcher = new ContractMatchService($db, $config, $services['discord_webhook'] ?? null);
  $matchResult = $matcher->matchOpenRequests($corpId);

  echo json_encode([
    'ok' => true,
    'pull' => $pullResult,
    'reconcile' => $reconcileResult,
    'match' => $matchResult,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(0);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(1);
}
