<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/CronSyncService.php
 *
 * Orchestrates scheduled ESI sync tasks with cooldowns.
 */
final class CronSyncService
{
  private const SETTING_KEY = 'esi.cron.stats';

  private UniverseDataService $universe;
  private SsoService $sso;

  public function __construct(
    private Db $db,
    private array $config,
    private EsiService $esi
  ) {
    $esiClient = new EsiClient($db, $config);
    $this->sso = new SsoService($db, $config);
    $this->universe = new UniverseDataService($db, $config, $esiClient, $this->sso);
  }

  public function run(int $corpId, int $characterId, array $options = []): array
  {
    $options = array_merge([
      'force' => false,
      'scope' => 'all',
      'ttl_universe' => 86400,
      'ttl_stargate' => 86400,
      'ttl_tokens' => 300,
      'ttl_contracts' => 300,
      'ttl_structures' => 900,
      'ttl_public_structures' => 86400,
      'on_progress' => null,
    ], $options);

    $force = (bool)$options['force'];
    $scope = (string)$options['scope'];
    $runUniverse = in_array($scope, ['all', 'universe'], true);
    $runCorp = $scope === 'all';
    $progressCb = is_callable($options['on_progress'] ?? null) ? $options['on_progress'] : null;
    $steps = [];
    if ($runUniverse) {
      $steps[] = ['key' => 'universe', 'label' => 'Universe data'];
      $steps[] = ['key' => 'stargate_graph', 'label' => 'Stargate graph'];
    }
    if ($runCorp) {
      $steps[] = ['key' => 'token_refresh', 'label' => 'Token refresh'];
      $steps[] = ['key' => 'structures', 'label' => 'Structures'];
      $steps[] = ['key' => 'public_structures', 'label' => 'Public structures'];
      $steps[] = ['key' => 'contracts', 'label' => 'Contracts'];
    }
    $totalSteps = count($steps);
    $currentStep = 0;
    $this->reportProgress($progressCb, [
      'current' => 0,
      'total' => $totalSteps,
      'label' => 'Starting sync',
      'stage' => 'start',
      'scope' => $scope,
    ]);
    $stats = $this->loadStats($corpId);
    $results = [
      'force' => $force,
      'scope' => $scope,
      'started_at' => gmdate('c'),
    ];

    $universeEmpty = $this->isUniverseEmpty();
    if ($runUniverse) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Universe data',
        'stage' => 'universe',
      ]);
    }
    if (!$runUniverse) {
      $results['universe'] = $this->skipPayload($stats, 'universe', $universeEmpty, 'scope');
    } elseif ($this->shouldRun($stats, 'universe', (int)$options['ttl_universe'], $force, $universeEmpty)) {
      $results['universe'] = $this->universe->syncUniverse((int)$options['ttl_universe']);
      $stats['universe'] = gmdate('c');
    } else {
      $results['universe'] = $this->skipPayload($stats, 'universe', $universeEmpty);
    }

    $graphEmpty = $this->isStargateGraphEmpty();
    if ($runUniverse) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Stargate graph',
        'stage' => 'stargate_graph',
      ]);
    }
    if (!$runUniverse) {
      $results['stargate_graph'] = $this->skipPayload($stats, 'stargate_graph', $graphEmpty, 'scope');
    } elseif ($this->shouldRun($stats, 'stargate_graph', (int)$options['ttl_stargate'], $force, $graphEmpty)) {
      $results['stargate_graph'] = $this->universe->syncStargateGraph((int)$options['ttl_stargate'], true);
      $stats['stargate_graph'] = gmdate('c');
    } else {
      $results['stargate_graph'] = $this->skipPayload($stats, 'stargate_graph', $graphEmpty);
    }

    if ($runCorp) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Token refresh',
        'stage' => 'token_refresh',
      ]);
    }
    if (!$runCorp) {
      $results['token_refresh'] = $this->skipPayload($stats, 'token_refresh', false, 'scope');
    } elseif ($this->shouldRun($stats, 'token_refresh', (int)$options['ttl_tokens'], $force, false)) {
      $results['token_refresh'] = $this->sso->refreshCorpTokens($corpId);
      $stats['token_refresh'] = gmdate('c');
    } else {
      $results['token_refresh'] = $this->skipPayload($stats, 'token_refresh', false);
    }

    $structuresEmpty = $this->isStructuresEmpty($corpId);
    if ($runCorp) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Structures',
        'stage' => 'structures',
      ]);
    }
    try {
      if (!$runCorp) {
        $results['structures'] = $this->skipPayload($stats, 'structures', $structuresEmpty, 'scope');
      } elseif ($this->shouldRun($stats, 'structures', (int)$options['ttl_structures'], $force, $structuresEmpty)) {
        $results['structures'] = $this->universe->syncCorpStructures($corpId, $characterId, (int)$options['ttl_structures']);
        $stats['structures'] = gmdate('c');
      } else {
        $results['structures'] = $this->skipPayload($stats, 'structures', $structuresEmpty);
      }
    } catch (\Throwable $e) {
      $results['structures_error'] = $e->getMessage();
    }

    $publicStructuresEmpty = $this->isPublicStructuresEmpty();
    if ($runCorp) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Public structures',
        'stage' => 'public_structures',
      ]);
    }
    try {
      if (!$runCorp) {
        $results['public_structures'] = $this->skipPayload($stats, 'public_structures', $publicStructuresEmpty, 'scope');
      } elseif ($this->shouldRun($stats, 'public_structures', (int)$options['ttl_public_structures'], $force, $publicStructuresEmpty)) {
        $results['public_structures'] = $this->universe->syncPublicStructures($corpId, $characterId, (int)$options['ttl_public_structures']);
        $stats['public_structures'] = gmdate('c');
      } else {
        $results['public_structures'] = $this->skipPayload($stats, 'public_structures', $publicStructuresEmpty);
      }
    } catch (\Throwable $e) {
      $results['public_structures_error'] = $e->getMessage();
    }

    $contractsEmpty = $this->isContractsEmpty($corpId);
    if ($runCorp) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Contracts',
        'stage' => 'contracts',
      ]);
    }
    if (!$runCorp) {
      $results['contracts'] = $this->skipPayload($stats, 'contracts', $contractsEmpty, 'scope');
    } elseif ($this->shouldRun($stats, 'contracts', (int)$options['ttl_contracts'], $force, $contractsEmpty)) {
      $results['contracts'] = $this->db->tx(fn($db) => $this->esi->contracts()->pull($corpId, $characterId));
      $stats['contracts'] = gmdate('c');
    } else {
      $results['contracts'] = $this->skipPayload($stats, 'contracts', $contractsEmpty);
    }

    $this->persistStats($corpId, $stats);

    $results['finished_at'] = gmdate('c');
    $this->reportProgress($progressCb, [
      'current' => $totalSteps,
      'total' => $totalSteps,
      'label' => 'Sync complete',
      'stage' => 'completed',
    ]);
    return $results;
  }

  public function getStats(int $corpId): array
  {
    return $this->loadStats($corpId);
  }

  private function loadStats(int $corpId): array
  {
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = :key LIMIT 1",
      ['cid' => $corpId, 'key' => self::SETTING_KEY]
    );
    if (!$row || empty($row['setting_json'])) {
      return [];
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    return is_array($decoded) ? $decoded : [];
  }

  private function persistStats(int $corpId, array $stats): void
  {
    $this->db->execute(
      "INSERT INTO app_setting (corp_id, setting_key, setting_json)
       VALUES (:cid, :key, :json)
       ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json), updated_at=UTC_TIMESTAMP()",
      [
        'cid' => $corpId,
        'key' => self::SETTING_KEY,
        'json' => Db::jsonEncode($stats),
      ]
    );
  }

  private function shouldRun(array $stats, string $key, int $ttlSeconds, bool $force, bool $empty): bool
  {
    if ($force || $empty) return true;
    $last = $this->lastSyncAt($stats, $key);
    if ($last === null) return true;
    return (time() - $last) >= $ttlSeconds;
  }

  private function skipPayload(array $stats, string $key, bool $empty, ?string $reasonOverride = null): array
  {
    $last = $this->lastSyncAt($stats, $key);
    return [
      'skipped' => true,
      'reason' => $reasonOverride ?? ($empty ? 'empty' : 'cooldown'),
      'last_sync' => $last ? gmdate('c', $last) : null,
    ];
  }

  private function lastSyncAt(array $stats, string $key): ?int
  {
    $last = $stats[$key] ?? null;
    if (!$last) return null;
    $ts = strtotime((string)$last);
    return $ts === false ? null : $ts;
  }

  private function isUniverseEmpty(): bool
  {
    $regionCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_region");
    $constellationCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_constellation");
    $systemCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_system");
    $stationCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_station");
    return $regionCount === 0 || $constellationCount === 0 || $systemCount === 0 || $stationCount === 0;
  }

  private function isStargateGraphEmpty(): bool
  {
    $graphEdgeCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM map_edge");
    $graphSystemCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM map_system");
    return $graphEdgeCount === 0 || $graphSystemCount === 0;
  }

  private function isStructuresEmpty(int $corpId): bool
  {
    $count = (int)$this->db->fetchValue(
      "SELECT COUNT(*) FROM eve_structure WHERE owner_corp_id = :cid",
      ['cid' => $corpId]
    );
    return $count === 0;
  }

  private function isContractsEmpty(int $corpId): bool
  {
    $count = (int)$this->db->fetchValue(
      "SELECT COUNT(*) FROM esi_corp_contract WHERE corp_id = :cid",
      ['cid' => $corpId]
    );
    return $count === 0;
  }

  private function isPublicStructuresEmpty(): bool
  {
    $count = (int)$this->db->fetchValue(
      "SELECT COUNT(*) FROM eve_structure WHERE is_public = 1"
    );
    return $count === 0;
  }

  private function reportProgress(?callable $callback, array $payload): void
  {
    if ($callback) {
      $callback($payload);
    }
  }
}
