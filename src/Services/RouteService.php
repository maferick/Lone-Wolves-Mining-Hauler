<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class RouteService
{
  private Db $db;
  private array $config;
  private int $cacheTtl;

  private const PROFILE_PENALTIES = [
    'shortest' => ['low' => 0.0, 'null' => 0.0],
    'balanced' => ['low' => 2.0, 'null' => 6.0],
    'safest' => ['low' => 5.0, 'null' => 15.0],
  ];

  private const SECURITY_HIGHSEC_MIN = 0.5;
  private const SECURITY_LOWSEC_MIN = 0.1;

  private static array $graphCache = [
    'loaded_at' => 0,
    'systems' => [],
    'adjacency' => [],
    'name_to_id' => [],
  ];

  public function __construct(Db $db, array $config = [])
  {
    $this->db = $db;
    $this->config = $config;
    $this->cacheTtl = (int)($config['routing']['cache_ttl_seconds'] ?? 300);
  }

  public function findRoute(string $fromSystemName, string $toSystemName, string $profile = 'shortest'): array
  {
    $graph = $this->loadGraph();
    $fromId = $this->resolveSystemId($fromSystemName, $graph);
    $toId = $this->resolveSystemId($toSystemName, $graph);

    if ($fromId === 0 || $toId === 0) {
      throw new \RuntimeException('Unknown system name.');
    }

    $profile = $this->normalizeProfile($profile);
    $dnf = $this->loadDnfRules();

    if ($this->isSystemHardBlocked($fromId, $graph['systems'], $dnf) || $this->isSystemHardBlocked($toId, $graph['systems'], $dnf)) {
      throw new \RuntimeException('Route blocked by DNF rules.');
    }

    $result = $this->dijkstra($fromId, $toId, $profile, $graph, $dnf);
    if ($result['found'] === false) {
      $dnfWithoutHard = $this->withoutHardRules($dnf);
      $fallbackResult = $this->dijkstra($fromId, $toId, $profile, $graph, $dnfWithoutHard);
      if ($fallbackResult['found'] === true) {
        throw new \RuntimeException('Route blocked by DNF rules.');
      }
      throw new \RuntimeException('No viable route found.');
    }

    $pathIds = $this->buildPath($fromId, $toId, $result['prev']);
    $path = [];
    foreach ($pathIds as $systemId) {
      $system = $graph['systems'][$systemId] ?? null;
      if ($system === null) {
        continue;
      }
      $path[] = [
        'system_id' => $systemId,
        'system_name' => $system['system_name'],
        'security' => $system['security'],
      ];
    }

    $counts = $this->countSecurityComposition($pathIds, $graph['systems']);
    $usedSoft = $this->collectSoftRules($pathIds, $graph['systems'], $dnf);

    return [
      'path' => $path,
      'jumps' => max(0, count($pathIds) - 1),
      'hs_count' => $counts['high'],
      'ls_count' => $counts['low'],
      'ns_count' => $counts['null'],
      'used_soft_dnf' => array_values($usedSoft),
      'profile' => $profile,
    ];
  }

  private function loadGraph(): array
  {
    $now = time();
    if (self::$graphCache['loaded_at'] > 0 && ($now - self::$graphCache['loaded_at']) < $this->cacheTtl) {
      return self::$graphCache;
    }

    $systems = [];
    $nameToId = [];
    $rows = $this->db->select(
      "SELECT system_id, system_name, security, region_id, constellation_id FROM map_system"
    );
    if (empty($rows)) {
      $rows = $this->db->select(
        "SELECT s.system_id,
                s.system_name,
                s.security_status AS security,
                c.region_id,
                s.constellation_id
           FROM eve_system s
           JOIN eve_constellation c ON c.constellation_id = s.constellation_id"
      );
    }
    foreach ($rows as $row) {
      $systemId = (int)$row['system_id'];
      $systems[$systemId] = [
        'system_id' => $systemId,
        'system_name' => (string)$row['system_name'],
        'security' => (float)$row['security'],
        'region_id' => (int)$row['region_id'],
        'constellation_id' => (int)$row['constellation_id'],
      ];
      $nameToId[strtolower((string)$row['system_name'])] = $systemId;
    }

    $adjacency = [];
    $edges = $this->db->select(
      "SELECT from_system_id, to_system_id FROM map_edge"
    );
    foreach ($edges as $edge) {
      $from = (int)$edge['from_system_id'];
      $to = (int)$edge['to_system_id'];
      if ($from === 0 || $to === 0) {
        continue;
      }
      $adjacency[$from][$to] = true;
      $adjacency[$to][$from] = true;
    }

    self::$graphCache = [
      'loaded_at' => $now,
      'systems' => $systems,
      'adjacency' => $adjacency,
      'name_to_id' => $nameToId,
    ];

    return self::$graphCache;
  }

  private function resolveSystemId(string $systemName, array $graph): int
  {
    $key = strtolower(trim($systemName));
    if ($key === '') {
      return 0;
    }

    return (int)($graph['name_to_id'][$key] ?? 0);
  }

  private function normalizeProfile(string $profile): string
  {
    $profile = strtolower(trim($profile));
    if (!array_key_exists($profile, self::PROFILE_PENALTIES)) {
      return 'shortest';
    }
    return $profile;
  }

  private function dijkstra(int $fromId, int $toId, string $profile, array $graph, array $dnf): array
  {
    $dist = [];
    $prev = [];
    $visited = [];

    $queue = new \SplPriorityQueue();
    $queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);

    $dist[$fromId] = 0.0;
    $queue->insert($fromId, 0.0);

    while (!$queue->isEmpty()) {
      $current = $queue->extract();
      $currentId = $current['data'];
      $currentCost = -$current['priority'];

      if (isset($visited[$currentId])) {
        continue;
      }
      $visited[$currentId] = true;

      if ($currentId === $toId) {
        break;
      }

      $neighbors = $graph['adjacency'][$currentId] ?? [];
      foreach ($neighbors as $neighborId => $_) {
        if ($this->isSystemHardBlocked($neighborId, $graph['systems'], $dnf)) {
          continue;
        }
        if ($this->isEdgeHardBlocked($currentId, $neighborId, $dnf)) {
          continue;
        }

        $penalty = $this->securityPenalty($neighborId, $graph['systems'], $profile);
        $penalty += $this->softPenalty($currentId, $neighborId, $graph['systems'], $dnf);
        $cost = $currentCost + 1.0 + $penalty;

        if (!isset($dist[$neighborId]) || $cost < $dist[$neighborId]) {
          $dist[$neighborId] = $cost;
          $prev[$neighborId] = $currentId;
          $queue->insert($neighborId, -$cost);
        }
      }
    }

    return [
      'found' => isset($dist[$toId]),
      'prev' => $prev,
      'dist' => $dist,
    ];
  }

  private function buildPath(int $fromId, int $toId, array $prev): array
  {
    $path = [$toId];
    $current = $toId;
    while ($current !== $fromId) {
      if (!isset($prev[$current])) {
        return [];
      }
      $current = $prev[$current];
      array_unshift($path, $current);
    }
    return $path;
  }

  private function loadDnfRules(): array
  {
    $rows = $this->db->select(
      "SELECT dnf_rule_id, scope_type, id_a, id_b, severity, is_hard_block, reason
         FROM dnf_rule
        WHERE active = 1"
    );

    $soft = [
      'system' => [],
      'constellation' => [],
      'region' => [],
      'edge' => [],
    ];
    $hard = [
      'system' => [],
      'constellation' => [],
      'region' => [],
      'edge' => [],
    ];

    foreach ($rows as $row) {
      $rule = [
        'dnf_rule_id' => (int)$row['dnf_rule_id'],
        'scope_type' => (string)$row['scope_type'],
        'id_a' => (int)$row['id_a'],
        'id_b' => (int)$row['id_b'],
        'severity' => (int)$row['severity'],
        'is_hard_block' => (int)$row['is_hard_block'] === 1,
        'reason' => (string)($row['reason'] ?? ''),
      ];

      $scope = $rule['scope_type'];
      if (!isset($soft[$scope])) {
        continue;
      }

      if ($rule['is_hard_block']) {
        if ($scope === 'edge') {
          $key = $this->edgeKey($rule['id_a'], $rule['id_b']);
          $hard['edge'][$key] = $rule;
        } else {
          $hard[$scope][$rule['id_a']] = $rule;
        }
      } else {
        if ($scope === 'edge') {
          $key = $this->edgeKey($rule['id_a'], $rule['id_b']);
          $soft['edge'][$key] = $rule;
        } else {
          $soft[$scope][$rule['id_a']] = $rule;
        }
      }
    }

    return [
      'soft' => $soft,
      'hard' => $hard,
    ];
  }

  private function withoutHardRules(array $dnf): array
  {
    $dnf['hard'] = [
      'system' => [],
      'constellation' => [],
      'region' => [],
      'edge' => [],
    ];
    return $dnf;
  }

  private function isSystemHardBlocked(int $systemId, array $systems, array $dnf): bool
  {
    if (isset($dnf['hard']['system'][$systemId])) {
      return true;
    }
    $system = $systems[$systemId] ?? null;
    if ($system === null) {
      return false;
    }
    if (isset($dnf['hard']['constellation'][$system['constellation_id']])) {
      return true;
    }
    if (isset($dnf['hard']['region'][$system['region_id']])) {
      return true;
    }
    return false;
  }

  private function isEdgeHardBlocked(int $fromId, int $toId, array $dnf): bool
  {
    $key = $this->edgeKey($fromId, $toId);
    return isset($dnf['hard']['edge'][$key]);
  }

  private function securityPenalty(int $systemId, array $systems, string $profile): float
  {
    $system = $systems[$systemId] ?? null;
    if ($system === null) {
      return 0.0;
    }

    $security = (float)$system['security'];
    $penalties = self::PROFILE_PENALTIES[$profile];

    if ($security < self::SECURITY_LOWSEC_MIN) {
      return $penalties['null'];
    }
    if ($security < self::SECURITY_HIGHSEC_MIN) {
      return $penalties['low'];
    }
    return 0.0;
  }

  private function softPenalty(int $fromId, int $toId, array $systems, array $dnf): float
  {
    $penalty = 0.0;
    $system = $systems[$toId] ?? null;
    if ($system !== null) {
      $penalty += $this->rulePenalty($dnf['soft']['system'][$toId] ?? null);
      $penalty += $this->rulePenalty($dnf['soft']['constellation'][$system['constellation_id']] ?? null);
      $penalty += $this->rulePenalty($dnf['soft']['region'][$system['region_id']] ?? null);
    }

    $edgeKey = $this->edgeKey($fromId, $toId);
    $penalty += $this->rulePenalty($dnf['soft']['edge'][$edgeKey] ?? null);

    return $penalty;
  }

  private function rulePenalty(?array $rule): float
  {
    if ($rule === null) {
      return 0.0;
    }
    $severity = (int)($rule['severity'] ?? 1);
    if ($severity <= 0) {
      $severity = 1;
    }
    return 3.0 * $severity;
  }

  private function countSecurityComposition(array $pathIds, array $systems): array
  {
    $counts = ['high' => 0, 'low' => 0, 'null' => 0];
    foreach ($pathIds as $systemId) {
      $system = $systems[$systemId] ?? null;
      if ($system === null) {
        continue;
      }
      $security = (float)$system['security'];
      if ($security < self::SECURITY_LOWSEC_MIN) {
        $counts['null']++;
      } elseif ($security < self::SECURITY_HIGHSEC_MIN) {
        $counts['low']++;
      } else {
        $counts['high']++;
      }
    }
    return $counts;
  }

  private function collectSoftRules(array $pathIds, array $systems, array $dnf): array
  {
    $used = [];
    $count = count($pathIds);
    for ($i = 0; $i < $count; $i++) {
      $systemId = $pathIds[$i];
      $system = $systems[$systemId] ?? null;
      if ($system !== null) {
        $this->maybeAddRule($used, $dnf['soft']['system'][$systemId] ?? null);
        $this->maybeAddRule($used, $dnf['soft']['constellation'][$system['constellation_id']] ?? null);
        $this->maybeAddRule($used, $dnf['soft']['region'][$system['region_id']] ?? null);
      }
      if ($i > 0) {
        $prevId = $pathIds[$i - 1];
        $edgeKey = $this->edgeKey($prevId, $systemId);
        $this->maybeAddRule($used, $dnf['soft']['edge'][$edgeKey] ?? null);
      }
    }
    return $used;
  }

  private function maybeAddRule(array &$used, ?array $rule): void
  {
    if ($rule === null) {
      return;
    }
    $ruleId = (int)$rule['dnf_rule_id'];
    if ($ruleId <= 0) {
      return;
    }
    if (!isset($used[$ruleId])) {
      $used[$ruleId] = [
        'dnf_rule_id' => $ruleId,
        'scope_type' => $rule['scope_type'],
        'id_a' => $rule['id_a'],
        'id_b' => $rule['id_b'],
        'severity' => $rule['severity'],
        'reason' => $rule['reason'],
      ];
    }
  }

  private function edgeKey(int $fromId, int $toId): string
  {
    $a = min($fromId, $toId);
    $b = max($fromId, $toId);
    return $a . ':' . $b;
  }
}
