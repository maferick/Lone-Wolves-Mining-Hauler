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
    $from = trim((string)($payload['from_system'] ?? ''));
    $to = trim((string)($payload['to_system'] ?? ''));
    $profile = (string)($payload['profile'] ?? '');
    $volume = (float)($payload['volume_m3'] ?? 0);
    $collateral = (float)($payload['collateral_isk'] ?? 0);

    if ($from === '' || $to === '') {
      throw new \InvalidArgumentException('from_system and to_system are required.');
    }
    if ($volume <= 0) {
      throw new \InvalidArgumentException('volume_m3 must be greater than zero.');
    }
    if ($collateral < 0) {
      throw new \InvalidArgumentException('collateral_isk must be zero or higher.');
    }

    $route = $this->routeService->findRoute($from, $to, $profile);

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
    $jumpSubtotal = $baseJumpCost + $lowPenalty + $nullPenalty;
    $collateralFee = $collateral * $collateralRate;
    $priceTotal = max($minPrice, $jumpSubtotal + $collateralFee);

    $breakdown = [
      'ship_class' => $ship,
      'jumps' => $jumps,
      'security_counts' => [
        'high' => $hs,
        'low' => $ls,
        'null' => $ns,
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
