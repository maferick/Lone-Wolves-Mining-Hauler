<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

final class DiscountRule
{
  public int $id;
  public string $name;
  public ?string $description;
  public bool $enabled;
  public int $priority;
  public bool $stackable;
  public ?\DateTimeImmutable $startsAt;
  public ?\DateTimeImmutable $endsAt;
  public string $customerScope;
  public ?string $scopeId;
  public string $routeScope;
  public ?int $pickupId;
  public ?int $dropId;
  public ?float $minVolumeM3;
  public ?float $maxVolumeM3;
  public ?float $minRewardIsk;
  public ?float $maxRewardIsk;
  public ?int $minContractsInWindow;
  public ?int $windowHours;
  public ?string $offpeakStart;
  public ?string $offpeakEnd;
  public string $discountType;
  public float $discountValue;
  public ?float $maxDiscountIsk;
  public ?float $minFinalPriceIsk;

  public function __construct(array $row)
  {
    $this->id = (int)($row['id'] ?? 0);
    $this->name = (string)($row['name'] ?? '');
    $this->description = isset($row['description']) ? (string)$row['description'] : null;
    $this->enabled = (bool)($row['enabled'] ?? false);
    $this->priority = (int)($row['priority'] ?? 0);
    $this->stackable = (bool)($row['stackable'] ?? false);
    $this->startsAt = !empty($row['starts_at']) ? new \DateTimeImmutable((string)$row['starts_at'], new \DateTimeZone('UTC')) : null;
    $this->endsAt = !empty($row['ends_at']) ? new \DateTimeImmutable((string)$row['ends_at'], new \DateTimeZone('UTC')) : null;
    $this->customerScope = (string)($row['customer_scope'] ?? 'any');
    $this->scopeId = isset($row['scope_id']) ? (string)$row['scope_id'] : null;
    $this->routeScope = (string)($row['route_scope'] ?? 'any');
    $this->pickupId = isset($row['pickup_id']) && $row['pickup_id'] !== null ? (int)$row['pickup_id'] : null;
    $this->dropId = isset($row['drop_id']) && $row['drop_id'] !== null ? (int)$row['drop_id'] : null;
    $this->minVolumeM3 = isset($row['min_volume_m3']) && $row['min_volume_m3'] !== null ? (float)$row['min_volume_m3'] : null;
    $this->maxVolumeM3 = isset($row['max_volume_m3']) && $row['max_volume_m3'] !== null ? (float)$row['max_volume_m3'] : null;
    $this->minRewardIsk = isset($row['min_reward_isk']) && $row['min_reward_isk'] !== null ? (float)$row['min_reward_isk'] : null;
    $this->maxRewardIsk = isset($row['max_reward_isk']) && $row['max_reward_isk'] !== null ? (float)$row['max_reward_isk'] : null;
    $this->minContractsInWindow = isset($row['min_contracts_in_window']) && $row['min_contracts_in_window'] !== null
      ? (int)$row['min_contracts_in_window']
      : null;
    $this->windowHours = isset($row['window_hours']) && $row['window_hours'] !== null ? (int)$row['window_hours'] : null;
    $this->offpeakStart = isset($row['offpeak_start_hhmm']) ? (string)$row['offpeak_start_hhmm'] : null;
    $this->offpeakEnd = isset($row['offpeak_end_hhmm']) ? (string)$row['offpeak_end_hhmm'] : null;
    $this->discountType = (string)($row['discount_type'] ?? 'percent');
    $this->discountValue = isset($row['discount_value']) ? (float)$row['discount_value'] : 0.0;
    $this->maxDiscountIsk = isset($row['max_discount_isk']) && $row['max_discount_isk'] !== null ? (float)$row['max_discount_isk'] : null;
    $this->minFinalPriceIsk = isset($row['min_final_price_isk']) && $row['min_final_price_isk'] !== null ? (float)$row['min_final_price_isk'] : null;
  }
}
