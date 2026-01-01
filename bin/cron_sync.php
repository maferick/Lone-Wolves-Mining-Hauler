#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/cron_sync.php
 *
 * Usage:
 *   php bin/cron_sync.php <corp_id> [character_id_with_token] [--force] [--scope=all|universe|sde] [--sde]
 *
 * Syncs:
 * - Universe regions/constellations/systems
 * - Corp structures (requires esi-corporations.read_structures.v1)
 * - Corp contracts (requires esi-contracts.read_corporation_contracts.v1)
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Services\CronSyncService;
use App\Services\EsiService;

if ($argc < 2) {
  fwrite(STDERR, "Usage: php bin/cron_sync.php <corp_id> [character_id] [--force] [--scope=all|universe|sde] [--sde]\n");
  exit(2);
}

$corpId = (int)$argv[1];
$charId = $argc >= 3 ? (int)$argv[2] : 0;
$force = in_array('--force', $argv, true) || in_array('--full', $argv, true);
$scope = 'all';
$useSde = in_array('--sde', $argv, true);

foreach ($argv as $arg) {
  if ($arg === '--init-universe' || $arg === '--universe') {
    $scope = 'universe';
    continue;
  }
  if (str_starts_with($arg, '--scope=')) {
    $scope = substr($arg, strlen('--scope='));
  }
}

if ($scope === 'sde') {
  $scope = 'universe';
  $useSde = true;
}

if (!in_array($scope, ['all', 'universe'], true)) {
  fwrite(STDERR, "Invalid scope. Allowed values: all, universe, sde.\n");
  exit(2);
}

if ($charId <= 0 && $scope !== 'universe') {
  $setting = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
    ['cid' => $corpId]
  );
  $settingJson = $setting ? \App\Db\Db::jsonDecode($setting['setting_json'], []) : [];
  $charId = (int)($settingJson['character_id'] ?? 0);
}

if ($charId <= 0 && $scope !== 'universe') {
  fwrite(STDERR, "No cron character configured. Provide character_id or set in admin panel.\n");
  exit(2);
}

try {
  /** @var EsiService $esi */
  $esi = $services['esi'];
  $cron = new CronSyncService($db, $config, $esi);
  $results = $cron->run($corpId, $charId, [
    'force' => $force,
    'scope' => $scope,
    'sde' => $useSde,
  ]);

  echo json_encode(['ok' => true, 'result' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(0);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(1);
}
