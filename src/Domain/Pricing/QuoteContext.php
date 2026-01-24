<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

final class QuoteContext
{
  public int $corpId;
  public ?int $characterId;
  public ?int $allianceId;
  /** @var array<int, string> */
  public array $aclGroups;
  public int $pickupSystemId;
  public int $dropSystemId;
  public int $pickupRegionId;
  public int $dropRegionId;
  public float $volumeM3;
  public float $basePrice;
  public float $minPrice;
  public bool $minPriceApplied;
  public float $minFeeDelta;
  public float $globalMinPrice;
  public bool $allowStacking;
  public \DateTimeImmutable $nowUtc;

  /**
   * @param array<int, string> $aclGroups
   */
  public function __construct(
    int $corpId,
    ?int $characterId,
    ?int $allianceId,
    array $aclGroups,
    int $pickupSystemId,
    int $dropSystemId,
    int $pickupRegionId,
    int $dropRegionId,
    float $volumeM3,
    float $basePrice,
    float $minPrice,
    bool $minPriceApplied,
    float $minFeeDelta,
    float $globalMinPrice,
    bool $allowStacking,
    \DateTimeImmutable $nowUtc
  ) {
    $this->corpId = $corpId;
    $this->characterId = $characterId;
    $this->allianceId = $allianceId;
    $this->aclGroups = $aclGroups;
    $this->pickupSystemId = $pickupSystemId;
    $this->dropSystemId = $dropSystemId;
    $this->pickupRegionId = $pickupRegionId;
    $this->dropRegionId = $dropRegionId;
    $this->volumeM3 = $volumeM3;
    $this->basePrice = $basePrice;
    $this->minPrice = $minPrice;
    $this->minPriceApplied = $minPriceApplied;
    $this->minFeeDelta = $minFeeDelta;
    $this->globalMinPrice = $globalMinPrice;
    $this->allowStacking = $allowStacking;
    $this->nowUtc = $nowUtc;
  }
}
