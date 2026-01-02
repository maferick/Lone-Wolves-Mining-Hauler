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
    $priority = (string)($payload['priority'] ?? $payload['route_priority'] ?? $payload['profile'] ?? '');
    $volume = (float)($payload['volume_m3'] ?? $payload['volume'] ?? 0);
    $collateral = (float)($payload['collateral_isk'] ?? $payload['collateral'] ?? 0);

    if ($from === '' || $to === '') {
      throw new \InvalidArgumentException('from_system and to_system are required.');
    }
    if ($volume <= 0) {
      throw new \InvalidArgumentException('volume_m3 must be greater than zero.');
    }
    if ($collateral < 0) {
      throw new \InvalidArgumentException('collateral_isk must be zero or higher.');
    }

    $fromSystem = $this->resolveSystemByName($from);
    $toSystem = $this->resolveSystemByName($to);
    if ($fromSystem === null || $toSystem === null) {
      throw new \InvalidArgumentException('Unknown system name.');
    }

    $priority = $this->normalizePriority($priority);
    $routeProfile = 'balanced';
    $accessRules = $this->loadAccessRules($corpId);
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
