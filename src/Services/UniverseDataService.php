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
  public function __construct(
    private Db $db,
    private array $config,
    private EsiClient $esi,
    private SsoService $sso
  ) {}

  public function syncUniverse(int $ttlSeconds = 86400): array
  {
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
      $upserted++;
    }

    return [
      'corp_id' => $corpId,
      'fetched' => count($structures),
      'upserted' => $upserted,
    ];
  }

  public function syncStargateGraph(int $ttlSeconds = 86400, bool $truncate = true): array
  {
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
      $this->db->execute("TRUNCATE map_edge");
      $this->db->execute("TRUNCATE map_system");
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

    $raw = (string)curl_exec($ch);
    $errno = curl_errno($ch);
    $err = $errno ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

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
