<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class PricingService
{
  private const DEFAULT_MAX_COLLATERAL_ISK = 1500000000.0;
  private Db $db;
  private array $config;
  private RouteService $routeService;

  private const SHIP_CLASSES = [
    'BR' => 12500,
    'DST' => 62500,
    'JF' => 360000,
    'FREIGHTER' => 950000,
  ];

  private const DEFAULT_SECURITY_MULTIPLIERS = [
    'high' => 1.0,
    'low' => 1.5,
    'null' => 2.5,
    'pochven' => 3.0,
    'zarzakh' => 3.5,
    'thera' => 3.0,
  ];

  public function __construct(Db $db, RouteService $routeService, array $config = [])
  {
    $this->db = $db;
    $this->routeService = $routeService;
    $this->config = $config;
  }

  public function quote(array $payload, int $corpId = 0, array $context = []): array
  {
    $from = trim((string)($payload['pickup'] ?? $payload['pickup_system'] ?? $payload['from_system'] ?? ''));
    $to = trim((string)($payload['destination'] ?? $payload['destination_system'] ?? $payload['to_system'] ?? ''));
    $pickupLocationId = (int)($payload['pickup_location_id'] ?? 0);
    $pickupLocationType = (string)($payload['pickup_location_type'] ?? '');
    $destinationLocationId = (int)($payload['destination_location_id'] ?? 0);
    $destinationLocationType = (string)($payload['destination_location_type'] ?? '');
    $priority = (string)($payload['priority'] ?? $payload['route_priority'] ?? $payload['profile'] ?? '');
    $volume = (float)($payload['volume_m3'] ?? $payload['volume'] ?? 0);
    $collateral = (float)($payload['collateral_isk'] ?? $payload['collateral'] ?? 0);

    if (($from === '' && $pickupLocationId <= 0) || ($to === '' && $destinationLocationId <= 0)) {
      throw new \InvalidArgumentException('pickup and delivery locations are required.');
    }
    if ($volume <= 0) {
      throw new \InvalidArgumentException('volume_m3 must be greater than zero.');
    }
    if ($collateral < 0) {
      throw new \InvalidArgumentException('collateral_isk must be zero or higher.');
    }
    $maxCollateral = $this->loadMaxCollateral($corpId);
    if ($maxCollateral > 0 && $collateral > $maxCollateral) {
      throw new \InvalidArgumentException(sprintf(
        'collateral_isk exceeds the maximum allowed (%s ISK).',
        number_format($maxCollateral, 2, '.', ',')
      ));
    }

    $allowStructures = $this->loadQuoteLocationMode($corpId);
    $structureAllowlist = $this->loadStructureAllowlist($corpId);
    $accessRules = $this->loadAccessRules($corpId);
    $securityDefinitions = $this->loadSecurityClassDefinitions($corpId);
    $securityRules = $this->loadSecurityRoutingRules($corpId);
    $securityPolicy = $this->buildSecurityPolicy($securityDefinitions, $securityRules);
    [$fromLocation, $pickupDebug] = $this->resolveLocationEntryWithDebug(
      $from,
      $pickupLocationType,
      $pickupLocationId,
      $allowStructures,
      'pickup',
      $structureAllowlist,
      $accessRules
    );
    [$toLocation, $deliveryDebug] = $this->resolveLocationEntryWithDebug(
      $to,
      $destinationLocationType,
      $destinationLocationId,
      $allowStructures,
      'destination',
      $structureAllowlist,
      $accessRules
    );
    if ($fromLocation === null || $toLocation === null) {
      $debug = [
        'pickup' => $pickupDebug,
        'delivery' => $deliveryDebug,
      ];
      error_log('Quote location resolve failed: ' . json_encode($debug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      if ($fromLocation === null) {
        throw new \InvalidArgumentException(sprintf(
          'Unknown pickup location (id=%s, type=%s).',
          $pickupLocationId ?: 'n/a',
          $pickupLocationType !== '' ? $pickupLocationType : 'n/a'
        ));
      }
      throw new \InvalidArgumentException(sprintf(
        'Unknown delivery location (id=%s, type=%s).',
        $destinationLocationId ?: 'n/a',
        $destinationLocationType !== '' ? $destinationLocationType : 'n/a'
      ));
    }
    $fromSystem = $fromLocation['system'];
    $toSystem = $toLocation['system'];

    $priority = $this->normalizePriority($priority);
    $routeProfile = 'balanced';
    $routeContext = array_merge(
      $context,
      $accessRules,
      [
        'access_location_ids' => $structureAllowlist,
        'security_policy' => $securityPolicy,
        'pickup_location' => $fromLocation['location'] ?? null,
        'destination_location' => $toLocation['location'] ?? null,
      ]
    );
    $route = $this->routeService->findRoute(
      $fromSystem['system_name'],
      $toSystem['system_name'],
      $routeProfile,
      $routeContext
    );

    $ship = $this->chooseShipClass($volume, $route);
    $ratePlan = $this->getRatePlan($corpId, $ship['service_class']);
    $priorityFees = $this->loadPriorityFees($corpId);
    $securityMultipliers = $this->loadSecurityMultipliers($corpId);
    $flatRiskFees = $this->loadFlatRiskSurcharges($corpId);
    $volumePressure = $this->loadVolumePressureScaling($corpId);
    $priorityFee = (float)($priorityFees[$priority] ?? 0.0);

    $jumps = (int)$route['jumps'];
    $securityCounts = $route['security_counts'] ?? [
      'high' => (int)($route['hs_count'] ?? 0),
      'low' => (int)($route['ls_count'] ?? 0),
      'null' => (int)($route['ns_count'] ?? 0),
      'pochven' => 0,
      'zarzakh' => 0,
      'thera' => 0,
      'special' => 0,
    ];

    $ratePerJump = (float)($ratePlan['rate_per_jump'] ?? 0);
    $collateralRate = (float)($ratePlan['collateral_rate'] ?? 0);
    $minPrice = (float)($ratePlan['min_price'] ?? 0);

    $jumpCosts = $this->buildSecurityJumpCosts($securityCounts, $securityMultipliers, $ratePerJump);
    $jumpSubtotal = $jumpCosts['total'];
    $softPenalty = $this->softDnfPenalty($route['used_soft_dnf_rules'] ?? [], $ratePerJump);
    $flatRisk = $this->applyFlatRiskFees($securityCounts, $flatRiskFees);
    $haulSubtotal = $jumpSubtotal + $softPenalty['total'] + $flatRisk['total'] + $priorityFee;
    $volumeAdjustment = $this->applyVolumePressure($volume, (float)($ship['max_volume'] ?? 0), $haulSubtotal, $volumePressure);
    $haulTotal = $haulSubtotal + $volumeAdjustment['surcharge'];
    $collateralFee = $collateral * $collateralRate;
    $priceTotal = max($minPrice, $haulTotal + $collateralFee);

    $breakdown = [
      'inputs' => [
        'from_system' => $fromSystem,
        'to_system' => $toSystem,
        'from_location' => $fromLocation['location'] ?? null,
        'to_location' => $toLocation['location'] ?? null,
        'volume_m3' => $volume,
        'collateral_isk' => $collateral,
      ],
      'priority' => $priority,
      'ship_class' => $ship,
      'jumps' => $jumps,
      'security_counts' => $securityCounts,
      'security_multipliers' => $securityMultipliers,
      'security_jump_costs' => $jumpCosts['by_class'],
      'flat_risk_fees' => $flatRisk,
      'volume_pressure' => $volumeAdjustment,
      'penalties' => [
        'soft_dnf_total' => $softPenalty['total'],
        'soft_dnf' => $softPenalty['rules'],
        'priority_fee' => $priorityFee,
      ],
      'rate_plan' => [
        'service_class' => $ratePlan['service_class'] ?? $ship['service_class'],
        'rate_per_jump' => $ratePerJump,
        'collateral_rate' => $collateralRate,
        'min_price' => $minPrice,
      ],
      'costs' => [
        'jump_security' => $jumpSubtotal,
        'soft_dnf_penalty' => $softPenalty['total'],
        'priority_fee' => $priorityFee,
        'flat_risk_fees' => $flatRisk['total'],
        'volume_pressure' => $volumeAdjustment['surcharge'],
        'haul_subtotal' => $haulSubtotal,
        'haul_total' => $haulTotal,
        'collateral_fee' => $collateralFee,
        'min_price_applied' => $priceTotal === $minPrice && $priceTotal > 0,
        'total' => $priceTotal,
      ],
    ];

    $quoteId = $this->db->insert('quote', [
      'corp_id' => $corpId,
      'from_system_id' => $route['path'][0]['system_id'] ?? 0,
      'to_system_id' => $route['path'][count($route['path']) - 1]['system_id'] ?? 0,
      'profile' => $priority,
      'route_json' => Db::jsonEncode($route),
      'breakdown_json' => Db::jsonEncode($breakdown),
      'volume_m3' => $volume,
      'collateral_isk' => $collateral,
      'price_total' => $priceTotal,
      'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return [
      'quote_id' => $quoteId,
      'price_total' => $priceTotal,
      'breakdown' => $breakdown,
      'route' => $route,
    ];
  }

  private function normalizePriority(string $priority): string
  {
    $priority = strtolower(trim($priority));
    if (in_array($priority, ['high', 'normal'], true)) {
      return $priority;
    }
    return 'normal';
  }

  private function resolveSystemByName(string $systemName): ?array
  {
    $name = trim($systemName);
    if ($name === '') {
      return null;
    }

    $systemId = $this->routeService->resolveSystemIdByName($name);
    $row = $this->db->one(
      "SELECT system_id, system_name FROM map_system WHERE system_id = :id LIMIT 1",
      ['id' => $systemId]
    );
    if ($row === null) {
      $row = $this->db->one(
        "SELECT system_id, system_name FROM eve_system WHERE system_id = :id LIMIT 1",
        ['id' => $systemId]
      );
    }

    if ($row === null) {
      return null;
    }

    return [
      'system_id' => (int)$row['system_id'],
      'system_name' => (string)$row['system_name'],
    ];
  }

  private function resolveLocationEntry(
    string $locationName,
    string $locationType,
    int $locationId,
    bool $allowStructures,
    string $type,
    array $structureAllowlist,
    array $accessRules
  ): ?array {
    if ($locationId > 0 && $locationType !== '') {
      if (($locationType === 'structure' || $locationType === 'npc_station') && !$allowStructures) {
        return null;
      }
      $entry = $this->resolveLocationById($locationId, $locationType);
      if ($entry !== null && $this->isLocationAllowed($entry, $type, $structureAllowlist, $accessRules)) {
        return $entry;
      }
    }

    return $this->resolveLocationByName($locationName, $allowStructures, $type, $structureAllowlist, $accessRules);
  }

  private function resolveLocationEntryWithDebug(
    string $locationName,
    string $locationType,
    int $locationId,
    bool $allowStructures,
    string $type,
    array $structureAllowlist,
    array $accessRules
  ): array {
    $debug = [
      'location_id' => $locationId > 0 ? $locationId : null,
      'location_type' => $locationType !== '' ? $locationType : null,
      'lookup' => null,
      'found' => false,
      'allowed' => null,
      'system_id' => null,
    ];

    if ($locationId > 0 && $locationType !== '') {
      $debug['lookup'] = 'id';
      if (($locationType === 'structure' || $locationType === 'npc_station') && !$allowStructures) {
        $debug['allowed'] = false;
        return [null, $debug];
      }
      $entry = $this->resolveLocationById($locationId, $locationType);
      $debug['found'] = $entry !== null;
      $debug['system_id'] = $entry['system']['system_id'] ?? $entry['location']['system_id'] ?? null;
      $debug['allowed'] = $entry !== null
        ? $this->isLocationAllowed($entry, $type, $structureAllowlist, $accessRules)
        : null;
      if ($entry !== null && $debug['allowed']) {
        return [$entry, $debug];
      }
      return [null, $debug];
    }

    $debug['lookup'] = 'name';
    $entry = $this->resolveLocationByName($locationName, $allowStructures, $type, $structureAllowlist, $accessRules);
    $debug['found'] = $entry !== null;
    $debug['system_id'] = $entry['system']['system_id'] ?? $entry['location']['system_id'] ?? null;
    $debug['allowed'] = $entry !== null;
    return [$entry, $debug];
  }

  private function resolveLocationByName(
    string $locationName,
    bool $allowStructures,
    string $type,
    array $structureAllowlist,
    array $accessRules
  ): ?array {
    $system = $this->resolveSystemByName($locationName);
    if ($system !== null) {
      $entry = $this->buildSystemLocationEntry($system);
      return $this->isLocationAllowed($entry, $type, $structureAllowlist, $accessRules) ? $entry : null;
    }

    return null;
  }

  private function resolveLocationById(int $locationId, string $locationType): ?array
  {
    if ($locationId <= 0) {
      return null;
    }
    if ($locationType === 'npc_station') {
      return $this->resolveStationById($locationId);
    }
    if ($locationType === 'structure') {
      return $this->resolveStructureById($locationId);
    }
    if ($locationType === 'system') {
      $system = $this->resolveSystemById($locationId);
      return $system ? $this->buildSystemLocationEntry($system) : null;
    }
    return null;
  }

  private function resolveStationById(int $stationId): ?array
  {
    $row = $this->db->one(
      "SELECT st.station_id AS location_id,
              st.station_name,
              ea.alias AS alias_name,
              ee.name AS entity_name,
              st.system_id,
              COALESCE(ms.system_name, s.system_name) AS system_name,
              COALESCE(ms.region_id, c.region_id) AS region_id
         FROM eve_station st
         LEFT JOIN (
           SELECT entity_id, MIN(alias) AS alias
             FROM eve_entity_alias
            WHERE entity_type = 'station'
            GROUP BY entity_id
         ) ea ON ea.entity_id = st.station_id
         LEFT JOIN eve_entity ee ON ee.entity_id = st.station_id AND ee.entity_type = 'station'
         LEFT JOIN map_system ms ON ms.system_id = st.system_id
         LEFT JOIN eve_system s ON s.system_id = st.system_id
         LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
        WHERE st.station_id = :id
        LIMIT 1",
      ['id' => $stationId]
    );
    if ($row === null) {
      return null;
    }
    $resolvedName = $this->resolveStationName(
      (string)($row['station_name'] ?? ''),
      (string)($row['alias_name'] ?? ''),
      (string)($row['entity_name'] ?? '')
    );
    return $this->buildLocationEntry(
      (int)$row['location_id'],
      'npc_station',
      $resolvedName,
      (int)($row['system_id'] ?? 0),
      (string)($row['system_name'] ?? ''),
      (int)($row['region_id'] ?? 0)
    );
  }

  private function resolveStructureById(int $structureId): ?array
  {
    $row = $this->db->one(
      "SELECT es.structure_id AS location_id,
              es.structure_name,
              es.system_id,
              COALESCE(ms.system_name, s.system_name) AS system_name,
              COALESCE(ms.region_id, c.region_id) AS region_id
         FROM eve_structure es
         LEFT JOIN map_system ms ON ms.system_id = es.system_id
         LEFT JOIN eve_system s ON s.system_id = es.system_id
         LEFT JOIN eve_constellation c ON c.constellation_id = s.constellation_id
        WHERE es.structure_id = :id
        LIMIT 1",
      ['id' => $structureId]
    );
    if ($row === null) {
      return null;
    }
    return $this->buildLocationEntry(
      (int)$row['location_id'],
      'structure',
      (string)($row['structure_name'] ?? ''),
      (int)($row['system_id'] ?? 0),
      (string)($row['system_name'] ?? ''),
      (int)($row['region_id'] ?? 0)
    );
  }

  private function buildSystemLocationEntry(array $system): array
  {
    $systemId = (int)($system['system_id'] ?? 0);
    $systemName = (string)($system['system_name'] ?? '');
    $regionId = $this->resolveRegionIdForSystem($systemId);
    return [
      'system' => [
        'system_id' => $systemId,
        'system_name' => $systemName,
      ],
      'location' => [
        'location_id' => $systemId,
        'location_type' => 'system',
        'location_name' => $systemName,
        'system_id' => $systemId,
        'system_name' => $systemName,
        'display_name' => $systemName,
        'region_id' => $regionId,
      ],
    ];
  }

  private function buildLocationEntry(
    int $locationId,
    string $locationType,
    string $locationName,
    int $systemId,
    string $systemName,
    int $regionId
  ): array {
    $system = $this->resolveSystemById($systemId);
    $resolvedSystemId = $system ? (int)$system['system_id'] : $systemId;
    $resolvedSystemName = $system ? (string)$system['system_name'] : $systemName;
    $displayName = $this->buildLocationDisplayName($resolvedSystemName, $locationName);
    return [
      'system' => [
        'system_id' => $resolvedSystemId,
        'system_name' => $resolvedSystemName,
      ],
      'location' => [
        'location_id' => $locationId,
        'location_type' => $locationType,
        'location_name' => $locationName,
        'system_id' => $resolvedSystemId,
        'system_name' => $resolvedSystemName,
        'display_name' => $displayName,
        'region_id' => $regionId,
      ],
    ];
  }

  private function buildLocationDisplayName(string $systemName, string $locationName): string
  {
    $systemName = trim($systemName);
    $locationName = trim($locationName);
    if ($systemName !== '' && $locationName !== '') {
      return $systemName . ' — ' . $locationName;
    }
    return $locationName !== '' ? $locationName : $systemName;
  }

  private function resolveStationName(string $stationName, string $aliasName, string $entityName): string
  {
    $stationPlaceholderPattern = '/^station\\s+\\d+$/i';
    $stationName = trim($stationName);
    $aliasName = trim($aliasName);
    $entityName = trim($entityName);
    $isPlaceholder = $stationName === '' || preg_match($stationPlaceholderPattern, $stationName) === 1;
    if ($isPlaceholder) {
      if ($aliasName !== '') {
        return $aliasName;
      }
      if ($entityName !== '' && preg_match($stationPlaceholderPattern, $entityName) !== 1) {
        return $entityName;
      }
    }
    return $stationName;
  }

  private function isLocationAllowed(array $entry, string $type, array $structureAllowlist, array $accessRules): bool
  {
    $systemId = (int)($entry['location']['system_id'] ?? $entry['system']['system_id'] ?? 0);
    $regionId = (int)($entry['location']['region_id'] ?? 0);
    $locationId = (int)($entry['location']['location_id'] ?? 0);
    $locationType = (string)($entry['location']['location_type'] ?? '');

    $allowedSystemIds = $accessRules['access_system_ids'] ?? [];
    $allowedRegionIds = $accessRules['access_region_ids'] ?? [];
    $allowedPickup = $structureAllowlist['pickup'] ?? [];
    $allowedDestination = $structureAllowlist['destination'] ?? [];
    $hasAllowlist = !empty($allowedSystemIds) || !empty($allowedRegionIds) || !empty($allowedPickup) || !empty($allowedDestination);

    if (!$hasAllowlist) {
      return true;
    }

    $allowedIds = $type === 'destination' ? $allowedDestination : $allowedPickup;
    if (in_array($locationId, $allowedIds, true)) {
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

  private function resolveSystemById(int $systemId): ?array
  {
    if ($systemId <= 0) {
      return null;
    }

    $row = $this->db->one(
      "SELECT system_id, system_name FROM map_system WHERE system_id = :id LIMIT 1",
      ['id' => $systemId]
    );
    if ($row === null) {
      $row = $this->db->one(
        "SELECT system_id, system_name FROM eve_system WHERE system_id = :id LIMIT 1",
        ['id' => $systemId]
      );
    }

    if ($row === null) {
      return null;
    }

    return [
      'system_id' => (int)$row['system_id'],
      'system_name' => (string)$row['system_name'],
    ];
  }

  private function resolveRegionIdForSystem(int $systemId): int
  {
    if ($systemId <= 0) {
      return 0;
    }
    $regionId = $this->db->fetchValue(
      "SELECT region_id FROM map_system WHERE system_id = :id LIMIT 1",
      ['id' => $systemId]
    );
    if (!empty($regionId)) {
      return (int)$regionId;
    }
    $regionId = $this->db->fetchValue(
      "SELECT c.region_id
         FROM eve_system s
         JOIN eve_constellation c ON c.constellation_id = s.constellation_id
        WHERE s.system_id = :id
        LIMIT 1",
      ['id' => $systemId]
    );
    return $regionId ? (int)$regionId : 0;
  }

  private function softDnfPenalty(array $rules, float $ratePerJump): array
  {
    $total = 0.0;
    $details = [];
    foreach ($rules as $rule) {
      $severity = (int)($rule['severity'] ?? 1);
      if ($severity <= 0) {
        $severity = 1;
      }
      $penalty = $ratePerJump * $severity;
      $total += $penalty;
      $details[] = [
        'dnf_rule_id' => (int)($rule['dnf_rule_id'] ?? 0),
        'reason' => (string)($rule['reason'] ?? ''),
        'severity' => $severity,
        'penalty' => $penalty,
      ];
    }

    return [
      'total' => $total,
      'rules' => $details,
    ];
  }

  private function buildSecurityJumpCosts(array $securityCounts, array $multipliers, float $ratePerJump): array
  {
    $classes = ['high', 'low', 'null', 'pochven', 'zarzakh', 'thera'];
    $byClass = [];
    $total = 0.0;
    foreach ($classes as $class) {
      $count = (int)($securityCounts[$class] ?? 0);
      $multiplier = (float)($multipliers[$class] ?? 1.0);
      $cost = $count * $ratePerJump * $multiplier;
      $byClass[$class] = [
        'count' => $count,
        'multiplier' => $multiplier,
        'cost' => $cost,
      ];
      $total += $cost;
    }

    return [
      'total' => $total,
      'by_class' => $byClass,
    ];
  }

  private function applyFlatRiskFees(array $securityCounts, array $flatFees): array
  {
    $lowCount = (int)($securityCounts['low'] ?? 0);
    $nullCount = (int)($securityCounts['null'] ?? 0);
    $specialCount = (int)($securityCounts['special'] ?? 0);
    $lowFee = $lowCount > 0 ? (float)($flatFees['lowsec'] ?? 0.0) : 0.0;
    $nullFee = $nullCount > 0 ? (float)($flatFees['nullsec'] ?? 0.0) : 0.0;
    $specialFee = $specialCount > 0 ? (float)($flatFees['special'] ?? 0.0) : 0.0;

    return [
      'lowsec' => $lowFee,
      'nullsec' => $nullFee,
      'special' => $specialFee,
      'total' => $lowFee + $nullFee + $specialFee,
    ];
  }

  private function applyVolumePressure(
    float $volume,
    float $maxVolume,
    float $haulSubtotal,
    array $volumePressure
  ): array {
    $thresholds = $volumePressure['thresholds'] ?? [];
    if (empty($volumePressure['enabled']) || $maxVolume <= 0 || !is_array($thresholds) || $thresholds === []) {
      return [
        'enabled' => !empty($volumePressure['enabled']),
        'thresholds' => [],
        'applied' => null,
        'surcharge' => 0.0,
      ];
    }

    $ratio = $maxVolume > 0 ? $volume / $maxVolume : 0.0;
    $applied = null;
    foreach ($thresholds as $threshold) {
      $minRatio = (float)($threshold['min_ratio'] ?? 0.0);
      if ($ratio >= $minRatio) {
        $applied = $threshold;
      }
    }
    if ($applied === null) {
      return [
        'enabled' => !empty($volumePressure['enabled']),
        'thresholds' => $thresholds,
        'applied' => null,
        'surcharge' => 0.0,
      ];
    }

    $surchargePct = (float)($applied['surcharge_pct'] ?? 0.0);
    $multiplier = 1 + ($surchargePct / 100);
    $surcharge = $haulSubtotal * ($multiplier - 1);

    return [
      'enabled' => !empty($volumePressure['enabled']),
      'thresholds' => $thresholds,
      'applied' => [
        'min_ratio' => $applied['min_ratio'],
        'surcharge_pct' => $surchargePct,
        'multiplier' => $multiplier,
      ],
      'surcharge' => $surcharge,
    ];
  }

  private function loadPriorityFees(int $corpId): array
  {
    $defaults = ['normal' => 0.0, 'high' => 0.0];
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'routing.priority_fee' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'routing.priority_fee' LIMIT 1"
      );
    }
    if (!$row || empty($row['setting_json'])) {
      return $defaults;
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (!is_array($decoded)) {
      return $defaults;
    }
    $fees = array_merge($defaults, $decoded);
    $fees['normal'] = max(0.0, (float)($fees['normal'] ?? 0.0));
    $fees['high'] = max(0.0, (float)($fees['high'] ?? 0.0));
    return $fees;
  }

  private function loadSecurityMultipliers(int $corpId): array
  {
    $defaults = self::DEFAULT_SECURITY_MULTIPLIERS;
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.security_multipliers' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.security_multipliers' LIMIT 1"
      );
    }
    if (!$row || empty($row['setting_json'])) {
      return $defaults;
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (!is_array($decoded)) {
      return $defaults;
    }
    $values = array_merge($defaults, $decoded);
    foreach ($defaults as $key => $_value) {
      $values[$key] = max(0.0, (float)($values[$key] ?? $defaults[$key]));
    }
    return $values;
  }

  private function loadMaxCollateral(int $corpId): float
  {
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.max_collateral' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.max_collateral' LIMIT 1"
      );
    }
    if (!$row || empty($row['setting_json'])) {
      return self::DEFAULT_MAX_COLLATERAL_ISK;
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (!is_array($decoded)) {
      return self::DEFAULT_MAX_COLLATERAL_ISK;
    }
    $value = (float)($decoded['max_collateral_isk'] ?? $decoded['max'] ?? $decoded['value'] ?? self::DEFAULT_MAX_COLLATERAL_ISK);
    if ($value <= 0) {
      return self::DEFAULT_MAX_COLLATERAL_ISK;
    }
    return $value;
  }

  private function loadFlatRiskSurcharges(int $corpId): array
  {
    $defaults = ['lowsec' => 0.0, 'nullsec' => 0.0, 'special' => 0.0];
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.flat_risk_fees' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.flat_risk_fees' LIMIT 1"
      );
    }
    if (!$row || empty($row['setting_json'])) {
      return $defaults;
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (!is_array($decoded)) {
      return $defaults;
    }
    $values = array_merge($defaults, $decoded);
    foreach ($defaults as $key => $_value) {
      $values[$key] = max(0.0, (float)($values[$key] ?? 0.0));
    }
    return $values;
  }

  private function loadVolumePressureScaling(int $corpId): array
  {
    $defaults = ['enabled' => false, 'thresholds' => []];
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'pricing.volume_pressure' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT setting_json FROM app_setting WHERE corp_id = 0 AND setting_key = 'pricing.volume_pressure' LIMIT 1"
      );
    }
    if (!$row || empty($row['setting_json'])) {
      return $defaults;
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (!is_array($decoded)) {
      return $defaults;
    }
    $enabled = !empty($decoded['enabled']);
    $rawThresholds = $decoded['thresholds'] ?? (array_is_list($decoded) ? $decoded : []);
    $thresholds = [];
    foreach ($rawThresholds as $threshold) {
      if (!is_array($threshold)) {
        continue;
      }
      $thresholdPct = isset($threshold['threshold_pct']) ? (float)$threshold['threshold_pct'] : null;
      $minRatio = isset($threshold['min_ratio']) ? (float)$threshold['min_ratio'] : null;
      if ($minRatio === null && $thresholdPct !== null) {
        $minRatio = $thresholdPct / 100;
      }
      if ($minRatio === null) {
        continue;
      }
      $minRatio = max(0.0, min(1.0, $minRatio));
      $surchargePct = isset($threshold['surcharge_pct']) ? (float)$threshold['surcharge_pct'] : 0.0;
      if ($surchargePct <= 0) {
        continue;
      }
      $thresholds[] = [
        'min_ratio' => $minRatio,
        'threshold_pct' => $thresholdPct ?? ($minRatio * 100),
        'surcharge_pct' => $surchargePct,
      ];
    }
    usort($thresholds, static fn($a, $b) => $a['min_ratio'] <=> $b['min_ratio']);
    return [
      'enabled' => $enabled,
      'thresholds' => $thresholds,
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

  private function chooseShipClass(float $volume, array $route): array
  {
    $counts = $route['security_counts'] ?? [];
    $lowSec = (int)($counts['low'] ?? ($route['ls_count'] ?? 0));
    $nullSec = (int)($counts['null'] ?? ($route['ns_count'] ?? 0));
    $special = (int)($counts['special'] ?? 0);
    $highSecOnly = $lowSec <= 0 && $nullSec <= 0 && $special <= 0;
    $shipClasses = self::SHIP_CLASSES;

    if ($highSecOnly) {
      unset($shipClasses['JF']);
    } else {
      unset($shipClasses['FREIGHTER']);
    }

    $maxVolume = $shipClasses ? max($shipClasses) : 0;
    if ($volume > $maxVolume) {
      throw new \InvalidArgumentException('oversized_volume:' . $maxVolume);
    }

    foreach ($shipClasses as $class => $maxVolume) {
      if ($volume <= $maxVolume) {
        return [
          'service_class' => $class,
          'max_volume' => $maxVolume,
          'volume_m3' => $volume,
        ];
      }
    }

    return [
      'service_class' => 'JF',
      'max_volume' => self::SHIP_CLASSES['JF'],
      'volume_m3' => $volume,
    ];
  }

  private function getRatePlan(int $corpId, string $serviceClass): array
  {
    $row = $this->db->one(
      "SELECT service_class, rate_per_jump, collateral_rate, min_price
         FROM rate_plan
        WHERE corp_id = :cid AND service_class = :service
        LIMIT 1",
      [
        'cid' => $corpId,
        'service' => $serviceClass,
      ]
    );

    if ($row === null && $corpId !== 0) {
      $row = $this->db->one(
        "SELECT service_class, rate_per_jump, collateral_rate, min_price
           FROM rate_plan
          WHERE corp_id = 0 AND service_class = :service
          LIMIT 1",
        ['service' => $serviceClass]
      );
    }

    return $row ?? [
      'service_class' => $serviceClass,
      'rate_per_jump' => 0,
      'collateral_rate' => 0,
      'min_price' => 0,
    ];
  }

  private function loadQuoteLocationMode(int $corpId): bool
  {
    if ($corpId <= 0) {
      return true;
    }

    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = 'quote.location_mode' LIMIT 1",
      ['cid' => $corpId]
    );
    if (!$row || empty($row['setting_json'])) {
      return true;
    }
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (is_array($decoded) && array_key_exists('allow_structures', $decoded)) {
      return (bool)$decoded['allow_structures'];
    }

    return true;
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
}
