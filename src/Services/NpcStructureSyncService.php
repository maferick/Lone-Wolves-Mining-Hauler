<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/NpcStructureSyncService.php
 *
 * Resolves NPC station data from ESI and caches names in eve_station/eve_entity.
 */
final class NpcStructureSyncService
{
  private const DEFAULT_BATCH_LIMIT = 500;
  private const DEFAULT_TTL_SECONDS = 86400;
  private const DEFAULT_MAX_RUNTIME_SECONDS = 240;
  private const DEFAULT_MAX_UPSTREAM_FAILURES = 25;

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
    $batchLimit = (int)($this->config['npc_structures']['batch_limit'] ?? self::DEFAULT_BATCH_LIMIT);
    $ttlSeconds = (int)($this->config['npc_structures']['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS);
    $maxRuntimeSeconds = (int)($this->config['npc_structures']['max_runtime_seconds'] ?? self::DEFAULT_MAX_RUNTIME_SECONDS);
    $maxUpstreamFailures = (int)($this->config['npc_structures']['max_upstream_failures'] ?? self::DEFAULT_MAX_UPSTREAM_FAILURES);

    $batchLimit = max(1, min($batchLimit, 1000));
    $maxRuntimeSeconds = max(30, $maxRuntimeSeconds);
    $maxUpstreamFailures = max(1, $maxUpstreamFailures);

    $stationRows = $this->db->select(
      "SELECT station_id
         FROM eve_station
        WHERE station_name LIKE 'Station %'
           OR station_type_id IS NULL
        ORDER BY updated_at ASC
        LIMIT {$batchLimit}"
    );

    $total = count($stationRows);
    $attempted = 0;
    $resolved = 0;
    $skipped = 0;
    $failed = 0;
    $upstreamFailures = 0;

    $startedTs = time();

    foreach ($stationRows as $row) {
      $attempted++;
      if ((time() - $startedTs) > $maxRuntimeSeconds) {
        throw new \RuntimeException("NPC station sync exceeded time limit after {$attempted} attempts.");
      }

      $stationId = (int)($row['station_id'] ?? 0);
      if ($stationId <= 0) {
        $skipped++;
        continue;
      }

      $resp = $this->fetchStation($esiClient, $stationId, $ttlSeconds);
      if ($resp['ok'] && is_array($resp['json'])) {
        $data = $resp['json'];
        $name = trim((string)($data['name'] ?? ''));
        $systemId = (int)($data['system_id'] ?? 0);
        $typeId = isset($data['type_id']) ? (int)$data['type_id'] : null;

        if ($name === '' || $systemId <= 0) {
          $skipped++;
        } else {
          $this->db->execute(
            "INSERT INTO eve_station (station_id, system_id, station_name, station_type_id)
             VALUES (:station_id, :system_id, :station_name, :station_type_id)
             ON DUPLICATE KEY UPDATE
              system_id=VALUES(system_id),
              station_name=VALUES(station_name),
              station_type_id=VALUES(station_type_id),
              updated_at=UTC_TIMESTAMP()",
            [
              'station_id' => $stationId,
              'system_id' => $systemId,
              'station_name' => $name,
              'station_type_id' => $typeId,
            ]
          );
          $this->db->upsertEntity($stationId, 'station', $name, $data, null, 'esi');
          $resolved++;
        }
      } else {
        $status = (int)($resp['status'] ?? 0);
        if ($status === 404) {
          $skipped++;
        } else {
          $failed++;
          if ($status === 0 || $status >= 500 || in_array($status, [420, 429], true)) {
            $upstreamFailures++;
          }
        }
      }

      if ($progress !== null) {
        $progress([
          'current' => $attempted,
          'total' => $total,
          'label' => "Resolving NPC stations ({$attempted}/{$total})",
          'stage' => 'npc_structures',
        ]);
      }

      if ($upstreamFailures >= $maxUpstreamFailures) {
        throw new \RuntimeException("NPC station sync aborted after {$upstreamFailures} upstream failures.");
      }
    }

    $result = [
      'attempted' => $attempted,
      'resolved' => $resolved,
      'skipped' => $skipped,
      'failed' => $failed,
      'upstream_failures' => $upstreamFailures,
      'source' => 'esi',
    ];

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

  private function fetchStation(EsiClient $esiClient, int $stationId, int $ttlSeconds): array
  {
    $resp = $esiClient->get("/latest/universe/stations/{$stationId}/", null, null, $ttlSeconds);
    $status = (int)($resp['status'] ?? 0);

    if (in_array($status, [420, 429], true) || $status >= 500) {
      EsiRateLimiter::sleepForRetry($resp['headers'] ?? [], $this->config);
      $resp = $esiClient->get("/latest/universe/stations/{$stationId}/", null, null, $ttlSeconds);
    }

    return $resp;
  }
}
