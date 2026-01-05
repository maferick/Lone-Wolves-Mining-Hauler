<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/NpcStructureSyncService.php
 *
 * Pulls NPC station data from the SDE and caches names in eve_station/eve_entity.
 */
final class NpcStructureSyncService
{
  public function __construct(private Db $db, private array $config) {}

  public function syncNpcStructures(?callable $progress = null): array
  {
    $startedAt = gmdate('c');
    if ($progress !== null) {
      $progress([
        'current' => 0,
        'total' => 1,
        'label' => 'Loading NPC structures',
        'stage' => 'npc_structures',
      ]);
    }

    $esiClient = new EsiClient($this->db, $this->config);
    $sso = new SsoService($this->db, $this->config);
    $universe = new UniverseDataService($this->db, $this->config, $esiClient, $sso);
    $universe->setForceSde(true);
    $result = $universe->syncNpcStations();

    if ($progress !== null) {
      $progress([
        'current' => 1,
        'total' => 1,
        'label' => 'NPC structures synced',
        'stage' => 'npc_structures',
      ]);
    }

    return array_merge($result, [
      'started_at' => $startedAt,
      'finished_at' => gmdate('c'),
    ]);
  }
}
