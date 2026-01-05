<?php
declare(strict_types=1);

namespace App\Services;

final class BuybackHaulageService
{
  public const MAX_VOLUME_M3 = 950000.0;
  public const TIER_COUNT = 4;

  /**
   * @return array<int, array{max_m3: float, price_isk: float}>
   */
  public static function defaultTiers(): array
  {
    $maxes = [250000.0, 500000.0, 750000.0, self::MAX_VOLUME_M3];
    return array_map(static fn(float $max): array => [
      'max_m3' => $max,
      'price_isk' => 0.0,
    ], $maxes);
  }

  /**
   * @param array<string, mixed> $setting
   * @return array<int, array{max_m3: float, price_isk: float}>
   */
  public static function normalizeSetting(array $setting): array
  {
    $tiers = $setting['tiers'] ?? null;
    $fallbackPrice = null;
    if (array_key_exists('price_isk', $setting) && is_numeric($setting['price_isk'])) {
      $fallbackPrice = max(0.0, (float)$setting['price_isk']);
    }
    return self::normalizeTiers(is_array($tiers) ? $tiers : null, $fallbackPrice);
  }

  /**
   * @param array<int, mixed>|null $tiers
   * @return array<int, array{max_m3: float, price_isk: float}>
   */
  public static function normalizeTiers(?array $tiers, ?float $fallbackPrice = null): array
  {
    $defaults = self::defaultTiers();
    if (!$tiers) {
      if ($fallbackPrice !== null) {
        return array_map(static fn(array $tier): array => [
          'max_m3' => (float)$tier['max_m3'],
          'price_isk' => $fallbackPrice,
        ], $defaults);
      }
      return $defaults;
    }

    $normalized = [];
    foreach ($tiers as $tier) {
      if (!is_array($tier)) {
        continue;
      }
      if (count($normalized) >= self::TIER_COUNT) {
        break;
      }
      $max = isset($tier['max_m3']) && is_numeric($tier['max_m3'])
        ? (float)$tier['max_m3']
        : 0.0;
      $price = isset($tier['price_isk']) && is_numeric($tier['price_isk'])
        ? (float)$tier['price_isk']
        : 0.0;
      $normalized[] = [
        'max_m3' => $max,
        'price_isk' => max(0.0, $price),
      ];
    }

    if (count($normalized) < self::TIER_COUNT) {
      foreach (array_slice($defaults, count($normalized)) as $tier) {
        $normalized[] = [
          'max_m3' => (float)$tier['max_m3'],
          'price_isk' => $fallbackPrice ?? (float)$tier['price_isk'],
        ];
      }
    }

    usort($normalized, static fn(array $a, array $b): int => $a['max_m3'] <=> $b['max_m3']);
    return $normalized;
  }

  /**
   * @param array<int, array{max_m3: float, price_isk: float}> $tiers
   */
  public static function priceForVolume(array $tiers, float $volumeM3): float
  {
    foreach ($tiers as $tier) {
      if ($volumeM3 <= (float)$tier['max_m3']) {
        return max(0.0, (float)$tier['price_isk']);
      }
    }
    return 0.0;
  }

  /**
   * @param array<int, array{max_m3: float, price_isk: float}> $tiers
   */
  public static function hasEnabledTier(array $tiers): bool
  {
    foreach ($tiers as $tier) {
      if ((float)$tier['price_isk'] > 0) {
        return true;
      }
    }
    return false;
  }
}
