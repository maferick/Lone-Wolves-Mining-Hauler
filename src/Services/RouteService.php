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
    'health' => [],
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
    $graphHealth = $graph['health'] ?? [];
    if (empty($graphHealth['ready'])) {
      $reason = $graphHealth['reason'] ?? 'graph_not_ready';
      throw new RouteException('Graph is not ready for routing.', [
        'reason' => $reason,
        'graph' => $graphHealth,
      ]);
    }

    $fromId = $this->resolveSystemIdByName($fromSystemName);
    $toId = $this->resolveSystemIdByName($toSystemName);

    if (!isset($graph['systems'][$fromId]) || !isset($graph['systems'][$toId])) {
      throw new RouteException('Graph missing system nodes.', [
        'reason' => 'graph_missing_system',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
      ]);
    }

    $profile = $this->normalizeProfile($profile);
    $dnf = $this->loadDnfRules();
    $dnfCounts = $this->countDnfRules($dnf);

    if ($this->isSystemHardBlocked($fromId, $graph['systems'], $dnf) || $this->isSystemHardBlocked($toId, $graph['systems'], $dnf)) {
      throw new RouteException('Pickup or destination blocked by DNF rules.', [
        'reason' => 'pickup_destination_blocked',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        'blocked_count_hard' => $dnfCounts['hard'],
        'blocked_count_soft' => $dnfCounts['soft'],
      ]);
    }

    $result = $this->dijkstra($fromId, $toId, $profile, $graph, $dnf);
    if ($result['found'] === false) {
      $dnfWithoutHard = $this->withoutHardRules($dnf);
      $fallbackResult = $this->dijkstra($fromId, $toId, $profile, $graph, $dnfWithoutHard);
      if ($fallbackResult['found'] === true) {
        throw new RouteException('Route blocked by DNF rules.', [
          'reason' => 'blocked_by_dnf',
          'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
          'blocked_count_hard' => $dnfCounts['hard'],
          'blocked_count_soft' => $dnfCounts['soft'],
        ]);
      }
      throw new RouteException('No viable route found.', [
        'reason' => 'no_viable_route',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        'blocked_count_hard' => $dnfCounts['hard'],
        'blocked_count_soft' => $dnfCounts['soft'],
      ]);
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
    $usedSoftFlag = !empty($usedSoft);

    return [
      'path' => $path,
      'jumps' => max(0, count($pathIds) - 1),
      'hs_count' => $counts['high'],
      'ls_count' => $counts['low'],
      'ns_count' => $counts['null'],
      'used_soft_dnf' => $usedSoftFlag,
      'used_soft_dnf_rules' => array_values($usedSoft),
      'blocked_count_hard' => $dnfCounts['hard'],
      'blocked_count_soft' => $dnfCounts['soft'],
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
    $graphSource = 'map_system';
    $rows = $this->db->select(
      "SELECT system_id, system_name, security, region_id, constellation_id FROM map_system"
    );
    if (empty($rows)) {
      $graphSource = 'eve_system';
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

    $health = $this->buildGraphHealth($systems, $adjacency, count($edges), $graphSource);

    self::$graphCache = [
      'loaded_at' => $now,
      'systems' => $systems,
      'adjacency' => $adjacency,
      'name_to_id' => $nameToId,
      'health' => $health,
    ];

    return self::$graphCache;
  }

  public function resolveSystemIdByName(string $systemName): int
  {
    $normalized = $this->normalizeSystemName($systemName);
    if ($normalized === '') {
      throw new RouteException('System name is required.', [
        'reason' => 'invalid_system_name',
      ]);
    }

    $exactMatches = $this->db->select(
      "SELECT system_id, system_name
         FROM map_system
        WHERE LOWER(system_name) = :name
        LIMIT 2",
      ['name' => $normalized]
    );
    if (count($exactMatches) > 1) {
      throw new RouteException('System name is ambiguous.', [
        'reason' => 'ambiguous_system_name',
        'candidates' => array_map(fn($row) => (string)$row['system_name'], $exactMatches),
      ]);
    }
    if (count($exactMatches) === 1) {
      return (int)$exactMatches[0]['system_id'];
    }

    $matches = $this->db->select(
      "SELECT system_id, system_name
         FROM map_system
        WHERE LOWER(system_name) LIKE :like
        ORDER BY system_name
        LIMIT 10",
      ['like' => '%' . $normalized . '%']
    );
    if (count($matches) > 1) {
      throw new RouteException('System name is ambiguous.', [
        'reason' => 'ambiguous_system_name',
        'candidates' => array_map(fn($row) => (string)$row['system_name'], $matches),
      ]);
    }
    if (count($matches) === 1) {
      return (int)$matches[0]['system_id'];
    }

    $exactMatches = $this->db->select(
      "SELECT system_id, system_name
         FROM eve_system
        WHERE LOWER(system_name) = :name
        LIMIT 2",
      ['name' => $normalized]
    );
    if (count($exactMatches) > 1) {
      throw new RouteException('System name is ambiguous.', [
        'reason' => 'ambiguous_system_name',
        'candidates' => array_map(fn($row) => (string)$row['system_name'], $exactMatches),
      ]);
    }
    if (count($exactMatches) === 1) {
      return (int)$exactMatches[0]['system_id'];
    }

    $matches = $this->db->select(
      "SELECT system_id, system_name
         FROM eve_system
        WHERE LOWER(system_name) LIKE :like
        ORDER BY system_name
        LIMIT 10",
      ['like' => '%' . $normalized . '%']
    );
    if (count($matches) > 1) {
      throw new RouteException('System name is ambiguous.', [
        'reason' => 'ambiguous_system_name',
        'candidates' => array_map(fn($row) => (string)$row['system_name'], $matches),
      ]);
    }
    if (count($matches) === 1) {
      return (int)$matches[0]['system_id'];
    }

    throw new RouteException('Unknown system name.', [
      'reason' => 'unknown_system_name',
    ]);
  }

  public function getGraphStatus(): array
  {
    $graph = $this->loadGraph();
    return $graph['health'] ?? [];
  }

  public function getNodeDegree(int $systemId): int
  {
    $graph = $this->loadGraph();
    if (!isset($graph['adjacency'][$systemId])) {
      return 0;
    }
    return count($graph['adjacency'][$systemId]);
  }

  private function normalizeSystemName(string $name): string
  {
    $normalized = trim($name);
    if ($normalized === '') {
      return '';
    }
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return strtolower((string)$normalized);
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

  private function countDnfRules(array $dnf): array
  {
    $hard = 0;
    $soft = 0;
    foreach (['system', 'constellation', 'region', 'edge'] as $scope) {
      $hard += count($dnf['hard'][$scope] ?? []);
      $soft += count($dnf['soft'][$scope] ?? []);
    }
    return ['hard' => $hard, 'soft' => $soft];
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

  private function buildGraphHealth(array $systems, array $adjacency, int $edgeCount, string $graphSource): array
  {
    $nodeCount = count($systems);
    $health = [
      'node_count' => $nodeCount,
      'edge_count' => $edgeCount,
      'graph_source' => $graphSource,
      'graph_loaded' => $nodeCount > 0 && $edgeCount > 0,
      'ready' => false,
      'reason' => null,
      'warnings' => [],
      'errors' => [],
      'checked_systems' => [],
    ];

    if ($nodeCount === 0 || $edgeCount === 0) {
      $health['reason'] = 'graph_empty';
      $health['errors'][] = 'Graph tables are empty.';
      return $health;
    }

    $nodeCountTooSmall = false;
    if ($nodeCount < 1000) {
      $health['errors'][] = 'Graph node count below expected minimum.';
      $nodeCountTooSmall = true;
    }

    $criticalNames = ['Eldjaerin', 'Jita'];
    foreach ($criticalNames as $name) {
      $row = $this->db->one(
        "SELECT system_id, system_name
           FROM map_system
          WHERE LOWER(system_name) = LOWER(:name)
          LIMIT 1",
        ['name' => $name]
      );
      if ($row === null) {
        $health['warnings'][] = sprintf('System %s not found in map_system.', $name);
        $health['checked_systems'][$name] = [
          'system_id' => null,
          'degree' => 0,
          'present' => false,
        ];
        continue;
      }

      $systemId = (int)$row['system_id'];
      $degree = isset($adjacency[$systemId]) ? count($adjacency[$systemId]) : 0;
      $health['checked_systems'][$name] = [
        'system_id' => $systemId,
        'degree' => $degree,
        'present' => true,
      ];
      if ($degree === 0) {
        $health['errors'][] = sprintf('Graph missing edges for system %s.', $name);
      }
    }

    if (!empty($health['errors'])) {
      $health['reason'] = $nodeCountTooSmall ? 'graph_too_small' : 'graph_missing_edges';
      return $health;
    }

    $health['ready'] = true;
    return $health;
  }
}
