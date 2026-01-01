<?php
declare(strict_types=1);

namespace App\Services;

final class EsiRateLimiter
{
  public static function shouldRetry(int $status, array $config): bool
  {
    $rateConfig = $config['esi']['rate_limit'] ?? [];
    if (($rateConfig['enabled'] ?? true) === false) {
      return false;
    }
    $retryStatuses = $rateConfig['retry_statuses'] ?? [420, 429];
    return in_array($status, $retryStatuses, true);
  }

  public static function sleepForRetry(array $headers, array $config): void
  {
    self::sleepSeconds(self::computeResetSeconds($headers, $config), $config);
  }

  public static function sleepIfLowRemaining(array $headers, array $config): void
  {
    $rateConfig = $config['esi']['rate_limit'] ?? [];
    if (($rateConfig['enabled'] ?? true) === false) {
      return;
    }

    $remain = self::headerInt($headers, ['x-esi-error-limit-remain', 'x-rate-limit-remaining']);
    $reset = self::headerInt($headers, ['x-esi-error-limit-reset', 'x-rate-limit-reset', 'retry-after']);
    if ($remain === null || $reset === null) {
      return;
    }

    $threshold = (int)($rateConfig['min_error_remain'] ?? 5);
    if ($remain > $threshold) {
      return;
    }

    $sleepSeconds = max(1, $reset) + (int)($rateConfig['sleep_buffer_seconds'] ?? 1);
    self::sleepSeconds($sleepSeconds, $config);
  }

  private static function computeResetSeconds(array $headers, array $config): int
  {
    $rateConfig = $config['esi']['rate_limit'] ?? [];
    $reset = self::headerInt($headers, ['x-esi-error-limit-reset', 'x-rate-limit-reset', 'retry-after']);
    $buffer = (int)($rateConfig['sleep_buffer_seconds'] ?? 1);
    return max(1, $reset ?? 1) + $buffer;
  }

  private static function sleepSeconds(int $seconds, array $config): void
  {
    $rateConfig = $config['esi']['rate_limit'] ?? [];
    $maxSleep = (int)($rateConfig['max_sleep_seconds'] ?? 60);
    $sleepSeconds = max(0, min($seconds, $maxSleep));
    if ($sleepSeconds <= 0) {
      return;
    }
    sleep($sleepSeconds);
  }

  private static function headerInt(array $headers, array $names): ?int
  {
    foreach ($names as $name) {
      $key = strtolower($name);
      if (!array_key_exists($key, $headers)) {
        continue;
      }
      $value = trim((string)$headers[$key]);
      if ($value === '') {
        continue;
      }
      return (int)$value;
    }
    return null;
  }
}
