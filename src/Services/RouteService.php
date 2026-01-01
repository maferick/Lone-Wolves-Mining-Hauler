<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class RouteService
{
  private Db $db;
  private array $config;
  private int $cacheTtl;
  private ?EsiRouteService $esiRouteService;

  private const PROFILE_PENALTIES = [
    'balanced' => ['low' => 2.0, 'null' => 6.0],
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

  public function __construct(Db $db, array $config = [], ?EsiRouteService $esiRouteService = null)
  {
    $this->db = $db;
    $this->config = $config;
    $this->cacheTtl = (int)($config['routing']['cache_ttl_seconds'] ?? 300);
    $this->esiRouteService = $esiRouteService;
  }

  public function findRoute(
    string $fromSystemName,
    string $toSystemName,
    string $profile = 'balanced',
    array $context = []
  ): array
  {
    $graph = $this->loadGraph();
    $graphHealth = $graph['health'] ?? [];
    $graphReady = !empty($graphHealth['ready']);

    $fromId = $this->resolveSystemIdByName($fromSystemName);
    $toId = $this->resolveSystemIdByName($toSystemName);

    $profile = $this->normalizeProfile($profile);
    $dnf = $this->loadDnfRules();
    $dnfCounts = $this->countDnfRules($dnf);
    $hardAvoidSystemIds = $this->collectHardAvoidSystemIds($dnf, $graph['systems']);
    $avoidSystemIds = $this->mergeSecurityAvoids($hardAvoidSystemIds, $profile, $graph['systems']);
    $avoidCount = count($avoidSystemIds);
    $accessAllowlist = $this->buildAccessAllowlist($graph['systems'], $context);
    $hasAccessAllowlist = !empty($accessAllowlist);

    if ($profile === 'safest') {
      $fromSecurity = $this->normalizedSecurity((float)($graph['systems'][$fromId]['security'] ?? 0.0));
      $toSecurity = $this->normalizedSecurity((float)($graph['systems'][$toId]['security'] ?? 0.0));
      if ($fromSecurity < self::SECURITY_HIGHSEC_MIN || $toSecurity < self::SECURITY_HIGHSEC_MIN) {
        throw new RouteException('Pickup or destination is not high-sec for safest routing.', [
          'reason' => 'pickup_destination_not_highsec',
          'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        ]);
      }
    }

    if ($hasAccessAllowlist && (!$this->isSystemAllowed($fromId, $accessAllowlist) || !$this->isSystemAllowed($toId, $accessAllowlist))) {
      throw new RouteException('Pickup or destination not allowed by access rules.', [
        'reason' => 'pickup_destination_not_allowed',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
      ]);
    }

    if (!$graphReady) {
      $reason = $graphHealth['reason'] ?? 'graph_not_ready';
      return $this->fallbackToCcpRoute(
        $fromId,
        $toId,
        $profile,
        $dnf,
        $dnfCounts,
        $hardAvoidSystemIds,
        $avoidCount,
        array_merge($context, ['graph_reason' => $reason])
      );
    }

    if (!isset($graph['systems'][$fromId]) || !isset($graph['systems'][$toId])) {
      return $this->fallbackToCcpRoute(
        $fromId,
        $toId,
        $profile,
        $dnf,
        $dnfCounts,
        $hardAvoidSystemIds,
        $avoidCount,
        array_merge($context, ['graph_reason' => 'graph_missing_system'])
      );
    }

    if ($this->isSystemHardBlocked($fromId, $graph['systems'], $dnf) || $this->isSystemHardBlocked($toId, $graph['systems'], $dnf)) {
      throw new RouteException('Pickup or destination blocked by DNF rules.', [
        'reason' => 'pickup_destination_blocked',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        'blocked_count_hard' => $dnfCounts['hard'],
        'blocked_count_soft' => $dnfCounts['soft'],
      ]);
    }

    $result = $this->dijkstra($fromId, $toId, $profile, $graph, $dnf, $accessAllowlist);
    if ($result['found'] === false) {
      $dnfWithoutHard = $this->withoutHardRules($dnf);
      $fallbackResult = $this->dijkstra($fromId, $toId, $profile, $graph, $dnfWithoutHard, $accessAllowlist);
      if ($fallbackResult['found'] === true) {
        throw new RouteException('Route blocked by DNF rules.', [
          'reason' => 'blocked_by_dnf',
          'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
          'blocked_count_hard' => $dnfCounts['hard'],
          'blocked_count_soft' => $dnfCounts['soft'],
        ]);
      }
      throw new RouteException('No viable route found (local).', [
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
      'route_profile' => $profile,
      'route_source' => 'local',
      'avoid_count' => $avoidCount,
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
    if (in_array($profile, ['normal', 'high'], true)) {
      return 'balanced';
    }
    if (!array_key_exists($profile, self::PROFILE_PENALTIES)) {
      return 'balanced';
    }
    return $profile;
  }

  private function dijkstra(
    int $fromId,
    int $toId,
    string $profile,
    array $graph,
    array $dnf,
    array $accessAllowlist = []
  ): array
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
        if (!$this->isSystemAllowed($neighborId, $accessAllowlist)) {
          continue;
        }
        if ($this->isSystemHardBlocked($neighborId, $graph['systems'], $dnf)) {
          continue;
        }
        if ($this->isProfileSecurityBlocked($neighborId, $graph['systems'], $profile)) {
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

  private function buildAccessAllowlist(array $systems, array $context): array
  {
    $allowedSystemIds = array_values(array_filter(array_map('intval', $context['access_system_ids'] ?? [])));
    $allowedRegionIds = array_values(array_filter(array_map('intval', $context['access_region_ids'] ?? [])));

    if (empty($allowedSystemIds) && empty($allowedRegionIds)) {
      return [];
    }

    $allow = [];
    foreach ($allowedSystemIds as $systemId) {
      $allow[$systemId] = true;
    }
    if (!empty($allowedRegionIds)) {
      foreach ($systems as $systemId => $system) {
        if (in_array((int)($system['region_id'] ?? 0), $allowedRegionIds, true)) {
          $allow[(int)$systemId] = true;
        }
      }
    }

    return $allow;
  }

  private function isSystemAllowed(int $systemId, array $allowlist): bool
  {
    if (empty($allowlist)) {
      return true;
    }
    return isset($allowlist[$systemId]);
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

    $security = $this->normalizedSecurity((float)$system['security']);
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
      $security = $this->normalizedSecurity((float)$system['security']);
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

  private function fallbackToCcpRoute(
    int $fromId,
    int $toId,
    string $profile,
    array $dnf,
    array $dnfCounts,
    array $avoidSystemIds,
    int $avoidCount,
    array $context
  ): array {
    if ($this->esiRouteService === null) {
      throw new RouteException('No viable route found (local+CCP)', [
        'reason' => 'no_viable_route',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        'blocked_count_hard' => $dnfCounts['hard'],
        'blocked_count_soft' => $dnfCounts['soft'],
      ]);
    }

    $corpId = isset($context['corp_id']) ? (int)$context['corp_id'] : null;
    $flag = $this->mapProfileToCcpFlag($profile);

    try {
      $routeIds = $this->esiRouteService->fetchRouteSystemIds($fromId, $toId, $flag, $avoidSystemIds, $corpId);
    } catch (\Throwable $e) {
      throw new RouteException('CCP route unavailable, and local graph had no route', [
        'reason' => 'ccp_route_unavailable',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        'blocked_count_hard' => $dnfCounts['hard'],
        'blocked_count_soft' => $dnfCounts['soft'],
        'esi_error' => $e->getMessage(),
      ]);
    }

    if (empty($routeIds)) {
      throw new RouteException('No viable route found (local+CCP)', [
        'reason' => 'no_viable_route',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        'blocked_count_hard' => $dnfCounts['hard'],
        'blocked_count_soft' => $dnfCounts['soft'],
      ]);
    }

    $systemLookup = $this->loadSystemDetails($routeIds);
    $accessAllowlist = $this->buildAccessAllowlist($systemLookup, $context);
    if (!empty($accessAllowlist)) {
      foreach ($routeIds as $systemId) {
        if (!$this->isSystemAllowed((int)$systemId, $accessAllowlist)) {
          throw new RouteException('Route not allowed by access rules.', [
            'reason' => 'route_not_allowed',
            'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
            'blocked_count_hard' => $dnfCounts['hard'],
            'blocked_count_soft' => $dnfCounts['soft'],
          ]);
        }
      }
    }
    if ($this->routeViolatesHardDnf($routeIds, $systemLookup, $dnf)) {
      error_log('CCP route violated hard DNF rules.');
      throw new RouteException('No viable route found (local+CCP)', [
        'reason' => 'ccp_route_contains_hard_dnf',
        'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
        'blocked_count_hard' => $dnfCounts['hard'],
        'blocked_count_soft' => $dnfCounts['soft'],
      ]);
    }

    $path = [];
    foreach ($routeIds as $systemId) {
      $system = $systemLookup[$systemId] ?? null;
      if ($system === null) {
        $path[] = [
          'system_id' => $systemId,
          'system_name' => 'Unknown',
          'security' => 0.0,
        ];
        continue;
      }
      $path[] = [
        'system_id' => $systemId,
        'system_name' => $system['system_name'],
        'security' => $system['security'],
      ];
    }

    if ($profile === 'safest') {
      foreach ($systemLookup as $system) {
        if ($this->normalizedSecurity((float)($system['security'] ?? 0.0)) < self::SECURITY_HIGHSEC_MIN) {
          throw new RouteException('CCP route included low/null-sec systems.', [
            'reason' => 'ccp_route_not_highsec',
            'resolved_ids' => ['pickup' => $fromId, 'destination' => $toId],
            'blocked_count_hard' => $dnfCounts['hard'],
            'blocked_count_soft' => $dnfCounts['soft'],
          ]);
        }
      }
    }

    $counts = $this->countSecurityComposition($routeIds, $systemLookup);

    if ($this->isBackfillEnabled($corpId)) {
      $this->backfillRouteEdges($routeIds, $systemLookup, $context);
    }

    return [
      'path' => $path,
      'jumps' => max(0, count($routeIds) - 1),
      'hs_count' => $counts['high'],
      'ls_count' => $counts['low'],
      'ns_count' => $counts['null'],
      'used_soft_dnf' => false,
      'used_soft_dnf_rules' => [],
      'blocked_count_hard' => $dnfCounts['hard'],
      'blocked_count_soft' => $dnfCounts['soft'],
      'profile' => $profile,
      'route_profile' => $profile,
      'route_source' => 'ccp',
      'avoid_count' => $avoidCount,
    ];
  }

  private function mapProfileToCcpFlag(string $profile): string
  {
    return match ($profile) {
      'balanced' => 'secure',
      default => 'secure',
    };
  }

  private function collectHardAvoidSystemIds(array $dnf, array $systems): array
  {
    $avoid = [];
    foreach ($dnf['hard']['system'] ?? [] as $systemId => $_rule) {
      $avoid[] = (int)$systemId;
    }

    $constellations = array_keys($dnf['hard']['constellation'] ?? []);
    $regions = array_keys($dnf['hard']['region'] ?? []);

    if (!empty($constellations) || !empty($regions)) {
      foreach ($systems as $systemId => $system) {
        if (!empty($constellations) && in_array((int)$system['constellation_id'], $constellations, true)) {
          $avoid[] = (int)$systemId;
        }
        if (!empty($regions) && in_array((int)$system['region_id'], $regions, true)) {
          $avoid[] = (int)$systemId;
        }
      }
    }

    return array_values(array_unique($avoid));
  }

  private function loadSystemDetails(array $systemIds): array
  {
    $systemIds = array_values(array_unique(array_map('intval', $systemIds)));
    if (empty($systemIds)) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($systemIds), '?'));
    $mapRows = $this->db->select(
      "SELECT system_id, system_name, security, region_id, constellation_id
         FROM map_system
        WHERE system_id IN ({$placeholders})",
      $systemIds
    );

    $details = [];
    foreach ($mapRows as $row) {
      $systemId = (int)$row['system_id'];
      $details[$systemId] = [
        'system_id' => $systemId,
        'system_name' => (string)$row['system_name'],
        'security' => (float)$row['security'],
        'region_id' => (int)$row['region_id'],
        'constellation_id' => (int)$row['constellation_id'],
      ];
    }

    $missing = array_diff($systemIds, array_keys($details));
    if (!empty($missing)) {
      $placeholders = implode(',', array_fill(0, count($missing), '?'));
      $eveRows = $this->db->select(
        "SELECT s.system_id,
                s.system_name,
                s.security_status AS security,
                c.region_id,
                s.constellation_id
           FROM eve_system s
           JOIN eve_constellation c ON c.constellation_id = s.constellation_id
          WHERE s.system_id IN ({$placeholders})",
        array_values($missing)
      );
      foreach ($eveRows as $row) {
        $systemId = (int)$row['system_id'];
        $details[$systemId] = [
          'system_id' => $systemId,
          'system_name' => (string)$row['system_name'],
          'security' => (float)$row['security'],
          'region_id' => (int)$row['region_id'],
          'constellation_id' => (int)$row['constellation_id'],
        ];
      }
    }

    $missing = array_diff($systemIds, array_keys($details));
    if (!empty($missing)) {
      $placeholders = implode(',', array_fill(0, count($missing), '?'));
      $entityRows = $this->db->select(
        "SELECT entity_id, name
           FROM eve_entity
          WHERE entity_type = 'system' AND entity_id IN ({$placeholders})",
        array_values($missing)
      );
      foreach ($entityRows as $row) {
        $systemId = (int)$row['entity_id'];
        $details[$systemId] = [
          'system_id' => $systemId,
          'system_name' => (string)$row['name'],
          'security' => 0.0,
          'region_id' => null,
          'constellation_id' => null,
        ];
      }
    }

    $missing = array_diff($systemIds, array_keys($details));
    foreach ($missing as $systemId) {
      $details[$systemId] = [
        'system_id' => (int)$systemId,
        'system_name' => 'Unknown',
        'security' => 0.0,
        'region_id' => null,
        'constellation_id' => null,
      ];
    }

    return $details;
  }

  private function isProfileSecurityBlocked(int $systemId, array $systems, string $profile): bool
  {
    if ($profile !== 'safest') {
      return false;
    }
    $security = $this->normalizedSecurity((float)($systems[$systemId]['security'] ?? 0.0));
    return $security < self::SECURITY_HIGHSEC_MIN;
  }

  private function mergeSecurityAvoids(array $avoidSystemIds, string $profile, array $systems): array
  {
    if ($profile !== 'safest') {
      return $avoidSystemIds;
    }

    foreach ($systems as $systemId => $system) {
      if ($this->normalizedSecurity((float)($system['security'] ?? 0.0)) < self::SECURITY_HIGHSEC_MIN) {
        $avoidSystemIds[] = (int)$systemId;
      }
    }

    return array_values(array_unique($avoidSystemIds));
  }

  private function routeViolatesHardDnf(array $routeIds, array $systems, array $dnf): bool
  {
    foreach ($routeIds as $systemId) {
      if ($this->isSystemHardBlocked($systemId, $systems, $dnf)) {
        return true;
      }
    }

    $count = count($routeIds);
    for ($i = 1; $i < $count; $i++) {
      if ($this->isEdgeHardBlocked($routeIds[$i - 1], $routeIds[$i], $dnf)) {
        return true;
      }
    }

    return false;
  }

  private function normalizedSecurity(float $security): float
  {
    return round($security, 1);
  }

  private function isBackfillEnabled(?int $corpId): bool
  {
    $settingRow = null;
    if ($corpId !== null) {
      $settingRow = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.backfill_edges' LIMIT 1",
        ['cid' => $corpId]
      );
    }
    if ($settingRow === null) {
      $settingRow = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.backfill_edges' LIMIT 1"
      );
    }

    if ($settingRow && !empty($settingRow['setting_json'])) {
      $decoded = Db::jsonDecode((string)$settingRow['setting_json'], null);
      if (is_bool($decoded)) {
        return $decoded;
      }
      if (is_array($decoded) && isset($decoded['enabled'])) {
        return (bool)$decoded['enabled'];
      }
    }

    return ($this->config['app']['env'] ?? 'dev') === 'dev';
  }

  private function backfillRouteEdges(array $routeIds, array $systems, array $context): void
  {
    try {
      $this->db->tx(function (Db $db) use ($routeIds, $systems, $context) {
        $this->backfillSystems($db, $routeIds, $systems, $context);
        $this->backfillEdges($db, $routeIds, $context);
      });
    } catch (\Throwable $e) {
      error_log('Route backfill failed: ' . $e->getMessage());
    }

    self::$graphCache['loaded_at'] = 0;
  }

  private function backfillSystems(Db $db, array $routeIds, array $systems, array $context): void
  {
    $existing = $db->select(
      "SELECT system_id FROM map_system WHERE system_id IN (" . implode(',', array_fill(0, count($routeIds), '?')) . ")",
      array_values($routeIds)
    );
    $existingIds = array_map(fn($row) => (int)$row['system_id'], $existing);
    $missing = array_values(array_diff($routeIds, $existingIds));
    if (empty($missing)) {
      return;
    }

    $columns = $this->getMapSystemColumns();
    $baseColumns = array_intersect(['system_id', 'system_name', 'security', 'region_id', 'constellation_id', 'updated_at'], $columns);

    foreach ($missing as $systemId) {
      $system = $systems[$systemId] ?? [
        'system_name' => 'Unknown',
        'security' => 0.0,
        'region_id' => null,
        'constellation_id' => null,
      ];

      $data = [
        'system_id' => $systemId,
        'system_name' => (string)($system['system_name'] ?? 'Unknown'),
        'security' => (float)($system['security'] ?? 0.0),
        'region_id' => $system['region_id'] ?? null,
        'constellation_id' => $system['constellation_id'] ?? null,
      ];

      if (in_array('updated_at', $baseColumns, true)) {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
      }

      $columnsToInsert = array_keys(array_intersect_key($data, array_flip($baseColumns)));
      if (empty($columnsToInsert)) {
        continue;
      }

      $placeholders = implode(',', array_map(fn($col) => ':' . $col, $columnsToInsert));
      $colList = implode(',', $columnsToInsert);
      $db->execute(
        "INSERT IGNORE INTO map_system ({$colList}) VALUES ({$placeholders})",
        array_intersect_key($data, array_flip($columnsToInsert))
      );

      $this->auditBackfill($db, $context, 'map_system', (string)$systemId, null, $data);
    }
  }

  private function backfillEdges(Db $db, array $routeIds, array $context): void
  {
    $columns = $this->getMapEdgeColumns();
    $baseColumns = array_intersect(['from_system_id', 'to_system_id', 'edge_type', 'weight', 'updated_at'], $columns);

    $count = count($routeIds);
    for ($i = 1; $i < $count; $i++) {
      $from = (int)$routeIds[$i - 1];
      $to = (int)$routeIds[$i];
      foreach ([[$from, $to], [$to, $from]] as $pair) {
        [$a, $b] = $pair;
        $data = [
          'from_system_id' => $a,
          'to_system_id' => $b,
          'edge_type' => 'stargate',
          'weight' => 1,
        ];
        if (in_array('updated_at', $baseColumns, true)) {
          $data['updated_at'] = gmdate('Y-m-d H:i:s');
        }

        $columnsToInsert = array_keys(array_intersect_key($data, array_flip($baseColumns)));
        if (empty($columnsToInsert)) {
          continue;
        }

        $placeholders = implode(',', array_map(fn($col) => ':' . $col, $columnsToInsert));
        $colList = implode(',', $columnsToInsert);
        $db->execute(
          "INSERT IGNORE INTO map_edge ({$colList}) VALUES ({$placeholders})",
          array_intersect_key($data, array_flip($columnsToInsert))
        );

        $this->auditBackfill($db, $context, 'map_edge', $a . ':' . $b, null, $data);
      }
    }
  }

  private function getMapEdgeColumns(): array
  {
    static $columns = null;
    if ($columns !== null) {
      return $columns;
    }
    $rows = $this->db->select("SHOW COLUMNS FROM map_edge");
    $columns = array_map(fn($row) => (string)$row['Field'], $rows);
    return $columns;
  }

  private function getMapSystemColumns(): array
  {
    static $columns = null;
    if ($columns !== null) {
      return $columns;
    }
    $rows = $this->db->select("SHOW COLUMNS FROM map_system");
    $columns = array_map(fn($row) => (string)$row['Field'], $rows);
    return $columns;
  }

  private function auditBackfill(Db $db, array $context, string $table, string $pk, $before, $after): void
  {
    $db->audit(
      isset($context['corp_id']) ? (int)$context['corp_id'] : null,
      isset($context['actor_user_id']) ? (int)$context['actor_user_id'] : null,
      isset($context['actor_character_id']) ? (int)$context['actor_character_id'] : null,
      'map.backfill_edge',
      $table,
      $pk,
      $before,
      $after,
      $context['ip_address'] ?? null,
      $context['user_agent'] ?? null
    );
  }
}
