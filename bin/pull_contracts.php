#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/pull_contracts.php
 *
 * Usage:
 *   php bin/pull_contracts.php <corp_id> <character_id_with_token>
 *
 * Prereqs:
 * - .env contains DB + EVE_CLIENT_ID/EVE_CLIENT_SECRET
 * - sso_token row exists for (corp_id, owner_type=character, owner_id=<character_id>)
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Services\EsiService;

if ($argc < 3) {
  fwrite(STDERR, "Usage: php bin/pull_contracts.php <corp_id> <character_id>\n");
  exit(2);
}

$corpId = (int)$argv[1];
$charId = (int)$argv[2];

try {
  /** @var EsiService $esi */
  $esi = $services['esi'];
  $result = $db->tx(fn($db) => $esi->contracts()->pull($corpId, $charId));
  echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(0);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
  exit(1);
}
