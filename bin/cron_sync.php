#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/cron_sync.php
 *
 * Usage:
 *   php bin/cron_sync.php <corp_id> <character_id_with_token>
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

if ($argc < 3) {
  fwrite(STDERR, "Usage: php bin/cron_sync.php <corp_id> <character_id>\n");
  exit(2);
}

$corpId = (int)$argv[1];
$charId = (int)$argv[2];

try {
  $esiClient = new EsiClient($db, $config);
  $sso = new SsoService($db, $config);
  $universe = new UniverseDataService($db, $config, $esiClient, $sso);

  /** @var EsiService $esi */
  $esi = $services['esi'];

  $results = [
    'universe' => $universe->syncUniverse(),
  ];

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
