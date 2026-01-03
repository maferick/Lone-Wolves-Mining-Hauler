#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/check_cron_contracts.php
 *
 * Usage:
 *   php bin/check_cron_contracts.php <corp_id> [--character_id=123] [--limit=10]
 *
 * Pulls corp contracts using the cron-configured character (or provided character_id)
 * and prints a summary of the latest contracts pulled.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Db\Db;
use App\Services\EsiService;

if ($argc < 2) {
  fwrite(STDERR, "Usage: php bin/check_cron_contracts.php <corp_id> [--character_id=123] [--limit=10]\n");
  exit(2);
}

$corpId = (int)$argv[1];
$charId = 0;
$limit = 10;

for ($i = 2; $i < $argc; $i++) {
  $arg = $argv[$i];
  if (str_starts_with($arg, '--character_id=')) {
    $charId = (int)substr($arg, strlen('--character_id='));
    continue;
  }
  if (str_starts_with($arg, '--limit=')) {
    $limit = (int)substr($arg, strlen('--limit='));
  }
}

$limit = max(1, min(100, $limit));

if ($charId <= 0) {
  $setting = $db->one(
    "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'esi.cron' LIMIT 1",
    ['cid' => $corpId]
  );
  $settingJson = $setting ? Db::jsonDecode((string)$setting['setting_json'], []) : [];
  $charId = (int)($settingJson['character_id'] ?? 0);
}

if ($charId <= 0) {
  fwrite(STDERR, "No cron character configured. Provide --character_id or set in admin panel.\n");
  exit(2);
}

try {
  /** @var EsiService $esi */
  $esi = $services['esi'];
  $pullResult = $db->tx(fn($db) => $esi->contracts()->pull($corpId, $charId));
  $reconcileResult = $esi->contractReconcile()->reconcile($corpId);

  $latestContracts = $db->select(
    "SELECT contract_id, type, status, title, availability, date_issued, date_expired,
            start_location_id, end_location_id, reward_isk, collateral_isk, volume_m3, last_fetched_at
       FROM esi_corp_contract
      WHERE corp_id = :cid
      ORDER BY date_issued DESC, contract_id DESC
      LIMIT {$limit}",
    ['cid' => $corpId]
  );

  $summary = $db->one(
    "SELECT COUNT(*) AS total_contracts,
            SUM(CASE WHEN status = 'outstanding' THEN 1 ELSE 0 END) AS outstanding,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) AS finished,
            MAX(last_fetched_at) AS last_fetched_at
       FROM esi_corp_contract
      WHERE corp_id = :cid",
    ['cid' => $corpId]
  ) ?: [];

  echo json_encode([
    'ok' => true,
    'pull' => $pullResult,
    'summary' => $summary,
    'latest_contracts' => $latestContracts,
    'reconcile' => $reconcileResult,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(0);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(1);
}
