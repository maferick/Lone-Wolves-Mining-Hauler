<?php
declare(strict_types=1);

namespace App\Services\Pricing;

use App\Db\Db;
use App\Domain\Pricing\DiscountRule;
use App\Domain\Pricing\DiscountResult;
use App\Domain\Pricing\QuoteContext;

final class DiscountEngine
{
  private Db $db;

  public function __construct(Db $db)
  {
    $this->db = $db;
  }

  public function evaluate(QuoteContext $ctx, float $basePrice): DiscountResult
  {
    $result = new DiscountResult($basePrice);
    $rules = $this->loadRules();
    if ($rules === []) {
      return $result;
    }

    $eligibleCandidates = [];
    foreach ($rules as $rule) {
      $eligibility = $this->evaluateEligibility($rule, $ctx, $basePrice);
      $eligible = $eligibility['eligible'];
      $reason = $eligibility['reason'];
      $discount = 0.0;
      if ($eligible) {
        $discount = $this->calculateDiscount($rule, $ctx, $basePrice);
      }

      $candidate = [
        'rule' => $rule,
        'eligible' => $eligible,
        'reason' => $reason,
        'discount' => $discount,
      ];
      $eligibleCandidates[] = $candidate;
    }

    $applied = false;
    $currentPrice = $basePrice;
    if ($ctx->allowStacking) {
      $stackableRules = array_values(array_filter(
        $eligibleCandidates,
        static fn(array $candidate): bool => $candidate['eligible'] && $candidate['rule']->stackable
      ));
      if ($stackableRules !== []) {
        usort($stackableRules, static function (array $a, array $b): int {
          $priority = $a['rule']->priority <=> $b['rule']->priority;
          if ($priority !== 0) {
            return $priority;
          }
          return $a['rule']->id <=> $b['rule']->id;
        });
        foreach ($stackableRules as $candidate) {
          $rule = $candidate['rule'];
          $discount = $this->calculateDiscount($rule, $ctx, $currentPrice);
          if ($discount <= 0) {
            continue;
          }
          $currentPrice = max($ctx->globalMinPrice, $currentPrice - $discount);
          $applied = true;
          $result->totalDiscount += $discount;
          $result->addAppliedRule($this->buildAppliedRule($rule, $discount, $currentPrice));
          $result->addMessage('Applied discount rule: ' . $rule->name . '.');
        }
      }
    }

    if (!$applied) {
      $best = $this->selectBestCandidate($eligibleCandidates, $ctx, $basePrice);
      if ($best !== null) {
        $rule = $best['rule'];
        $discount = $best['discount'];
        $currentPrice = max($ctx->globalMinPrice, $basePrice - $discount);
        $result->totalDiscount = $discount;
        $result->addAppliedRule($this->buildAppliedRule($rule, $discount, $currentPrice));
        $result->addMessage('Applied discount rule: ' . $rule->name . '.');
      }
    }

    foreach ($eligibleCandidates as $candidate) {
      $rule = $candidate['rule'];
      $eligible = $candidate['eligible'];
      $discount = 0.0;
      $finalPriceForRule = $basePrice;
      $appliedForRule = false;
      if ($eligible && $result->appliedRules !== []) {
        foreach ($result->appliedRules as $appliedRule) {
          if ((int)($appliedRule['rule_id'] ?? 0) === $rule->id) {
            $appliedForRule = true;
            $discount = (float)($appliedRule['discount_isk'] ?? 0);
            $finalPriceForRule = (float)($appliedRule['final_price_isk'] ?? $basePrice);
            break;
          }
        }
      }
      $result->addEvaluation($this->buildEvaluation($rule, $candidate['reason'], $appliedForRule, $basePrice, $discount, $finalPriceForRule));
    }

    $result->finalPrice = max($ctx->globalMinPrice, $basePrice - $result->totalDiscount);
    return $result;
  }

  /**
   * @return array<int, DiscountRule>
   */
  private function loadRules(): array
  {
    $rows = $this->db->select(
      "SELECT *
         FROM pricing_discount_rules
        WHERE enabled = 1"
    );
    if ($rows === []) {
      return [];
    }
    $rules = array_map(static fn(array $row): DiscountRule => new DiscountRule($row), $rows);
    usort($rules, static function (DiscountRule $a, DiscountRule $b): int {
      $priority = $a->priority <=> $b->priority;
      if ($priority !== 0) {
        return $priority;
      }
      return $a->id <=> $b->id;
    });
    return $rules;
  }

  /**
   * @return array{eligible: bool, reason: string}
   */
  private function evaluateEligibility(DiscountRule $rule, QuoteContext $ctx, float $basePrice): array
  {
    $now = $ctx->nowUtc;
    if ($rule->startsAt !== null && $now < $rule->startsAt) {
      return ['eligible' => false, 'reason' => 'Rule not active yet.'];
    }
    if ($rule->endsAt !== null && $now > $rule->endsAt) {
      return ['eligible' => false, 'reason' => 'Rule expired.'];
    }
    if (!$this->matchCustomerScope($rule, $ctx)) {
      return ['eligible' => false, 'reason' => 'Customer scope mismatch.'];
    }
    if (!$this->matchRouteScope($rule, $ctx)) {
      return ['eligible' => false, 'reason' => 'Route scope mismatch.'];
    }
    if ($rule->minVolumeM3 !== null && $ctx->volumeM3 < $rule->minVolumeM3) {
      return ['eligible' => false, 'reason' => 'Below minimum volume threshold.'];
    }
    if ($rule->maxVolumeM3 !== null && $ctx->volumeM3 > $rule->maxVolumeM3) {
      return ['eligible' => false, 'reason' => 'Above maximum volume threshold.'];
    }
    if ($rule->minRewardIsk !== null && $basePrice < $rule->minRewardIsk) {
      return ['eligible' => false, 'reason' => 'Below minimum reward threshold.'];
    }
    if ($rule->maxRewardIsk !== null && $basePrice > $rule->maxRewardIsk) {
      return ['eligible' => false, 'reason' => 'Above maximum reward threshold.'];
    }
    if ($rule->offpeakStart && $rule->offpeakEnd) {
      if (!$this->isWithinOffpeak($rule->offpeakStart, $rule->offpeakEnd, $now)) {
        return ['eligible' => false, 'reason' => 'Outside off-peak window.'];
      }
    }
    if ($rule->minContractsInWindow !== null || $rule->windowHours !== null) {
      if ($rule->minContractsInWindow === null || $rule->windowHours === null) {
        return ['eligible' => false, 'reason' => 'Bundle window incomplete.'];
      }
      if (!$this->checkBundleWindow($ctx, $rule->minContractsInWindow, $rule->windowHours)) {
        return ['eligible' => false, 'reason' => 'Bundle threshold not met.'];
      }
    }
    return ['eligible' => true, 'reason' => 'Eligible.'];
  }

  private function matchCustomerScope(DiscountRule $rule, QuoteContext $ctx): bool
  {
    switch ($rule->customerScope) {
      case 'any':
        return true;
      case 'character':
        return $ctx->characterId !== null && (string)$ctx->characterId === (string)($rule->scopeId ?? '');
      case 'corporation':
        return (string)$ctx->corpId === (string)($rule->scopeId ?? '');
      case 'alliance':
        return $ctx->allianceId !== null && (string)$ctx->allianceId === (string)($rule->scopeId ?? '');
      case 'acl_group':
        return $rule->scopeId !== null && in_array($rule->scopeId, $ctx->aclGroups, true);
    }
    return false;
  }

  private function matchRouteScope(DiscountRule $rule, QuoteContext $ctx): bool
  {
    switch ($rule->routeScope) {
      case 'any':
        return true;
      case 'lane':
        return $rule->pickupId !== null
          && $rule->dropId !== null
          && $ctx->pickupSystemId === $rule->pickupId
          && $ctx->dropSystemId === $rule->dropId;
      case 'pickup_region':
        return $rule->pickupId !== null && $ctx->pickupRegionId === $rule->pickupId;
      case 'drop_region':
        return $rule->dropId !== null && $ctx->dropRegionId === $rule->dropId;
      case 'pickup_system':
        return $rule->pickupId !== null && $ctx->pickupSystemId === $rule->pickupId;
      case 'drop_system':
        return $rule->dropId !== null && $ctx->dropSystemId === $rule->dropId;
    }
    return false;
  }

  private function isWithinOffpeak(string $start, string $end, \DateTimeImmutable $now): bool
  {
    $startTime = \DateTimeImmutable::createFromFormat('H:i', $start, new \DateTimeZone('UTC'));
    $endTime = \DateTimeImmutable::createFromFormat('H:i', $end, new \DateTimeZone('UTC'));
    if ($startTime === false || $endTime === false) {
      return false;
    }
    $current = $now->format('H:i');
    if ($start <= $end) {
      return $current >= $start && $current <= $end;
    }
    return $current >= $start || $current <= $end;
  }

  private function checkBundleWindow(QuoteContext $ctx, int $minContracts, int $windowHours): bool
  {
    $windowStart = $ctx->nowUtc->modify('-' . $windowHours . ' hours')->format('Y-m-d H:i:s');
    $count = (int)$this->db->scalar(
      "SELECT COUNT(*) FROM quote WHERE corp_id = :cid AND created_at >= :window_start",
      ['cid' => $ctx->corpId, 'window_start' => $windowStart]
    );
    return ($count + 1) >= $minContracts;
  }

  private function calculateDiscount(DiscountRule $rule, QuoteContext $ctx, float $price): float
  {
    $discount = 0.0;
    if ($rule->discountType === 'percent') {
      $discount = $price * ($rule->discountValue / 100.0);
    } elseif ($rule->discountType === 'flat_isk') {
      $discount = $rule->discountValue;
    } elseif ($rule->discountType === 'waive_min_fee') {
      $discount = $ctx->minPriceApplied ? $ctx->minFeeDelta : 0.0;
    }

    if ($rule->maxDiscountIsk !== null) {
      $discount = min($discount, $rule->maxDiscountIsk);
    }

    $floor = $ctx->globalMinPrice;
    if ($rule->minFinalPriceIsk !== null) {
      $floor = max($floor, $rule->minFinalPriceIsk);
    }
    $maxDiscountAllowed = max(0.0, $price - $floor);
    $discount = min($discount, $maxDiscountAllowed);
    if ($discount < 0) {
      return 0.0;
    }
    return $discount;
  }

  /**
   * @param array<int, array<string, mixed>> $candidates
   */
  private function selectBestCandidate(array $candidates, QuoteContext $ctx, float $basePrice): ?array
  {
    $best = null;
    foreach ($candidates as $candidate) {
      if (!$candidate['eligible']) {
        continue;
      }
      $discount = $candidate['discount'];
      if ($discount <= 0) {
        continue;
      }
      if ($best === null) {
        $best = $candidate;
        continue;
      }
      if ($discount > $best['discount']) {
        $best = $candidate;
        continue;
      }
      if (abs($discount - $best['discount']) < 0.0001) {
        $priority = $candidate['rule']->priority <=> $best['rule']->priority;
        if ($priority < 0 || ($priority === 0 && $candidate['rule']->id < $best['rule']->id)) {
          $best = $candidate;
        }
      }
    }
    if ($best === null) {
      return null;
    }
    $best['discount'] = $this->calculateDiscount($best['rule'], $ctx, $basePrice);
    return $best;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildAppliedRule(DiscountRule $rule, float $discount, float $finalPrice): array
  {
    return [
      'rule_id' => $rule->id,
      'rule_name' => $rule->name,
      'discount_isk' => $discount,
      'final_price_isk' => $finalPrice,
      'stackable' => $rule->stackable,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildEvaluation(
    DiscountRule $rule,
    string $reason,
    bool $applied,
    float $basePrice,
    float $discount,
    float $finalPrice
  ): array {
    return [
      'rule_id' => $rule->id,
      'rule_name' => $rule->name,
      'eligible_reason' => $reason,
      'applied' => $applied,
      'base_price_isk' => $basePrice,
      'discount_isk' => $discount,
      'final_price_isk' => $finalPrice,
    ];
  }
}
