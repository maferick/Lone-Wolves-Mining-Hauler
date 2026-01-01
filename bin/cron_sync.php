#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/cron_sync.php
 *
 * Usage:
 *   php bin/cron_sync.php <corp_id> [character_id_with_token]
 *
 * Syncs:
 * - Universe regions/constellations/systems
 * - Corp structures (requires esi-structures.read_corporation_structures.v1)
 * - Corp contracts (requires esi-contracts.read_corporation_contracts.v1)
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Services\EsiClient;
use App\Services\EsiService;
use App\Services\SsoService;
use App\Services\UniverseDataService;

if ($argc < 2) {
  fwrite(STDERR, "Usage: php bin/cron_sync.php <corp_id> [character_id]\n");
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
  $esiClient = new EsiClient($db, $config);
  $sso = new SsoService($db, $config);
  $universe = new UniverseDataService($db, $config, $esiClient, $sso);

  /** @var EsiService $esi */
  $esi = $services['esi'];

  $results = [
    'universe' => $universe->syncUniverse(),
    'token_refresh' => $sso->refreshCorpTokens($corpId),
  ];

  $graphEdgeCount = (int)$db->fetchValue("SELECT COUNT(*) FROM map_edge");
  $graphSystemCount = (int)$db->fetchValue("SELECT COUNT(*) FROM map_system");
  if ($graphEdgeCount === 0 || $graphSystemCount === 0) {
    $results['stargate_graph'] = $universe->syncStargateGraph();
  } else {
    $results['stargate_graph'] = [
      'skipped' => true,
      'map_system_count' => $graphSystemCount,
      'map_edge_count' => $graphEdgeCount,
    ];
  }

  try {
    $results['structures'] = $universe->syncCorpStructures($corpId, $charId);
  } catch (Throwable $e) {
    $results['structures_error'] = $e->getMessage();
  }

  $results['contracts'] = $db->tx(fn($db) => $esi->contracts()->pull($corpId, $charId));

  echo json_encode(['ok' => true, 'result' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(0);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(1);
}
