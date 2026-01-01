<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/UniverseDataService.php
 *
 * Hydrate static universe data (regions, constellations, systems)
 * and corp structures for access-rule typeahead.
 */
final class UniverseDataService
{
  private const SDE_LATEST_JSONL_URL = 'https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip';
  private const SDE_META_FILENAME = 'sde_meta.json';
  private const SDE_UPDATE_CHECK_SECONDS = 21600;

  private bool $forceSde = false;

  public function __construct(
    private Db $db,
    private array $config,
    private EsiClient $esi,
    private SsoService $sso
  ) {}

  public function syncUniverse(int $ttlSeconds = 86400): array
  {
    if ($this->isSdeEnabled()) {
      $this->ensureSdeUniverseFiles();
      return $this->syncUniverseFromSde();
    }
    $regionIds = $this->fetchIdList('/v1/universe/regions/', $ttlSeconds);
    $regionUpserts = 0;
    foreach ($regionIds as $regionId) {
      $region = $this->fetchJson("/v1/universe/regions/{$regionId}/", $ttlSeconds);
      if (!$region) continue;
      $name = trim((string)($region['name'] ?? ''));
      if ($name === '') continue;
      $this->db->execute(
        "INSERT INTO eve_region (region_id, region_name)
         VALUES (:region_id, :region_name)
         ON DUPLICATE KEY UPDATE region_name=VALUES(region_name)",
        [
          'region_id' => (int)$regionId,
          'region_name' => $name,
        ]
      );
      $regionUpserts++;
    }

    $constellationIds = $this->fetchIdList('/v1/universe/constellations/', $ttlSeconds);
    $constellationUpserts = 0;
    foreach ($constellationIds as $constellationId) {
      $constellation = $this->fetchJson("/v1/universe/constellations/{$constellationId}/", $ttlSeconds);
      if (!$constellation) continue;
      $name = trim((string)($constellation['name'] ?? ''));
      $regionId = (int)($constellation['region_id'] ?? 0);
      if ($name === '' || $regionId <= 0) continue;
      $this->db->execute(
        "INSERT INTO eve_constellation (constellation_id, region_id, constellation_name)
         VALUES (:constellation_id, :region_id, :constellation_name)
         ON DUPLICATE KEY UPDATE
           region_id=VALUES(region_id),
           constellation_name=VALUES(constellation_name)",
        [
          'constellation_id' => (int)$constellationId,
          'region_id' => $regionId,
          'constellation_name' => $name,
        ]
      );
      $constellationUpserts++;
    }

    $systemIds = $this->fetchIdList('/v1/universe/systems/', $ttlSeconds);
    $systemUpserts = 0;
    foreach ($systemIds as $systemId) {
      $system = $this->fetchJson("/v4/universe/systems/{$systemId}/", $ttlSeconds);
      if (!$system) continue;
      $name = trim((string)($system['name'] ?? ''));
      $constellationId = (int)($system['constellation_id'] ?? 0);
      if ($name === '' || $constellationId <= 0) continue;
      $securityStatus = isset($system['security_status']) ? (float)$system['security_status'] : 0.0;
      $hasSecurity = isset($system['security_status']) ? 1 : 0;
      $this->db->execute(
        "INSERT INTO eve_system (system_id, constellation_id, system_name, security_status, has_security)
         VALUES (:system_id, :constellation_id, :system_name, :security_status, :has_security)
         ON DUPLICATE KEY UPDATE
           constellation_id=VALUES(constellation_id),
           system_name=VALUES(system_name),
           security_status=VALUES(security_status),
           has_security=VALUES(has_security)",
        [
          'system_id' => (int)$systemId,
          'constellation_id' => $constellationId,
          'system_name' => $name,
          'security_status' => $securityStatus,
          'has_security' => $hasSecurity,
        ]
      );
      $systemUpserts++;
    }

    return [
      'regions' => ['fetched' => count($regionIds), 'upserted' => $regionUpserts],
      'constellations' => ['fetched' => count($constellationIds), 'upserted' => $constellationUpserts],
      'systems' => ['fetched' => count($systemIds), 'upserted' => $systemUpserts],
    ];
  }

  public function syncCorpStructures(int $corpId, int $characterOwnerId, int $ttlSeconds = 900): array
  {
    $token = $this->sso->getToken('character', $corpId, $characterOwnerId);
    if (!$token) {
      throw new \RuntimeException("No sso_token found for corp_id={$corpId}, character_id={$characterOwnerId}");
    }
    $token = $this->sso->ensureAccessToken($token);
    $bearer = $token['access_token'];

    $resp = $this->esiAuthedGet(
      $bearer,
      "/v4/corporations/{$corpId}/structures/",
      null,
      $corpId,
      $ttlSeconds
    );

    if (!$resp['ok']) {
      throw new \RuntimeException("ESI corp structures pull failed: HTTP {$resp['status']}");
    }

    $structures = is_array($resp['json']) ? $resp['json'] : [];
    $upserted = 0;
    foreach ($structures as $structure) {
      $structureId = (int)($structure['structure_id'] ?? 0);
      $systemId = (int)($structure['system_id'] ?? 0);
      $name = trim((string)($structure['name'] ?? ''));
      $typeId = isset($structure['type_id']) ? (int)$structure['type_id'] : null;
      if ($structureId <= 0 || $systemId <= 0 || $name === '') continue;

      $this->db->execute(
        "INSERT INTO eve_structure
          (structure_id, system_id, structure_name, owner_corp_id, type_id, is_public, last_fetched_at)
         VALUES
          (:structure_id, :system_id, :structure_name, :owner_corp_id, :type_id, 0, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
          system_id=VALUES(system_id),
          structure_name=VALUES(structure_name),
          owner_corp_id=VALUES(owner_corp_id),
          type_id=VALUES(type_id),
          last_fetched_at=UTC_TIMESTAMP()",
        [
          'structure_id' => $structureId,
          'system_id' => $systemId,
          'structure_name' => $name,
          'owner_corp_id' => $corpId,
          'type_id' => $typeId,
        ]
      );
      $this->db->upsertEntity($structureId, 'structure', $name, $structure, null, 'esi');
      $upserted++;
    }

    return [
      'corp_id' => $corpId,
      'fetched' => count($structures),
      'upserted' => $upserted,
    ];
  }

  public function syncPublicStructures(int $corpId, int $characterOwnerId, int $ttlSeconds = 86400): array
  {
    $token = $this->sso->getToken('character', $corpId, $characterOwnerId);
    if (!$token) {
      throw new \RuntimeException("No sso_token found for corp_id={$corpId}, character_id={$characterOwnerId}");
    }
    $token = $this->sso->ensureAccessToken($token);
    $bearer = $token['access_token'];

    $structureIds = $this->fetchPublicStructureIds($bearer, $corpId, $ttlSeconds);
    $fetched = 0;
    $upserted = 0;
    foreach ($structureIds as $structureId) {
      $structureId = (int)$structureId;
      if ($structureId <= 0) continue;
      $resp = $this->esiAuthedGet(
        $bearer,
        "/v2/universe/structures/{$structureId}/",
        null,
        $corpId,
        $ttlSeconds
      );
      $fetched++;
      if (!$resp['ok'] || !is_array($resp['json'])) {
        continue;
      }
      $data = $resp['json'];
      $name = trim((string)($data['name'] ?? ''));
      $systemId = (int)($data['solar_system_id'] ?? 0);
      $typeId = isset($data['type_id']) ? (int)$data['type_id'] : null;
      if ($name === '' || $systemId <= 0) {
        continue;
      }

      $this->db->execute(
        "INSERT INTO eve_structure
          (structure_id, system_id, structure_name, owner_corp_id, type_id, is_public, last_fetched_at)
         VALUES
          (:structure_id, :system_id, :structure_name, NULL, :type_id, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
          system_id=VALUES(system_id),
          structure_name=VALUES(structure_name),
          type_id=VALUES(type_id),
          owner_corp_id=COALESCE(owner_corp_id, VALUES(owner_corp_id)),
          is_public=IF(owner_corp_id IS NULL, VALUES(is_public), is_public),
          last_fetched_at=UTC_TIMESTAMP()",
        [
          'structure_id' => $structureId,
          'system_id' => $systemId,
          'structure_name' => $name,
          'type_id' => $typeId,
        ]
      );
      $this->db->upsertEntity($structureId, 'structure', $name, $data, null, 'esi');
      $upserted++;
    }

    return [
      'corp_id' => $corpId,
      'fetched' => $fetched,
      'upserted' => $upserted,
      'ids' => count($structureIds),
    ];
  }

  public function syncStargateGraph(int $ttlSeconds = 86400, bool $truncate = true): array
  {
    if ($this->isSdeEnabled()) {
      $this->ensureSdeGraphFiles();
      return $this->syncStargateGraphFromSde($truncate);
    }
    $results = [
      'map_system_count' => 0,
      'map_edge_count' => 0,
      'systems_fetched' => 0,
      'stargates_fetched' => 0,
      'edges_discovered' => 0,
    ];

    $systemCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_system");
    if ($systemCount === 0) {
      $results['universe'] = $this->syncUniverse($ttlSeconds);
    }

    if ($truncate) {
      try {
        $this->db->execute("TRUNCATE map_edge");
        $this->db->execute("TRUNCATE map_system");
      } catch (Throwable $e) {
        $this->db->execute("DELETE FROM map_edge");
        $this->db->execute("DELETE FROM map_system");
      }
    }

    $this->db->execute(
      "INSERT INTO map_system (system_id, system_name, security, region_id, constellation_id)
       SELECT s.system_id,
              s.system_name,
              CASE WHEN s.has_security = 1 THEN s.security_status ELSE 0.0 END,
              c.region_id,
              s.constellation_id
         FROM eve_system s
         JOIN eve_constellation c ON c.constellation_id = s.constellation_id
       ON DUPLICATE KEY UPDATE
         system_name=VALUES(system_name),
         security=VALUES(security),
         region_id=VALUES(region_id),
         constellation_id=VALUES(constellation_id)"
    );

    $results['map_system_count'] = (int)$this->db->fetchValue("SELECT COUNT(*) FROM map_system");
    $systemRows = $this->db->select("SELECT system_id FROM map_system");
    $edges = [];

    foreach ($systemRows as $row) {
      $systemId = (int)$row['system_id'];
      if ($systemId <= 0) {
        continue;
      }
      $system = $this->fetchJson("/v4/universe/systems/{$systemId}/", $ttlSeconds);
      $results['systems_fetched']++;
      if (!$system) {
        continue;
      }

      $stargates = $system['stargates'] ?? null;
      if (!is_array($stargates) || $stargates === []) {
        continue;
      }

      foreach ($stargates as $stargateId) {
        $stargateId = (int)$stargateId;
        if ($stargateId <= 0) {
          continue;
        }
        $gate = $this->fetchJson("/v1/universe/stargates/{$stargateId}/", $ttlSeconds);
        $results['stargates_fetched']++;
        if (!$gate) {
          continue;
        }
        $destination = $gate['destination'] ?? null;
        $destSystemId = (int)($destination['system_id'] ?? 0);
        if ($destSystemId <= 0 || $destSystemId === $systemId) {
          continue;
        }
        $from = min($systemId, $destSystemId);
        $to = max($systemId, $destSystemId);
        $edges["{$from}:{$to}"] = [$from, $to];
      }
    }

    $results['edges_discovered'] = count($edges);

    $edges = array_values($edges);
    $chunkSize = 500;
    $totalEdges = count($edges);
    for ($offset = 0; $offset < $totalEdges; $offset += $chunkSize) {
      $chunk = array_slice($edges, $offset, $chunkSize);
      if ($chunk === []) {
        continue;
      }
      $placeholders = [];
      $params = [];
      foreach ($chunk as $idx => $edge) {
        $fromKey = "from_{$idx}";
        $toKey = "to_{$idx}";
        $placeholders[] = "(:{$fromKey}, :{$toKey})";
        $params[$fromKey] = $edge[0];
        $params[$toKey] = $edge[1];
      }
      $this->db->execute(
        "INSERT IGNORE INTO map_edge (from_system_id, to_system_id) VALUES " . implode(',', $placeholders),
        $params
      );
    }

    $results['map_edge_count'] = (int)$this->db->fetchValue("SELECT COUNT(*) FROM map_edge");

    return $results;
  }

  private function syncUniverseFromSde(): array
  {
    $results = [
      'regions_fetched' => 0,
      'constellations_fetched' => 0,
      'systems_fetched' => 0,
      'stations_fetched' => 0,
      'source' => 'sde',
    ];

    $regionFile = $this->sdeFilePath('mapRegions');
    $constellationFile = $this->sdeFilePath('mapConstellations');
    $systemFile = $this->sdeFilePath('mapSolarSystems');
    $stationFile = $this->sdeFilePath('npcStations');

    if ($regionFile === null || $constellationFile === null || $systemFile === null || $stationFile === null) {
      throw new \RuntimeException('Missing required SDE universe files.');
    }

    $results['regions_fetched'] = $this->importSdeRegions($regionFile);
    $results['constellations_fetched'] = $this->importSdeConstellations($constellationFile);
    $results['systems_fetched'] = $this->importSdeSystems($systemFile);
    $results['stations_fetched'] = $this->importSdeStations($stationFile);

    return $results;
  }

  private function syncStargateGraphFromSde(bool $truncate): array
  {
    $results = [
      'map_system_count' => 0,
      'map_edge_count' => 0,
      'edges_discovered' => 0,
      'source' => 'sde',
    ];

    if ($truncate) {
      try {
        $this->db->execute("TRUNCATE map_edge");
        $this->db->execute("TRUNCATE map_system");
      } catch (Throwable $e) {
        $this->db->execute("DELETE FROM map_edge");
        $this->db->execute("DELETE FROM map_system");
      }
    }

    $systemFile = $this->sdeFilePath('mapSolarSystems');
    $jumpFile = $this->sdeFilePath('mapSolarSystemJumps');
    $stargateFile = $this->sdeFilePath('mapStargates');
    $constellationFile = $this->sdeFilePath('mapConstellations');
    if ($systemFile === null || ($jumpFile === null && $stargateFile === null)) {
      throw new \RuntimeException('Missing required SDE graph files.');
    }

    $constellationRegions = $this->loadSdeConstellationRegions($constellationFile);
    $this->importSdeMapSystems($systemFile, $constellationRegions);
    $results['map_system_count'] = (int)$this->db->fetchValue("SELECT COUNT(*) FROM map_system");

    if ($jumpFile !== null) {
      $results['edges_discovered'] = $this->importSdeMapEdges($jumpFile);
    } else {
      $results['edges_discovered'] = $this->importSdeMapEdgesFromStargates($stargateFile);
    }
    $results['map_edge_count'] = (int)$this->db->fetchValue("SELECT COUNT(*) FROM map_edge");

    return $results;
  }

  private function importSdeRegions(string $filePath): int
  {
    $count = 0;
    $batch = [];
    $this->readSdeRows($filePath, function (array $row) use (&$batch, &$count) {
      $regionId = (int)$this->pickSdeValue($row, ['regionID', 'regionId', 'region_id', '_key']);
      $regionName = trim((string)$this->pickSdeValue($row, ['regionName', 'region_name']));
      if ($regionId <= 0 || $regionName === '') {
        return;
      }
      $batch[] = ['region_id' => $regionId, 'region_name' => $regionName];
      $count++;
      if (count($batch) >= 500) {
        $this->flushRegionBatch($batch);
        $batch = [];
      }
    });
    if ($batch !== []) {
      $this->flushRegionBatch($batch);
    }
    return $count;
  }

  private function flushRegionBatch(array $batch): void
  {
    $placeholders = [];
    $params = [];
    foreach ($batch as $idx => $row) {
      $placeholders[] = "(:region_id_{$idx}, :region_name_{$idx})";
      $params["region_id_{$idx}"] = $row['region_id'];
      $params["region_name_{$idx}"] = $row['region_name'];
    }
    $this->db->execute(
      "INSERT INTO eve_region (region_id, region_name)
       VALUES " . implode(',', $placeholders) . "
       ON DUPLICATE KEY UPDATE region_name=VALUES(region_name), updated_at=UTC_TIMESTAMP()",
      $params
    );
  }

  private function importSdeConstellations(string $filePath): int
  {
    $count = 0;
    $batch = [];
    $this->readSdeRows($filePath, function (array $row) use (&$batch, &$count) {
      $constellationId = (int)$this->pickSdeValue($row, ['constellationID', 'constellationId', 'constellation_id', '_key']);
      $constellationName = trim((string)$this->pickSdeValue($row, ['constellationName', 'constellation_name']));
      $regionId = (int)$this->pickSdeValue($row, ['regionID', 'regionId', 'region_id']);
      if ($constellationId <= 0 || $regionId <= 0 || $constellationName === '') {
        return;
      }
      $batch[] = [
        'constellation_id' => $constellationId,
        'constellation_name' => $constellationName,
        'region_id' => $regionId,
      ];
      $count++;
      if (count($batch) >= 500) {
        $this->flushConstellationBatch($batch);
        $batch = [];
      }
    });
    if ($batch !== []) {
      $this->flushConstellationBatch($batch);
    }
    return $count;
  }

  private function flushConstellationBatch(array $batch): void
  {
    $placeholders = [];
    $params = [];
    foreach ($batch as $idx => $row) {
      $placeholders[] = "(:constellation_id_{$idx}, :region_id_{$idx}, :constellation_name_{$idx})";
      $params["constellation_id_{$idx}"] = $row['constellation_id'];
      $params["region_id_{$idx}"] = $row['region_id'];
      $params["constellation_name_{$idx}"] = $row['constellation_name'];
    }
    $this->db->execute(
      "INSERT INTO eve_constellation (constellation_id, region_id, constellation_name)
       VALUES " . implode(',', $placeholders) . "
       ON DUPLICATE KEY UPDATE
         region_id=VALUES(region_id),
         constellation_name=VALUES(constellation_name),
         updated_at=UTC_TIMESTAMP()",
      $params
    );
  }

  private function importSdeSystems(string $filePath): int
  {
    $count = 0;
    $batch = [];
    $this->readSdeRows($filePath, function (array $row) use (&$batch, &$count) {
      $systemId = (int)$this->pickSdeValue($row, ['solarSystemID', 'solarSystemId', 'system_id', 'systemID', '_key']);
      $systemName = trim((string)$this->pickSdeValue($row, ['solarSystemName', 'system_name', 'systemName']));
      $constellationId = (int)$this->pickSdeValue($row, ['constellationID', 'constellationId', 'constellation_id']);
      $security = (float)$this->pickSdeValue($row, ['security', 'security_status'], 0.0);
      if ($systemId <= 0 || $constellationId <= 0 || $systemName === '') {
        return;
      }
      $batch[] = [
        'system_id' => $systemId,
        'constellation_id' => $constellationId,
        'system_name' => $systemName,
        'security_status' => $security,
      ];
      $count++;
      if (count($batch) >= 500) {
        $this->flushSystemBatch($batch);
        $batch = [];
      }
    });
    if ($batch !== []) {
      $this->flushSystemBatch($batch);
    }
    return $count;
  }

  private function flushSystemBatch(array $batch): void
  {
    $placeholders = [];
    $params = [];
    foreach ($batch as $idx => $row) {
      $placeholders[] = "(:system_id_{$idx}, :constellation_id_{$idx}, :system_name_{$idx}, :security_status_{$idx}, 1)";
      $params["system_id_{$idx}"] = $row['system_id'];
      $params["constellation_id_{$idx}"] = $row['constellation_id'];
      $params["system_name_{$idx}"] = $row['system_name'];
      $params["security_status_{$idx}"] = $row['security_status'];
    }
    $this->db->execute(
      "INSERT INTO eve_system (system_id, constellation_id, system_name, security_status, has_security)
       VALUES " . implode(',', $placeholders) . "
       ON DUPLICATE KEY UPDATE
         constellation_id=VALUES(constellation_id),
         system_name=VALUES(system_name),
         security_status=VALUES(security_status),
         has_security=VALUES(has_security),
         updated_at=UTC_TIMESTAMP()",
      $params
    );
  }

  private function importSdeMapSystems(string $filePath, array $constellationRegions = []): void
  {
    $batch = [];
    $this->readSdeRows($filePath, function (array $row) use (&$batch, $constellationRegions) {
      $systemId = (int)$this->pickSdeValue($row, ['solarSystemID', 'solarSystemId', 'system_id', 'systemID', '_key']);
      $systemName = trim((string)$this->pickSdeValue($row, ['solarSystemName', 'system_name', 'systemName']));
      $regionId = (int)$this->pickSdeValue($row, ['regionID', 'regionId', 'region_id']);
      $constellationId = (int)$this->pickSdeValue($row, ['constellationID', 'constellationId', 'constellation_id']);
      $security = (float)$this->pickSdeValue($row, ['security', 'security_status'], 0.0);
      if ($regionId <= 0 && $constellationId > 0) {
        $regionId = (int)($constellationRegions[$constellationId] ?? 0);
      }
      if ($systemId <= 0 || $constellationId <= 0 || $systemName === '') {
        return;
      }
      $batch[] = [
        'system_id' => $systemId,
        'system_name' => $systemName,
        'security' => $security,
        'region_id' => $regionId,
        'constellation_id' => $constellationId,
      ];
      if (count($batch) >= 500) {
        $this->flushMapSystemBatch($batch);
        $batch = [];
      }
    });
    if ($batch !== []) {
      $this->flushMapSystemBatch($batch);
    }
  }

  private function flushMapSystemBatch(array $batch): void
  {
    $placeholders = [];
    $params = [];
    foreach ($batch as $idx => $row) {
      $placeholders[] = "(:system_id_{$idx}, :system_name_{$idx}, :security_{$idx}, :region_id_{$idx}, :constellation_id_{$idx})";
      $params["system_id_{$idx}"] = $row['system_id'];
      $params["system_name_{$idx}"] = $row['system_name'];
      $params["security_{$idx}"] = $row['security'];
      $params["region_id_{$idx}"] = $row['region_id'];
      $params["constellation_id_{$idx}"] = $row['constellation_id'];
    }
    $this->db->execute(
      "INSERT INTO map_system (system_id, system_name, security, region_id, constellation_id)
       VALUES " . implode(',', $placeholders) . "
       ON DUPLICATE KEY UPDATE
         system_name=VALUES(system_name),
         security=VALUES(security),
         region_id=VALUES(region_id),
         constellation_id=VALUES(constellation_id)",
      $params
    );
  }

  private function importSdeMapEdges(string $filePath): int
  {
    $count = 0;
    $batch = [];
    $this->readSdeRows($filePath, function (array $row) use (&$batch, &$count) {
      $fromId = (int)$this->pickSdeValue($row, ['fromSolarSystemID', 'fromSolarSystemId', 'from_system_id']);
      $toId = (int)$this->pickSdeValue($row, ['toSolarSystemID', 'toSolarSystemId', 'to_system_id']);
      if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        return;
      }
      $from = min($fromId, $toId);
      $to = max($fromId, $toId);
      $batch[] = [$from, $to];
      $count++;
      if (count($batch) >= 1000) {
        $this->flushMapEdgeBatch($batch);
        $batch = [];
      }
    });
    if ($batch !== []) {
      $this->flushMapEdgeBatch($batch);
    }
    return $count;
  }

  private function importSdeMapEdgesFromStargates(?string $filePath): int
  {
    if ($filePath === null) {
      return 0;
    }
    $count = 0;
    $batch = [];
    $this->readSdeRows($filePath, function (array $row) use (&$batch, &$count) {
      $fromId = (int)$this->pickSdeValue($row, [
        'solarSystemID',
        'solarSystemId',
        'solar_system_id',
        'fromSolarSystemID',
        'fromSolarSystemId',
        'from_system_id',
      ]);
      $toId = $this->extractSdeDestinationSystemId($row);
      if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        return;
      }
      $from = min($fromId, $toId);
      $to = max($fromId, $toId);
      $batch[] = [$from, $to];
      $count++;
      if (count($batch) >= 1000) {
        $this->flushMapEdgeBatch($batch);
        $batch = [];
      }
    });
    if ($batch !== []) {
      $this->flushMapEdgeBatch($batch);
    }
    return $count;
  }

  private function loadSdeConstellationRegions(?string $filePath): array
  {
    $regions = [];
    if ($filePath !== null) {
      $this->readSdeRows($filePath, function (array $row) use (&$regions) {
        $constellationId = (int)$this->pickSdeValue($row, ['constellationID', 'constellationId', 'constellation_id', '_key']);
        $regionId = (int)$this->pickSdeValue($row, ['regionID', 'regionId', 'region_id']);
        if ($constellationId > 0 && $regionId > 0) {
          $regions[$constellationId] = $regionId;
        }
      });
    }

    if ($regions === []) {
      foreach ($this->db->select("SELECT constellation_id, region_id FROM eve_constellation") as $row) {
        $constellationId = (int)($row['constellation_id'] ?? 0);
        $regionId = (int)($row['region_id'] ?? 0);
        if ($constellationId > 0 && $regionId > 0) {
          $regions[$constellationId] = $regionId;
        }
      }
    }

    return $regions;
  }

  private function flushMapEdgeBatch(array $batch): void
  {
    $placeholders = [];
    $params = [];
    foreach ($batch as $idx => $edge) {
      $placeholders[] = "(:from_{$idx}, :to_{$idx})";
      $params["from_{$idx}"] = $edge[0];
      $params["to_{$idx}"] = $edge[1];
    }
    $this->db->execute(
      "INSERT IGNORE INTO map_edge (from_system_id, to_system_id)
       VALUES " . implode(',', $placeholders),
      $params
    );
  }

  private function importSdeStations(string $filePath): int
  {
    $count = 0;
    $batch = [];
    $this->readSdeRows($filePath, function (array $row) use (&$batch, &$count) {
      $stationId = (int)$this->pickSdeValue($row, ['stationID', 'stationId', 'station_id', '_key']);
      $systemId = (int)$this->pickSdeValue($row, ['solarSystemID', 'solarSystemId', 'system_id', 'systemID']);
      $stationName = trim((string)$this->pickSdeValue($row, ['stationName', 'station_name', 'name']));
      $stationTypeId = $this->pickSdeValue($row, ['stationTypeID', 'stationTypeId', 'station_type_id']);
      $stationTypeId = $stationTypeId !== null ? (int)$stationTypeId : null;
      if ($stationId <= 0 || $systemId <= 0 || $stationName === '') {
        return;
      }
      $batch[] = [
        'station_id' => $stationId,
        'system_id' => $systemId,
        'station_name' => $stationName,
        'station_type_id' => $stationTypeId,
      ];
      $count++;
      if (count($batch) >= 500) {
        $this->flushStationBatch($batch);
        $batch = [];
      }
    });
    if ($batch !== []) {
      $this->flushStationBatch($batch);
    }
    return $count;
  }

  private function flushStationBatch(array $batch): void
  {
    $placeholders = [];
    $params = [];
    foreach ($batch as $idx => $row) {
      $placeholders[] = "(:station_id_{$idx}, :system_id_{$idx}, :station_name_{$idx}, :station_type_id_{$idx})";
      $params["station_id_{$idx}"] = $row['station_id'];
      $params["system_id_{$idx}"] = $row['system_id'];
      $params["station_name_{$idx}"] = $row['station_name'];
      $params["station_type_id_{$idx}"] = $row['station_type_id'];
    }
    $this->db->execute(
      "INSERT INTO eve_station (station_id, system_id, station_name, station_type_id)
       VALUES " . implode(',', $placeholders) . "
       ON DUPLICATE KEY UPDATE
         system_id=VALUES(system_id),
         station_name=VALUES(station_name),
         station_type_id=VALUES(station_type_id),
         updated_at=UTC_TIMESTAMP()",
      $params
    );

    foreach ($batch as $row) {
      $this->db->upsertEntity((int)$row['station_id'], 'station', (string)$row['station_name'], null, null, 'sde');
    }
  }

  private function fetchPublicStructureIds(string $bearer, int $corpId, int $ttlSeconds): array
  {
    $page = 1;
    $pages = 1;
    $ids = [];
    do {
      $resp = $this->esiAuthedGet(
        $bearer,
        '/v1/universe/structures/',
        ['page' => $page],
        $corpId,
        $ttlSeconds
      );
      if (!$resp['ok']) {
        throw new \RuntimeException("ESI public structures list failed: HTTP {$resp['status']}");
      }
      $pageIds = is_array($resp['json']) ? array_map('intval', $resp['json']) : [];
      $ids = array_merge($ids, array_filter($pageIds, static fn($id) => $id > 0));
      $pagesHeader = $resp['headers']['x-pages'] ?? null;
      $pages = max($pages, (int)($pagesHeader ?: 1));
      $page++;
    } while ($page <= $pages);

    return array_values(array_unique($ids));
  }

  private function isSdeEnabled(): bool
  {
    return $this->forceSde || (bool)($this->config['sde']['enabled'] ?? false);
  }

  public function setForceSde(bool $forceSde): void
  {
    $this->forceSde = $forceSde;
  }

  private function sdePath(string $fileName): string
  {
    $basePath = rtrim((string)($this->config['sde']['path'] ?? ''), DIRECTORY_SEPARATOR);
    return $basePath === '' ? $fileName : $basePath . DIRECTORY_SEPARATOR . $fileName;
  }

  private function sdeFilePath(string $baseName): ?string
  {
    $basePath = rtrim((string)($this->config['sde']['path'] ?? ''), DIRECTORY_SEPARATOR);
    if ($basePath === '') {
      return null;
    }
    $extensions = ["{$baseName}.jsonl", "{$baseName}.csv"];
    foreach ($extensions as $fileName) {
      $path = $this->sdePath($fileName);
      if ($path !== '' && is_file($path) && is_readable($path)) {
        return $path;
      }
      foreach (glob($basePath . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $fileName) ?: [] as $nested) {
        if (is_file($nested) && is_readable($nested)) {
          return $nested;
        }
      }
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
      );
      foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getFilename() === $fileName && $fileInfo->isReadable()) {
          return $fileInfo->getPathname();
        }
      }
    }
    return null;
  }

  private function extractSdeDestinationSystemId(array $row): int
  {
    $direct = $this->pickSdeValue($row, [
      'destinationSolarSystemID',
      'destinationSolarSystemId',
      'destination_system_id',
      'toSolarSystemID',
      'toSolarSystemId',
      'to_system_id',
    ]);
    if ($direct !== null && $direct !== '') {
      return (int)$direct;
    }
    $destination = $row['destination'] ?? null;
    if (is_array($destination)) {
      $nested = $this->pickSdeValue($destination, [
        'solarSystemID',
        'solarSystemId',
        'solar_system_id',
        'destinationSolarSystemID',
        'destinationSolarSystemId',
        'destination_system_id',
        'toSolarSystemID',
        'toSolarSystemId',
        'to_system_id',
      ]);
      if ($nested !== null && $nested !== '') {
        return (int)$nested;
      }
    }
    return 0;
  }

  private function hasSdeUniverseFiles(): bool
  {
    return $this->sdeFilePath('mapRegions') !== null
      && $this->sdeFilePath('mapConstellations') !== null
      && $this->sdeFilePath('mapSolarSystems') !== null
      && $this->sdeFilePath('npcStations') !== null;
  }

  private function hasSdeGraphFiles(): bool
  {
    return $this->sdeFilePath('mapSolarSystems') !== null
      && ($this->sdeFilePath('mapSolarSystemJumps') !== null
        || $this->sdeFilePath('mapStargates') !== null);
  }

  private function ensureSdeUniverseFiles(): void
  {
    $this->ensureSdeFiles(['mapRegions', 'mapConstellations', 'mapSolarSystems', 'npcStations']);
  }

  private function ensureSdeGraphFiles(): void
  {
    $hasSystems = $this->sdeFilePath('mapSolarSystems') !== null;
    $hasJumps = $this->sdeFilePath('mapSolarSystemJumps') !== null;
    $hasStargates = $this->sdeFilePath('mapStargates') !== null;
    if ($hasSystems && ($hasJumps || $hasStargates)) {
      $basePath = rtrim((string)($this->config['sde']['path'] ?? ''), DIRECTORY_SEPARATOR);
      if ($basePath === '') {
        throw new \RuntimeException('SDE_PATH must be set to download the latest SDE.');
      }
      $this->maybeRefreshSde($basePath);
      return;
    }

    $basePath = rtrim((string)($this->config['sde']['path'] ?? ''), DIRECTORY_SEPARATOR);
    if ($basePath === '') {
      throw new \RuntimeException('SDE_PATH must be set to download the latest SDE.');
    }
    $this->downloadLatestSde($basePath);

    $hasSystems = $this->sdeFilePath('mapSolarSystems') !== null;
    $hasJumps = $this->sdeFilePath('mapSolarSystemJumps') !== null;
    $hasStargates = $this->sdeFilePath('mapStargates') !== null;
    if (!($hasSystems && ($hasJumps || $hasStargates))) {
      $missing = [];
      if (!$hasSystems) {
        $missing[] = 'mapSolarSystems';
      }
      if (!($hasJumps || $hasStargates)) {
        $missing[] = 'mapSolarSystemJumps/mapStargates';
      }
      throw new \RuntimeException('Missing required SDE files after download: ' . implode(', ', $missing));
    }
  }

  private function ensureSdeFiles(array $requiredBaseNames): void
  {
    $missing = [];
    foreach ($requiredBaseNames as $baseName) {
      if ($this->sdeFilePath($baseName) === null) {
        $missing[] = $baseName;
      }
    }
    if ($missing === []) {
      $basePath = rtrim((string)($this->config['sde']['path'] ?? ''), DIRECTORY_SEPARATOR);
      if ($basePath === '') {
        throw new \RuntimeException('SDE_PATH must be set to download the latest SDE.');
      }
      $this->maybeRefreshSde($basePath);
      return;
    }

    $basePath = rtrim((string)($this->config['sde']['path'] ?? ''), DIRECTORY_SEPARATOR);
    if ($basePath === '') {
      throw new \RuntimeException('SDE_PATH must be set to download the latest SDE.');
    }
    $this->downloadLatestSde($basePath);

    $stillMissing = [];
    foreach ($requiredBaseNames as $baseName) {
      if ($this->sdeFilePath($baseName) === null) {
        $stillMissing[] = $baseName;
      }
    }
    if ($stillMissing !== []) {
      throw new \RuntimeException('Missing required SDE files after download: ' . implode(', ', $stillMissing));
    }
  }

  private function maybeRefreshSde(string $basePath, bool $force = false): void
  {
    $meta = $this->readSdeMeta($basePath);
    $now = time();
    $lastChecked = (int)($meta['last_checked'] ?? 0);
    if (!$force && $lastChecked > 0 && ($now - $lastChecked) < self::SDE_UPDATE_CHECK_SECONDS) {
      return;
    }

    $head = $this->fetchSdeHead();
    $previousEtag = $meta['etag'] ?? null;
    $previousLastModified = $meta['last_modified'] ?? null;
    $meta['last_checked'] = $now;

    if (!$head) {
      $this->writeSdeMeta($basePath, $meta);
      return;
    }

    $etag = $head['etag'] ?? null;
    $lastModified = $head['last_modified'] ?? null;
    $meta['etag'] = $etag ?? $previousEtag;
    $meta['last_modified'] = $lastModified ?? $previousLastModified;

    $changed = false;
    if ($etag && $etag !== $previousEtag) {
      $changed = true;
    }
    if (!$changed && $lastModified && $lastModified !== $previousLastModified) {
      $changed = true;
    }

    if ($changed || $force) {
      $this->downloadLatestSde($basePath, $head);
      $meta['downloaded_at'] = gmdate('c');
      $meta['etag'] = $etag ?? $previousEtag;
      $meta['last_modified'] = $lastModified ?? $previousLastModified;
    }

    $this->writeSdeMeta($basePath, $meta);
  }

  private function readSdeMeta(string $basePath): array
  {
    $path = $basePath . DIRECTORY_SEPARATOR . self::SDE_META_FILENAME;
    if (!is_file($path)) {
      return [];
    }
    $contents = @file_get_contents($path);
    if ($contents === false) {
      return [];
    }
    $decoded = Db::jsonDecode($contents, []);
    return is_array($decoded) ? $decoded : [];
  }

  private function writeSdeMeta(string $basePath, array $meta): void
  {
    $path = $basePath . DIRECTORY_SEPARATOR . self::SDE_META_FILENAME;
    @file_put_contents($path, Db::jsonEncode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  private function fetchSdeHead(): ?array
  {
    $headers = [];
    $ch = curl_init(self::SDE_LATEST_JSONL_URL);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $headerLine) use (&$headers) {
      $len = strlen($headerLine);
      $headerLine = trim($headerLine);
      if ($headerLine === '' || !str_contains($headerLine, ':')) {
        return $len;
      }
      [$name, $value] = explode(':', $headerLine, 2);
      $headers[strtolower(trim($name))] = trim($value);
      return $len;
    });
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($ok === false || $status < 200 || $status >= 300) {
      return null;
    }

    return [
      'etag' => $headers['etag'] ?? null,
      'last_modified' => $headers['last-modified'] ?? null,
    ];
  }

  private function downloadLatestSde(string $targetDir, ?array $head = null): void
  {
    if (!is_dir($targetDir)) {
      if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new \RuntimeException("Failed to create SDE directory: {$targetDir}");
      }
    }
    if (!is_writable($targetDir)) {
      throw new \RuntimeException("SDE directory is not writable: {$targetDir}");
    }

    $tempZip = tempnam($targetDir, 'sde_');
    if ($tempZip === false) {
      throw new \RuntimeException('Failed to create SDE temp file.');
    }
    $zipPath = $tempZip . '.zip';
    rename($tempZip, $zipPath);

    $headers = [];
    $ch = curl_init(self::SDE_LATEST_JSONL_URL);
    $fp = fopen($zipPath, 'wb');
    if ($fp === false) {
      throw new \RuntimeException("Failed to open SDE temp file: {$zipPath}");
    }
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $headerLine) use (&$headers) {
      $len = strlen($headerLine);
      $headerLine = trim($headerLine);
      if ($headerLine === '' || !str_contains($headerLine, ':')) {
        return $len;
      }
      [$name, $value] = explode(':', $headerLine, 2);
      $headers[strtolower(trim($name))] = trim($value);
      return $len;
    });
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = $errno ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $status < 200 || $status >= 300) {
      @unlink($zipPath);
      $detail = $err ? " ({$err})" : '';
      throw new \RuntimeException("Failed to download latest SDE: HTTP {$status}{$detail}");
    }

    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
      @unlink($zipPath);
      throw new \RuntimeException("Failed to open SDE zip: {$zipPath}");
    }
    $zip->extractTo($targetDir);
    $zip->close();
    @unlink($zipPath);

    $meta = $this->readSdeMeta($targetDir);
    $meta['downloaded_at'] = gmdate('c');
    $meta['last_checked'] = time();
    $meta['etag'] = $headers['etag'] ?? ($head['etag'] ?? ($meta['etag'] ?? null));
    $meta['last_modified'] = $headers['last-modified'] ?? ($head['last_modified'] ?? ($meta['last_modified'] ?? null));
    $this->writeSdeMeta($targetDir, $meta);
  }

  private function readSdeRows(string $filePath, callable $handler): void
  {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'jsonl') {
      $this->readSdeJsonl($filePath, $handler);
      return;
    }
    $this->readSdeCsv($filePath, $handler);
  }

  private function readSdeJsonl(string $filePath, callable $handler): void
  {
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
      throw new \RuntimeException("Failed to open SDE file: {$filePath}");
    }

    while (($line = fgets($handle)) !== false) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $decoded = Db::jsonDecode($line, null);
      if (!is_array($decoded)) {
        continue;
      }
      $handler($this->normalizeSdeRow($decoded));
    }

    fclose($handle);
  }

  private function readSdeCsv(string $filePath, callable $handler): void
  {
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
      throw new \RuntimeException("Failed to open SDE file: {$filePath}");
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers)) {
      fclose($handle);
      throw new \RuntimeException("Invalid SDE CSV header: {$filePath}");
    }
    $headerMap = [];
    foreach ($headers as $idx => $name) {
      $key = trim((string)$name);
      if ($key !== '') {
        $headerMap[$key] = $idx;
      }
    }

    while (($row = fgetcsv($handle)) !== false) {
      if ($row === [null] || $row === []) {
        continue;
      }
      $assoc = [];
      foreach ($headerMap as $name => $idx) {
        $assoc[$name] = $row[$idx] ?? null;
      }
      $handler($assoc);
    }

    fclose($handle);
  }

  private function normalizeSdeRow(array $row): array
  {
    if (!array_key_exists('_value', $row)) {
      return $row;
    }
    $value = $row['_value'];
    if (is_array($value)) {
      if (array_key_exists('_key', $row) && !array_key_exists('_key', $value)) {
        $value['_key'] = $row['_key'];
      }
      return $value;
    }
    return ['_key' => $row['_key'] ?? null, '_value' => $value];
  }

  private function pickSdeValue(array $row, array $keys, $default = null)
  {
    foreach ($keys as $key) {
      if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
        return $row[$key];
      }
    }
    return $default;
  }

  private function fetchIdList(string $path, int $ttlSeconds): array
  {
    $resp = $this->esi->get($path, null, null, $ttlSeconds);
    if (!$resp['ok']) {
      throw new \RuntimeException("ESI universe list failed for {$path}: HTTP {$resp['status']}");
    }
    $ids = $resp['json'];
    if (!is_array($ids)) return [];
    return array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
  }

  private function fetchJson(string $path, int $ttlSeconds): ?array
  {
    $resp = $this->esi->get($path, null, null, $ttlSeconds);
    if (!$resp['ok']) {
      return null;
    }
    return is_array($resp['json']) ? $resp['json'] : null;
  }

  private function esiAuthedGet(
    string $bearer,
    string $path,
    ?array $query,
    int $corpId,
    int $ttl
  ): array {
    $base = rtrim($this->config['esi']['endpoints']['esi_base'] ?? 'https://esi.evetech.net', '/');
    $url = $base . '/' . ltrim($path, '/');

    $cacheEnabled = (bool)($this->config['esi']['cache']['enabled'] ?? true);
    $cacheKeyBin = Db::esiCacheKey('GET', $url, $query, null);
    $cached = ['hit' => false, 'json' => null, 'etag' => null];

    if ($cacheEnabled) {
      $cached = $this->db->esiCacheGet($corpId, $cacheKeyBin);
    }

    $qs = $query ? ('?' . http_build_query($query)) : '';
    $finalUrl = $url . $qs;

    $respHeaders = [];
    $ch = curl_init($finalUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$respHeaders) {
      $len = strlen($headerLine);
      $headerLine = trim($headerLine);
      if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
      [$k, $v] = explode(':', $headerLine, 2);
      $respHeaders[strtolower(trim($k))] = trim($v);
      return $len;
    });

    $reqHeaders = [
      'Accept: application/json',
      'Authorization: Bearer ' . $bearer,
    ];
    if ($cacheEnabled && !empty($cached['etag'])) {
      $reqHeaders[] = 'If-None-Match: ' . $cached['etag'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

    $raw = '';
    $errno = 0;
    $err = null;
    $status = 0;
    $attempt = 0;

    while (true) {
      $raw = (string)curl_exec($ch);
      $errno = curl_errno($ch);
      $err = $errno ? curl_error($ch) : null;
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

      if (!$errno && EsiRateLimiter::shouldRetry($status, $this->config) && $attempt < 1) {
        curl_close($ch);
        EsiRateLimiter::sleepForRetry($respHeaders, $this->config);
        $respHeaders = [];
        $ch = curl_init($finalUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->config['esi']['user_agent'] ?? 'CorpHauling/1.0');
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$respHeaders) {
          $len = strlen($headerLine);
          $headerLine = trim($headerLine);
          if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
          [$k, $v] = explode(':', $headerLine, 2);
          $respHeaders[strtolower(trim($k))] = trim($v);
          return $len;
        });
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
        $attempt++;
        continue;
      }

      break;
    }

    curl_close($ch);

    if (!$errno) {
      EsiRateLimiter::sleepIfLowRemaining($respHeaders, $this->config);
    }

    if ($status === 304 && $cacheEnabled && $cached['hit'] && $cached['json'] !== null) {
      $raw = $cached['json'];
      $this->db->esiCachePut($corpId, $cacheKeyBin, 'GET', $url, $query, null, 200, $cached['etag'], $respHeaders['last-modified'] ?? null, $ttl, $raw);
      return ['ok' => true, 'status' => 200, 'headers' => $respHeaders, 'json' => Db::jsonDecode($raw, null), 'raw' => $raw, 'from_cache' => true];
    }

    if ($errno) {
      if ($cacheEnabled && $cached['hit'] && $cached['json'] !== null) {
        return ['ok' => true, 'status' => 200, 'headers' => $respHeaders, 'json' => Db::jsonDecode($cached['json'], null), 'raw' => $cached['json'], 'from_cache' => true, 'warning' => $err];
      }
      return ['ok' => false, 'status' => 0, 'headers' => $respHeaders, 'json' => null, 'raw' => '', 'from_cache' => false, 'error' => $err];
    }

    if ($cacheEnabled) {
      $this->db->esiCachePut($corpId, $cacheKeyBin, 'GET', $url, $query, null, $status, $respHeaders['etag'] ?? null, $respHeaders['last-modified'] ?? null, $ttl, $raw, ($status >= 400 ? ('HTTP ' . $status) : null));
    }

    $decoded = null;
    try { $decoded = Db::jsonDecode($raw, null); } catch (\Throwable $e) {}
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'headers' => $respHeaders, 'json' => $decoded, 'raw' => $raw, 'from_cache' => false];
  }
}
