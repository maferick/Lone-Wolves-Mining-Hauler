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
    'BR' => 62000,
    'DST' => 180000,
    'FREIGHTER' => 1200000,
    'JF' => 99999999,
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

  public function quote(array $payload, int $corpId = 0): array
  {
    $from = trim((string)($payload['pickup'] ?? $payload['pickup_system'] ?? $payload['from_system'] ?? ''));
    $to = trim((string)($payload['destination'] ?? $payload['destination_system'] ?? $payload['to_system'] ?? ''));
    $profile = (string)($payload['profile'] ?? '');
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

    $route = $this->routeService->findRoute($fromSystem['system_name'], $toSystem['system_name'], $profile);

    $ship = $this->chooseShipClass($volume);
    $ratePlan = $this->getRatePlan($corpId, $ship['service_class']);

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
    $softPenalty = $this->softDnfPenalty($route['used_soft_dnf'] ?? [], $ratePerJump);
    $jumpSubtotal = $baseJumpCost + $lowPenalty + $nullPenalty + $softPenalty['total'];
    $collateralFee = $collateral * $collateralRate;
    $priceTotal = max($minPrice, $jumpSubtotal + $collateralFee);

    $breakdown = [
      'inputs' => [
        'from_system' => $fromSystem,
        'to_system' => $toSystem,
        'volume_m3' => $volume,
        'collateral_isk' => $collateral,
      ],
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
      'profile' => $route['profile'],
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

  private function resolveSystemByName(string $systemName): ?array
  {
    $name = trim($systemName);
    if ($name === '') {
      return null;
    }

    $row = $this->db->one(
      "SELECT system_id, system_name FROM map_system WHERE LOWER(system_name) = LOWER(:name) LIMIT 1",
      ['name' => $name]
    );

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

  private function chooseShipClass(float $volume): array
  {
    foreach (self::SHIP_CLASSES as $class => $maxVolume) {
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
}
