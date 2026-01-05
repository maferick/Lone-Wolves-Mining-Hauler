<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class PricingService
{
  private Db $db;
  private array $config;
  private RouteService $routeService;

  private const SHIP_CLASSES = [
    'BR' => 12500,
    'DST' => 62500,
    'JF' => 360000,
    'FREIGHTER' => 950000,
  ];

  private const SECURITY_MULTIPLIERS = [
    'low' => 0.5,
    'null' => 1.0,
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

    $allowStructures = $this->loadQuoteLocationMode($corpId);
    $structureAllowlist = $this->loadStructureAllowlist($corpId);
    $accessRules = $this->loadAccessRules($corpId);
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
    $route = $this->routeService->findRoute(
      $fromSystem['system_name'],
      $toSystem['system_name'],
      $routeProfile,
      array_merge($context, $accessRules)
    );

    $ship = $this->chooseShipClass($volume, $route);
    $ratePlan = $this->getRatePlan($corpId, $ship['service_class']);
    $priorityFees = $this->loadPriorityFees($corpId);
    $priorityFee = (float)($priorityFees[$priority] ?? 0.0);

    $jumps = (int)$route['jumps'];
    $hs = (int)$route['hs_count'];
    $ls = (int)$route['ls_count'];
    $ns = (int)$route['ns_count'];

    $ratePerJump = (float)($ratePlan['rate_per_jump'] ?? 0);
    $collateralRate = (float)($ratePlan['collateral_rate'] ?? 0);
    $minPrice = (float)($ratePlan['min_price'] ?? 0);

    $baseJumpCost = $jumps * $ratePerJump;
    $lowPenalty = $ls * $ratePerJump * self::SECURITY_MULTIPLIERS['low'];
    $nullPenalty = $ns * $ratePerJump * self::SECURITY_MULTIPLIERS['null'];
    $softPenalty = $this->softDnfPenalty($route['used_soft_dnf_rules'] ?? [], $ratePerJump);
    $jumpSubtotal = $baseJumpCost + $lowPenalty + $nullPenalty + $softPenalty['total'];
    $collateralFee = $collateral * $collateralRate;
    $priceTotal = max($minPrice, $jumpSubtotal + $collateralFee + $priorityFee);

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
      'security_counts' => [
        'high' => $hs,
        'low' => $ls,
        'null' => $ns,
      ],
      'penalties' => [
        'lowsec' => $lowPenalty,
        'nullsec' => $nullPenalty,
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
        'jump_base' => $baseJumpCost,
        'lowsec_penalty' => $lowPenalty,
        'nullsec_penalty' => $nullPenalty,
        'soft_dnf_penalty' => $softPenalty['total'],
        'priority_fee' => $priorityFee,
        'jump_subtotal' => $jumpSubtotal,
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
      if ($locationType === 'structure' && !$allowStructures) {
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
      if ($locationType === 'structure' && !$allowStructures) {
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

  private function chooseShipClass(float $volume, array $route): array
  {
    $lowSec = (int)($route['ls_count'] ?? 0);
    $nullSec = (int)($route['ns_count'] ?? 0);
    $highSecOnly = $lowSec <= 0 && $nullSec <= 0;
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
