<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;
use App\Services\DiscordWebhookService;

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
    private EsiService $esi,
    private ?DiscordWebhookService $webhooks = null
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
      'sde' => false,
      'ttl_universe' => 86400,
      'ttl_stargate' => 86400,
      'ttl_tokens' => 300,
      'ttl_contracts' => 300,
      'ttl_structures' => 900,
      'ttl_public_structures' => 86400,
      'contracts_max_runtime' => (int)($_ENV['CRON_CONTRACTS_MAX_RUNTIME'] ?? 60),
      'contracts_page_limit' => 50,
      'contracts_lookback_hours' => 24,
      'contracts_reconcile_max_runtime' => 60,
      'sync_public_structures' => true,
      'steps' => null,
      'on_progress' => null,
    ], $options);

    $force = (bool)$options['force'];
    $scope = (string)$options['scope'];
    $useSde = (bool)$options['sde'];
    $syncPublicStructures = (bool)$options['sync_public_structures'];
    $allowedSteps = null;
    if ($options['steps'] !== null) {
      $rawSteps = $options['steps'];
      if (!is_array($rawSteps)) {
        $rawSteps = [$rawSteps];
      }
      $validSteps = [
        'universe',
        'stargate_graph',
        'token_refresh',
        'structures',
        'public_structures',
        'contracts',
      ];
      $filtered = [];
      foreach ($rawSteps as $step) {
        if (!is_string($step)) {
          continue;
        }
        if (in_array($step, $validSteps, true)) {
          $filtered[] = $step;
        }
      }
      $allowedSteps = array_values(array_unique($filtered));
    }
    $usingSde = $useSde || (bool)($this->config['sde']['enabled'] ?? false);
    $this->universe->setForceSde($useSde);
    $stepAllowed = static function (?array $allowed, string $key): bool {
      if ($allowed === null) {
        return true;
      }
      return in_array($key, $allowed, true);
    };
    $runUniverse = $allowedSteps !== null
      ? ($stepAllowed($allowedSteps, 'universe') || $stepAllowed($allowedSteps, 'stargate_graph'))
      : in_array($scope, ['all', 'universe'], true);
    $runCorp = $allowedSteps !== null
      ? ($stepAllowed($allowedSteps, 'token_refresh')
        || $stepAllowed($allowedSteps, 'structures')
        || $stepAllowed($allowedSteps, 'public_structures')
        || $stepAllowed($allowedSteps, 'contracts'))
      : $scope === 'all';
    $progressCb = is_callable($options['on_progress'] ?? null) ? $options['on_progress'] : null;
    $steps = [];
    if ($runUniverse && $stepAllowed($allowedSteps, 'universe')) {
      $steps[] = ['key' => 'universe', 'label' => 'Universe data'];
    }
    if ($runUniverse && $stepAllowed($allowedSteps, 'stargate_graph')) {
      $steps[] = ['key' => 'stargate_graph', 'label' => 'Stargate graph'];
    }
    if ($runCorp && $stepAllowed($allowedSteps, 'token_refresh')) {
      $steps[] = ['key' => 'token_refresh', 'label' => 'Token refresh'];
    }
    if ($runCorp && $stepAllowed($allowedSteps, 'structures')) {
      $steps[] = ['key' => 'structures', 'label' => 'Structures'];
    }
    $allowPublicStructures = $syncPublicStructures && $stepAllowed($allowedSteps, 'public_structures');
    if ($runCorp && $allowPublicStructures) {
      $steps[] = ['key' => 'public_structures', 'label' => 'Public structures'];
    }
    if ($runCorp && $stepAllowed($allowedSteps, 'contracts')) {
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
      'sde' => $useSde,
      'started_at' => gmdate('c'),
    ];

    $universeEmpty = $this->isUniverseEmpty($usingSde);
    $sdeUpdateAvailable = false;
    if ($usingSde && $runUniverse) {
      $sdeUpdateAvailable = $this->universe->sdeUpdateAvailable();
    }
    $runUniverseStep = $runUniverse && $stepAllowed($allowedSteps, 'universe');
    if ($runUniverseStep) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Universe data',
        'stage' => 'universe',
      ]);
    }
    if (!$runUniverseStep) {
      $results['universe'] = $this->skipPayload($stats, 'universe', $universeEmpty, 'scope');
    } elseif ($usingSde && !$force && !$universeEmpty && !$sdeUpdateAvailable) {
      $results['universe'] = $this->skipPayload($stats, 'universe', false, 'sde_unchanged');
    } elseif (
      $usingSde
      ? ($force || $universeEmpty || $sdeUpdateAvailable)
      : $this->shouldRun($stats, 'universe', (int)$options['ttl_universe'], $force, $universeEmpty)
    ) {
      $results['universe'] = $this->universe->syncUniverse((int)$options['ttl_universe']);
      $stats['universe'] = gmdate('c');
    } else {
      $results['universe'] = $this->skipPayload($stats, 'universe', $universeEmpty);
    }

    $graphEmpty = $this->isStargateGraphEmpty();
    $runGraphStep = $runUniverse && $stepAllowed($allowedSteps, 'stargate_graph');
    if ($runGraphStep) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Stargate graph',
        'stage' => 'stargate_graph',
      ]);
    }
    if (!$runGraphStep) {
      $results['stargate_graph'] = $this->skipPayload($stats, 'stargate_graph', $graphEmpty, 'scope');
    } elseif ($usingSde && !$force && !$graphEmpty && !$sdeUpdateAvailable) {
      $results['stargate_graph'] = $this->skipPayload($stats, 'stargate_graph', false, 'sde_unchanged');
    } elseif (
      $usingSde
      ? ($force || $graphEmpty || $sdeUpdateAvailable)
      : $this->shouldRun($stats, 'stargate_graph', (int)$options['ttl_stargate'], $force, $graphEmpty)
    ) {
      $results['stargate_graph'] = $this->universe->syncStargateGraph((int)$options['ttl_stargate'], true);
      $stats['stargate_graph'] = gmdate('c');
    } else {
      $results['stargate_graph'] = $this->skipPayload($stats, 'stargate_graph', $graphEmpty);
    }

    $runTokenStep = $runCorp && $stepAllowed($allowedSteps, 'token_refresh');
    if ($runTokenStep) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Token refresh',
        'stage' => 'token_refresh',
      ]);
    }
    if (!$runTokenStep) {
      $results['token_refresh'] = $this->skipPayload($stats, 'token_refresh', false, 'scope');
    } elseif ($this->shouldRun($stats, 'token_refresh', (int)$options['ttl_tokens'], $force, false)) {
      $results['token_refresh'] = $this->sso->refreshCorpTokens($corpId);
      $stats['token_refresh'] = gmdate('c');
    } else {
      $results['token_refresh'] = $this->skipPayload($stats, 'token_refresh', false);
    }

    $structuresEmpty = $this->isStructuresEmpty($corpId);
    $runStructuresStep = $runCorp && $stepAllowed($allowedSteps, 'structures');
    if ($runStructuresStep) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Structures',
        'stage' => 'structures',
      ]);
    }
    try {
      if (!$runStructuresStep) {
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

    if ($runCorp && $stepAllowed($allowedSteps, 'public_structures')) {
      $publicStructuresEmpty = $this->isPublicStructuresEmpty();
      if ($allowPublicStructures) {
        $currentStep++;
        $this->reportProgress($progressCb, [
          'current' => $currentStep,
          'total' => $totalSteps,
          'label' => 'Public structures',
          'stage' => 'public_structures',
        ]);
      }
      try {
        if (!$allowPublicStructures) {
          $results['public_structures'] = $this->skipPayload($stats, 'public_structures', $publicStructuresEmpty, 'disabled');
        } elseif ($this->shouldRun($stats, 'public_structures', (int)$options['ttl_public_structures'], $force, $publicStructuresEmpty)) {
          $results['public_structures'] = $this->universe->syncPublicStructures($corpId, $characterId, (int)$options['ttl_public_structures']);
          $stats['public_structures'] = gmdate('c');
        } else {
          $results['public_structures'] = $this->skipPayload($stats, 'public_structures', $publicStructuresEmpty);
        }
      } catch (\Throwable $e) {
        $results['public_structures_error'] = $e->getMessage();
      }
    } else {
      $results['public_structures'] = $this->skipPayload($stats, 'public_structures', false, 'scope');
    }

    $contractsEmpty = $this->isContractsEmpty($corpId);
    $runContractsStep = $runCorp && $stepAllowed($allowedSteps, 'contracts');
    if ($runContractsStep) {
      $currentStep++;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => 'Contracts',
        'stage' => 'contracts',
      ]);
    }
    if (!$runContractsStep) {
      $results['contracts'] = $this->skipPayload($stats, 'contracts', $contractsEmpty, 'scope');
    } elseif ($this->shouldRun($stats, 'contracts', (int)$options['ttl_contracts'], $force, $contractsEmpty)) {
      $contractsMaxRuntime = max(10, (int)$options['contracts_max_runtime']);
      $contractsReconcileMaxRuntime = max(10, (int)$options['contracts_reconcile_max_runtime']);
      $pullStart = microtime(true);
      $results['contracts'] = $this->db->tx(fn($db) => $this->esi->contracts()->pull(
        $corpId,
        $characterId,
        (int)$options['contracts_page_limit'],
        (int)$options['contracts_lookback_hours'],
        [
          'max_runtime_seconds' => $contractsMaxRuntime,
          'on_progress' => function (array $progress) use ($progressCb, $currentStep, $totalSteps): void {
            if (!$progressCb) {
              return;
            }
            $label = $progress['label'] ?? 'Contracts pull progress';
            $progressCb([
              'current' => $currentStep,
              'total' => $totalSteps,
              'label' => $label,
              'stage' => $progress['stage'] ?? 'contracts',
            ]);
          },
        ]
      ));
      $pullMs = (microtime(true) - $pullStart) * 1000;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => sprintf(
          'Contracts pulled: %d fetched, %d upserted, %d items, %d pages in %.0fms (stop: %s)',
          (int)($results['contracts']['fetched_contracts'] ?? 0),
          (int)($results['contracts']['upserted_contracts'] ?? 0),
          (int)($results['contracts']['upserted_items'] ?? 0),
          (int)($results['contracts']['pages'] ?? 0),
          $pullMs,
          (string)($results['contracts']['stop_reason'] ?? 'unknown')
        ),
        'stage' => 'contracts',
      ]);
      $reconcileStart = microtime(true);
      $results['contracts_reconcile'] = $this->esi->contractReconcile()->reconcile($corpId, [
        'max_runtime_seconds' => $contractsReconcileMaxRuntime,
        'on_progress' => function (array $progress) use ($progressCb, $currentStep, $totalSteps): void {
          if (!$progressCb) {
            return;
          }
          $label = $progress['label'] ?? 'Contracts reconcile progress';
          $progressCb([
            'current' => $currentStep,
            'total' => $totalSteps,
            'label' => $label,
            'stage' => $progress['stage'] ?? 'contracts',
          ]);
        },
      ]);
      $reconcileMs = (microtime(true) - $reconcileStart) * 1000;
      $this->reportProgress($progressCb, [
        'current' => $currentStep,
        'total' => $totalSteps,
        'label' => sprintf(
          'Contracts reconcile: %d updated, %d missing in %.0fms (stop: %s)',
          (int)($results['contracts_reconcile']['updated'] ?? 0),
          (int)($results['contracts_reconcile']['missing_contracts'] ?? 0),
          $reconcileMs,
          (string)($results['contracts_reconcile']['stop_reason'] ?? 'unknown')
        ),
        'stage' => 'contracts',
      ]);
      $stats['contracts'] = gmdate('c');
      if ($this->webhooks) {
        try {
          $results['contracts']['status'] = 'completed';
          $this->webhooks->enqueue($corpId, 'esi.contracts.pulled', $results['contracts']);
        } catch (\Throwable $e) {
          // Ignore webhook enqueue failures.
        }
        try {
          $results['contracts_reconcile']['status'] = 'completed';
          $this->webhooks->enqueue($corpId, 'esi.contracts.reconciled', $results['contracts_reconcile']);
        } catch (\Throwable $e) {
          // Ignore webhook enqueue failures.
        }
      }
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

  private function isUniverseEmpty(bool $usingSde): bool
  {
    $regionCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_region");
    $constellationCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_constellation");
    $systemCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM eve_system");
    if (!$usingSde) {
      return $regionCount === 0 || $constellationCount === 0 || $systemCount === 0;
    }
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
