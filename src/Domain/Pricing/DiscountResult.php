<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

final class DiscountResult
{
  /** @var array<int, array<string, mixed>> */
  public array $appliedRules = [];
  /** @var array<int, array<string, mixed>> */
  public array $evaluations = [];
  public float $totalDiscount = 0.0;
  public float $finalPrice = 0.0;
  /** @var array<int, string> */
  public array $messages = [];

  public function __construct(float $basePrice)
  {
    $this->finalPrice = $basePrice;
  }

  public function addMessage(string $message): void
  {
    $this->messages[] = $message;
  }

  /**
   * @param array<string, mixed> $data
   */
  public function addAppliedRule(array $data): void
  {
    $this->appliedRules[] = $data;
  }

  /**
   * @param array<string, mixed> $data
   */
  public function addEvaluation(array $data): void
  {
    $this->evaluations[] = $data;
  }
}
