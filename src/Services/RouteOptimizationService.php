<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class RouteOptimizationService
{
  private const SHIP_CLASS_CAPACITY = [
    'BR' => 12500,
    'DST' => 62500,
    'JF' => 360000,
    'FREIGHTER' => 950000,
  ];

  private const DEFAULT_SETTINGS = [
    'enabled' => true,
    'detour_budget_jumps' => 5,
    'max_suggestions' => 5,
    'min_free_capacity_percent' => 10,
  ];

  public function __construct(private Db $db, private RouteService $routeService, private array $config = [])
  {
  }

  public function getRouteOptimization(int $corpId, int $userId): array
  {
    if ($userId <= 0) {
      return $this->emptyResponse('no_user');
    }

    $settings = $this->loadOptimizationSettings($corpId);
    if (empty($settings['enabled'])) {
      return $this->emptyResponse('disabled', $settings);
    }

    $graphStatus = $this->routeService->getGraphStatus();
    if (empty($graphStatus['graph_loaded'])) {
      return $this->emptyResponse('graph_not_loaded', $settings);
    }

    $profile = $this->ensureHaulerProfile($userId);
    $capacity = $this->resolveCapacity($profile);
    if ($capacity['max_cargo_m3'] <= 0) {
      return $this->emptyResponse('no_capacity_profile', $settings, $capacity);
    }

    $active = $this->fetchActiveAssignment($corpId, $userId);
    if ($active === null) {
      return $this->emptyResponse('no_active_haul', $settings, $capacity);
    }

    $assignedVolume = $this->fetchAssignedVolume($corpId, $userId);
    $assignedVolume = max(0.0, $assignedVolume);
    $maxCargo = $capacity['max_cargo_m3'];
    $available = ($maxCargo * 0.99) - $assignedVolume;
    $available = max(0.0, $available);
    $utilization = $maxCargo > 0 ? min(100.0, ($assignedVolume / $maxCargo) * 100.0) : 0.0;
    $freePercent = $maxCargo > 0 ? ($available / $maxCargo) * 100.0 : 0.0;

    if ($freePercent < (float)$settings['min_free_capacity_percent']) {
      return $this->emptyResponse('below_min_free_capacity', $settings, $capacity, [
        'assigned_m3' => $assignedVolume,
        'available_m3' => $available,
        'utilization_percent' => $utilization,
        'free_percent' => $freePercent,
      ], $active);
    }

    $accessRules = $this->loadAccessRules($corpId);
    $structureAllowlist = $this->loadStructureAllowlist($corpId);
    $securityPolicy = $this->buildSecurityPolicy(
      $this->loadSecurityClassDefinitions($corpId),
      $this->loadSecurityRoutingRules($corpId)
    );

    $activePickup = $this->buildLocationContext($active, 'from');
    $activeDropoff = $this->buildLocationContext($active, 'to');

    $baselineContext = $this->buildRouteContext($activePickup, $activeDropoff, $accessRules, $structureAllowlist, $securityPolicy);
    $baselineJumps = $this->computeRouteJumps($activePickup['system_id'], $activeDropoff['system_id'], $baselineContext);
    if ($baselineJumps === null) {
      return $this->emptyResponse('baseline_route_unavailable', $settings, $capacity);
    }

    $candidateStatuses = [
      'requested',
      'awaiting_contract',
      'contract_linked',
      'contract_mismatch',
      'in_queue',
      'draft',
      'quoted',
      'submitted',
      'posted',
    ];

    $shipClasses = $capacity['allowed_classes'];
    $candidates = $this->fetchCandidateRequests(
      $corpId,
      $active['request_id'],
      $candidateStatuses,
      $shipClasses,
      $available
    );

    $detourBudget = max(0, (int)$settings['detour_budget_jumps']);
    $maxSuggestions = max(1, (int)$settings['max_suggestions']);
    $suggestions = [];

    foreach ($candidates as $candidate) {
      if (count($suggestions) >= $maxSuggestions) {
        break;
      }

      $pickup = $this->buildLocationContext($candidate, 'from');
      $dropoff = $this->buildLocationContext($candidate, 'to');

      if ($pickup['system_id'] <= 0 || $dropoff['system_id'] <= 0) {
        continue;
      }

      if (!$this->isLocationAllowed($pickup, 'pickup', $structureAllowlist, $accessRules)
        || !$this->isLocationAllowed($dropoff, 'destination', $structureAllowlist, $accessRules)) {
        continue;
      }

      $segmentA = $this->computeRouteJumps(
        $activePickup['system_id'],
        $pickup['system_id'],
        $this->buildRouteContext($activePickup, $pickup, $accessRules, $structureAllowlist, $securityPolicy)
      );
      if ($segmentA === null) {
        continue;
      }

      $segmentB = $this->computeRouteJumps(
        $pickup['system_id'],
        $dropoff['system_id'],
        $this->buildRouteContext($pickup, $dropoff, $accessRules, $structureAllowlist, $securityPolicy)
      );
      if ($segmentB === null) {
        continue;
      }

      $segmentC = $this->computeRouteJumps(
        $dropoff['system_id'],
        $activeDropoff['system_id'],
        $this->buildRouteContext($dropoff, $activeDropoff, $accessRules, $structureAllowlist, $securityPolicy)
      );
      if ($segmentC === null) {
        continue;
      }

      $totalJumps = $segmentA + $segmentB + $segmentC;
      if ($totalJumps > ($baselineJumps + ($detourBudget * 2))) {
        continue;
      }

      $extraJumps = max(0, $totalJumps - $baselineJumps);
      $volume = (float)($candidate['volume_m3'] ?? 0);
      if ($volume > $available) {
        continue;
      }

      $suggestions[] = [
        'request_id' => (int)$candidate['request_id'],
        'request_key' => (string)($candidate['request_key'] ?? ''),
        'request_code' => $this->buildRequestCode($candidate),
        'pickup_label' => (string)($candidate['from_name'] ?? ''),
        'delivery_label' => (string)($candidate['to_name'] ?? ''),
        'volume_m3' => $volume,
        'extra_jumps' => $extraJumps,
      ];
    }

    return [
      'ok' => true,
      'settings' => $settings,
      'summary' => [
        'max_cargo_m3' => $maxCargo,
        'assigned_m3' => $assignedVolume,
        'available_m3' => $available,
        'utilization_percent' => $utilization,
        'free_percent' => $freePercent,
      ],
      'active_request' => [
        'request_id' => (int)$active['request_id'],
        'pickup' => (string)($active['from_name'] ?? ''),
        'delivery' => (string)($active['to_name'] ?? ''),
        'volume_m3' => (float)($active['volume_m3'] ?? 0),
      ],
      'suggestions' => $suggestions,
      'reason' => $suggestions ? null : 'no_matches',
    ];
  }

  private function emptyResponse(
    string $reason,
    array $settings = [],
    array $capacity = [],
    array $summary = [],
    ?array $active = null
  ): array {
    return [
      'ok' => true,
      'settings' => $settings ?: self::DEFAULT_SETTINGS,
      'summary' => $summary,
      'active_request' => $active ? [
        'request_id' => (int)$active['request_id'],
        'pickup' => (string)($active['from_name'] ?? ''),
        'delivery' => (string)($active['to_name'] ?? ''),
        'volume_m3' => (float)($active['volume_m3'] ?? 0),
      ] : null,
      'capacity' => $capacity,
      'suggestions' => [],
      'reason' => $reason,
    ];
  }

  private function loadOptimizationSettings(int $corpId): array
  {
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.optimization' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.optimization' LIMIT 1"
      );
    }
    $decoded = $row && !empty($row['setting_json'])
      ? Db::jsonDecode((string)$row['setting_json'], [])
      : [];
    $settings = array_merge(self::DEFAULT_SETTINGS, is_array($decoded) ? $decoded : []);
    $settings['enabled'] = !empty($settings['enabled']);
    $settings['detour_budget_jumps'] = max(0, (int)($settings['detour_budget_jumps'] ?? self::DEFAULT_SETTINGS['detour_budget_jumps']));
    $settings['max_suggestions'] = max(1, (int)($settings['max_suggestions'] ?? self::DEFAULT_SETTINGS['max_suggestions']));
    $settings['min_free_capacity_percent'] = max(0.0, (float)($settings['min_free_capacity_percent'] ?? self::DEFAULT_SETTINGS['min_free_capacity_percent']));
    return $settings;
  }

  private function ensureHaulerProfile(int $userId): array
  {
    $this->db->execute(
      "INSERT IGNORE INTO user_hauler_profile (user_id) VALUES (:uid)",
      ['uid' => $userId]
    );
    $row = $this->db->one(
      "SELECT user_id, can_fly_freighter, can_fly_jump_freighter, can_fly_dst, can_fly_br,
              preferred_service_class, max_cargo_m3_override
         FROM user_hauler_profile
        WHERE user_id = :uid
        LIMIT 1",
      ['uid' => $userId]
    );
    return $row ?: [
      'user_id' => $userId,
      'can_fly_freighter' => 0,
      'can_fly_jump_freighter' => 0,
      'can_fly_dst' => 0,
      'can_fly_br' => 0,
      'preferred_service_class' => null,
      'max_cargo_m3_override' => null,
    ];
  }

  private function resolveCapacity(array $profile): array
  {
    $allowed = [];
    if (!empty($profile['can_fly_freighter'])) {
      $allowed[] = 'FREIGHTER';
    }
    if (!empty($profile['can_fly_jump_freighter'])) {
      $allowed[] = 'JF';
    }
    if (!empty($profile['can_fly_dst'])) {
      $allowed[] = 'DST';
    }
    if (!empty($profile['can_fly_br'])) {
      $allowed[] = 'BR';
    }

    $preferred = strtoupper(trim((string)($profile['preferred_service_class'] ?? '')));
    $override = (float)($profile['max_cargo_m3_override'] ?? 0);
    $maxCargo = 0.0;
    $serviceClass = null;

    if ($override > 0) {
      $maxCargo = $override;
    } elseif ($preferred !== '' && in_array($preferred, $allowed, true)) {
      $maxCargo = (float)(self::SHIP_CLASS_CAPACITY[$preferred] ?? 0);
      $serviceClass = $preferred;
    } else {
      foreach ($allowed as $class) {
        $classMax = (float)(self::SHIP_CLASS_CAPACITY[$class] ?? 0);
        if ($classMax > $maxCargo) {
          $maxCargo = $classMax;
          $serviceClass = $class;
        }
      }
    }

    return [
      'max_cargo_m3' => $maxCargo,
      'service_class' => $serviceClass,
      'allowed_classes' => $allowed,
    ];
  }

  private function fetchActiveAssignment(int $corpId, int $userId): ?array
  {
    $row = $this->db->one(
      "SELECT r.request_id, r.request_key, r.volume_m3, r.ship_class,
              r.from_location_id, r.from_location_type, r.to_location_id, r.to_location_type,
              COALESCE(fs.system_id, f_station.system_id, f_structure.system_id) AS from_system_id,
              COALESCE(ts.system_id, t_station.system_id, t_structure.system_id) AS to_system_id,
              COALESCE(fs.system_name, f_station.station_name, f_structure.structure_name, CONCAT(r.from_location_type, ':', r.from_location_id)) AS from_name,
              COALESCE(ts.system_name, t_station.station_name, t_structure.structure_name, CONCAT(r.to_location_type, ':', r.to_location_id)) AS to_name,
              COALESCE(fs.region_id, f_sys.region_id) AS from_region_id,
              COALESCE(ts.region_id, t_sys.region_id) AS to_region_id
         FROM haul_assignment a
         JOIN haul_request r ON r.request_id = a.request_id
         LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
         LEFT JOIN eve_station f_station ON f_station.station_id = r.from_location_id AND r.from_location_type = 'station'
         LEFT JOIN eve_structure f_structure ON f_structure.structure_id = r.from_location_id AND r.from_location_type = 'structure'
         LEFT JOIN eve_system f_sys ON f_sys.system_id = COALESCE(f_station.system_id, f_structure.system_id)
         LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
         LEFT JOIN eve_station t_station ON t_station.station_id = r.to_location_id AND r.to_location_type = 'station'
         LEFT JOIN eve_structure t_structure ON t_structure.structure_id = r.to_location_id AND r.to_location_type = 'structure'
         LEFT JOIN eve_system t_sys ON t_sys.system_id = COALESCE(t_station.system_id, t_structure.system_id)
        WHERE r.corp_id = :cid
          AND a.hauler_user_id = :uid
          AND a.status IN ('assigned','in_transit')
        ORDER BY a.updated_at DESC
        LIMIT 1",
      ['cid' => $corpId, 'uid' => $userId]
    );
    return $row ?: null;
  }

  private function fetchAssignedVolume(int $corpId, int $userId): float
  {
    $row = $this->db->one(
      "SELECT SUM(r.volume_m3) AS volume_total
         FROM haul_assignment a
         JOIN haul_request r ON r.request_id = a.request_id
        WHERE r.corp_id = :cid
          AND a.hauler_user_id = :uid
          AND a.status IN ('assigned','in_transit')",
      ['cid' => $corpId, 'uid' => $userId]
    );
    return $row ? (float)($row['volume_total'] ?? 0) : 0.0;
  }

  private function fetchCandidateRequests(
    int $corpId,
    int $activeRequestId,
    array $statuses,
    array $allowedShipClasses,
    float $availableM3
  ): array {
    if ($corpId <= 0 || $statuses === []) {
      return [];
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $shipFilter = '';
    $params = array_merge([$corpId], $statuses, [$activeRequestId, $availableM3]);

    if ($allowedShipClasses) {
      $shipPlaceholders = implode(',', array_fill(0, count($allowedShipClasses), '?'));
      $shipFilter = " AND (r.ship_class IS NULL OR r.ship_class = '' OR r.ship_class IN ({$shipPlaceholders}))";
      $params = array_merge($params, $allowedShipClasses);
    }

    $sql = "SELECT r.request_id, r.request_key, r.volume_m3, r.ship_class,
                   r.from_location_id, r.from_location_type, r.to_location_id, r.to_location_type,
                   COALESCE(fs.system_id, f_station.system_id, f_structure.system_id) AS from_system_id,
                   COALESCE(ts.system_id, t_station.system_id, t_structure.system_id) AS to_system_id,
                   COALESCE(fs.system_name, f_station.station_name, f_structure.structure_name, CONCAT(r.from_location_type, ':', r.from_location_id)) AS from_name,
                   COALESCE(ts.system_name, t_station.station_name, t_structure.structure_name, CONCAT(r.to_location_type, ':', r.to_location_id)) AS to_name,
                   COALESCE(fs.region_id, f_sys.region_id) AS from_region_id,
                   COALESCE(ts.region_id, t_sys.region_id) AS to_region_id
              FROM haul_request r
              LEFT JOIN haul_assignment a ON a.request_id = r.request_id
              LEFT JOIN eve_system fs ON fs.system_id = r.from_location_id AND r.from_location_type = 'system'
              LEFT JOIN eve_station f_station ON f_station.station_id = r.from_location_id AND r.from_location_type = 'station'
              LEFT JOIN eve_structure f_structure ON f_structure.structure_id = r.from_location_id AND r.from_location_type = 'structure'
              LEFT JOIN eve_system f_sys ON f_sys.system_id = COALESCE(f_station.system_id, f_structure.system_id)
              LEFT JOIN eve_system ts ON ts.system_id = r.to_location_id AND r.to_location_type = 'system'
              LEFT JOIN eve_station t_station ON t_station.station_id = r.to_location_id AND r.to_location_type = 'station'
              LEFT JOIN eve_structure t_structure ON t_structure.structure_id = r.to_location_id AND r.to_location_type = 'structure'
              LEFT JOIN eve_system t_sys ON t_sys.system_id = COALESCE(t_station.system_id, t_structure.system_id)
             WHERE r.corp_id = ?
               AND r.status IN ({$placeholders})
               AND (a.request_id IS NULL OR a.status IN ('cancelled','delivered'))
               AND r.request_id <> ?
               AND r.volume_m3 > 0
               AND r.volume_m3 <= ?{$shipFilter}
             ORDER BY r.created_at DESC
             LIMIT 50";

    return $this->db->select($sql, $params);
  }

  private function buildLocationContext(array $row, string $prefix): array
  {
    return [
      'location_id' => (int)($row[$prefix . '_location_id'] ?? 0),
      'location_type' => (string)($row[$prefix . '_location_type'] ?? 'system'),
      'system_id' => (int)($row[$prefix . '_system_id'] ?? 0),
      'region_id' => (int)($row[$prefix . '_region_id'] ?? 0),
    ];
  }

  private function buildRouteContext(
    array $pickup,
    array $destination,
    array $accessRules,
    array $structureAllowlist,
    array $securityPolicy
  ): array {
    return [
      'access_system_ids' => $accessRules['access_system_ids'] ?? [],
      'access_region_ids' => $accessRules['access_region_ids'] ?? [],
      'access_location_ids' => [
        'pickup' => $structureAllowlist['pickup'] ?? [],
        'destination' => $structureAllowlist['destination'] ?? [],
      ],
      'pickup_location' => [
        'location_id' => $pickup['location_id'] ?? 0,
        'location_type' => $pickup['location_type'] ?? null,
        'region_id' => $pickup['region_id'] ?? 0,
      ],
      'destination_location' => [
        'location_id' => $destination['location_id'] ?? 0,
        'location_type' => $destination['location_type'] ?? null,
        'region_id' => $destination['region_id'] ?? 0,
      ],
      'security_policy' => $securityPolicy,
      'allow_esi_fallback' => false,
    ];
  }

  private function computeRouteJumps(int $fromSystemId, int $toSystemId, array $context): ?int
  {
    if ($fromSystemId <= 0 || $toSystemId <= 0) {
      return null;
    }
    try {
      $route = $this->routeService->findRouteByIds($fromSystemId, $toSystemId, 'balanced', $context);
    } catch (RouteException $e) {
      return null;
    }
    return (int)($route['jumps'] ?? 0);
  }

  private function buildRequestCode(array $candidate): string
  {
    $requestKey = trim((string)($candidate['request_key'] ?? ''));
    if ($requestKey !== '') {
      return $requestKey;
    }
    return '#' . (string)((int)($candidate['request_id'] ?? 0));
  }

  private function isLocationAllowed(array $location, string $type, array $structureAllowlist, array $accessRules): bool
  {
    $systemId = (int)($location['system_id'] ?? 0);
    $regionId = (int)($location['region_id'] ?? 0);
    $locationId = (int)($location['location_id'] ?? 0);
    $locationType = (string)($location['location_type'] ?? '');

    $allowedSystemIds = $accessRules['access_system_ids'] ?? [];
    $allowedRegionIds = $accessRules['access_region_ids'] ?? [];
    $allowedPickup = $structureAllowlist['pickup'] ?? [];
    $allowedDestination = $structureAllowlist['destination'] ?? [];
    $hasAllowlist = !empty($allowedSystemIds) || !empty($allowedRegionIds) || !empty($allowedPickup) || !empty($allowedDestination);

    if (!$hasAllowlist) {
      return true;
    }

    $allowedIds = $type === 'destination' ? $allowedDestination : $allowedPickup;
    if ($locationId > 0 && in_array($locationId, $allowedIds, true)) {
      return true;
    }

    if ($locationType === 'system' && $systemId > 0) {
      return in_array($systemId, $allowedSystemIds, true)
        || ($regionId > 0 && in_array($regionId, $allowedRegionIds, true));
    }

    if ($systemId > 0 && in_array($systemId, $allowedSystemIds, true)) {
      return true;
    }
    if ($regionId > 0 && in_array($regionId, $allowedRegionIds, true)) {
      return true;
    }

    return false;
  }

  private function loadAccessRules(int $corpId): array
  {
    $allowedSystemIds = [];
    $allowedRegionIds = [];

    if ($corpId <= 0) {
      return [
        'access_system_ids' => $allowedSystemIds,
        'access_region_ids' => $allowedRegionIds,
      ];
    }

    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'access.rules' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'access.rules' LIMIT 1"
      );
    }
    if ($row && !empty($row['setting_json'])) {
      $decoded = Db::jsonDecode((string)$row['setting_json'], []);
      if (is_array($decoded)) {
        foreach ($decoded['systems'] ?? [] as $rule) {
          if (!empty($rule['allowed'])) {
            $allowedSystemIds[] = (int)($rule['id'] ?? 0);
          }
        }
        foreach ($decoded['regions'] ?? [] as $rule) {
          if (!empty($rule['allowed'])) {
            $allowedRegionIds[] = (int)($rule['id'] ?? 0);
          }
        }
      }
    }

    return [
      'access_system_ids' => array_values(array_filter($allowedSystemIds)),
      'access_region_ids' => array_values(array_filter($allowedRegionIds)),
    ];
  }

  private function loadStructureAllowlist(int $corpId): array
  {
    $allowPickup = [];
    $allowDestination = [];
    if ($corpId <= 0) {
      return ['pickup' => $allowPickup, 'destination' => $allowDestination];
    }

    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'access.rules' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'access.rules' LIMIT 1"
      );
    }
    if ($row && !empty($row['setting_json'])) {
      $decoded = Db::jsonDecode((string)$row['setting_json'], []);
      if (is_array($decoded)) {
        foreach ($decoded['structures'] ?? [] as $rule) {
          if (empty($rule['allowed'])) {
            continue;
          }
          $id = (int)($rule['id'] ?? 0);
          if ($id <= 0) {
            continue;
          }
          if (!empty($rule['pickup_allowed'])) {
            $allowPickup[] = $id;
          }
          if (!empty($rule['delivery_allowed'])) {
            $allowDestination[] = $id;
          }
        }
      }
    }

    return [
      'pickup' => array_values(array_unique($allowPickup)),
      'destination' => array_values(array_unique($allowDestination)),
    ];
  }

  private function loadSecurityClassDefinitions(int $corpId): array
  {
    $defaults = [
      'thresholds' => [
        'highsec_min' => 0.5,
        'lowsec_min' => 0.1,
      ],
      'special' => [
        'pochven' => [
          'enabled' => true,
          'region_names' => ['Pochven'],
          'system_names' => [],
        ],
        'zarzakh' => [
          'enabled' => true,
          'region_names' => [],
          'system_names' => ['Zarzakh'],
        ],
        'thera' => [
          'enabled' => false,
          'region_names' => [],
          'system_names' => ['Thera'],
        ],
      ],
    ];

    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.security_classes' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.security_classes' LIMIT 1"
      );
    }
    $decoded = (!$row || empty($row['setting_json']))
      ? $defaults
      : Db::jsonDecode((string)$row['setting_json'], []);

    if (!is_array($decoded)) {
      $decoded = $defaults;
    }

    $thresholds = array_merge($defaults['thresholds'], $decoded['thresholds'] ?? []);
    $thresholds['highsec_min'] = (float)($thresholds['highsec_min'] ?? 0.5);
    $thresholds['lowsec_min'] = (float)($thresholds['lowsec_min'] ?? 0.1);

    $special = $decoded['special'] ?? [];
    $special = is_array($special) ? $special : [];
    $normalizedSpecial = [];
    $specialSystemIds = ['pochven' => [], 'zarzakh' => [], 'thera' => []];
    $specialRegionIds = ['pochven' => [], 'zarzakh' => [], 'thera' => []];

    foreach (['pochven', 'zarzakh', 'thera'] as $key) {
      $entry = array_merge($defaults['special'][$key], $special[$key] ?? []);
      $enabled = !empty($entry['enabled']);
      $regionNames = array_values(array_filter(array_map('trim', $entry['region_names'] ?? [])));
      $systemNames = array_values(array_filter(array_map('trim', $entry['system_names'] ?? [])));
      $normalizedSpecial[$key] = [
        'enabled' => $enabled,
        'region_names' => $regionNames,
        'system_names' => $systemNames,
      ];
      if ($enabled) {
        $specialRegionIds[$key] = $this->resolveRegionIdsByNames($regionNames);
        $specialSystemIds[$key] = $this->resolveSystemIdsByNames($systemNames);
      }
    }

    return [
      'thresholds' => $thresholds,
      'special' => $normalizedSpecial,
      'special_system_ids' => $specialSystemIds,
      'special_region_ids' => $specialRegionIds,
    ];
  }

  private function loadSecurityRoutingRules(int $corpId): array
  {
    $defaults = [
      'high' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
      'low' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
      'null' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
      'pochven' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
      'zarzakh' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
      'thera' => ['enabled' => true, 'allow_pickup' => true, 'allow_delivery' => true, 'requires_acknowledgement' => false],
    ];

    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.security_rules' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.security_rules' LIMIT 1"
      );
    }
    if (!$row || empty($row['setting_json'])) {
      return $defaults;
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (!is_array($decoded)) {
      return $defaults;
    }
    $rules = [];
    foreach ($defaults as $key => $default) {
      $entry = is_array($decoded[$key] ?? null) ? $decoded[$key] : [];
      $rules[$key] = [
        'enabled' => array_key_exists('enabled', $entry) ? !empty($entry['enabled']) : $default['enabled'],
        'allow_pickup' => array_key_exists('allow_pickup', $entry) ? !empty($entry['allow_pickup']) : $default['allow_pickup'],
        'allow_delivery' => array_key_exists('allow_delivery', $entry) ? !empty($entry['allow_delivery']) : $default['allow_delivery'],
        'requires_acknowledgement' => array_key_exists('requires_acknowledgement', $entry)
          ? !empty($entry['requires_acknowledgement'])
          : $default['requires_acknowledgement'],
      ];
    }

    return $rules;
  }

  private function buildSecurityPolicy(array $definitions, array $rules): array
  {
    return [
      'thresholds' => $definitions['thresholds'] ?? [],
      'special_system_ids' => $definitions['special_system_ids'] ?? [],
      'special_region_ids' => $definitions['special_region_ids'] ?? [],
      'rules' => $rules,
    ];
  }

  private function resolveRegionIdsByNames(array $names): array
  {
    $names = array_values(array_filter(array_map('trim', $names)));
    if ($names === []) {
      return [];
    }
    $params = [];
    $placeholders = [];
    foreach ($names as $idx => $name) {
      $key = "name{$idx}";
      $params[$key] = strtolower($name);
      $placeholders[] = ':' . $key;
    }
    $rows = $this->db->select(
      "SELECT region_id FROM eve_region WHERE LOWER(region_name) IN (" . implode(',', $placeholders) . ")",
      $params
    );
    return array_values(array_map(static fn($row) => (int)$row['region_id'], $rows));
  }

  private function resolveSystemIdsByNames(array $names): array
  {
    $names = array_values(array_filter(array_map('trim', $names)));
    if ($names === []) {
      return [];
    }
    $params = [];
    $placeholders = [];
    foreach ($names as $idx => $name) {
      $key = "name{$idx}";
      $params[$key] = strtolower($name);
      $placeholders[] = ':' . $key;
    }
    $rows = $this->db->select(
      "SELECT system_id FROM eve_system WHERE LOWER(system_name) IN (" . implode(',', $placeholders) . ")",
      $params
    );
    return array_values(array_map(static fn($row) => (int)$row['system_id'], $rows));
  }
}
