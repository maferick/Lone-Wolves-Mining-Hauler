<?php
declare(strict_types=1);

namespace App\Cache;

final class CacheTtl
{
  public static function normalizeTtlSeconds(int $ttlSeconds): int
  {
    return max(30, $ttlSeconds);
  }

  public static function expiresAt(int $ttlSeconds, ?int $now = null): string
  {
    $normalized = self::normalizeTtlSeconds($ttlSeconds);
    $timestamp = ($now ?? time()) + $normalized;
    return gmdate('Y-m-d H:i:s', $timestamp);
  }

  public static function ttlFromExpiresAt(?string $expiresAt, ?int $now = null): int
  {
    if (!$expiresAt) {
      return 0;
    }
    $expiresTs = strtotime($expiresAt);
    if ($expiresTs === false) {
      return 0;
    }
    $ttl = $expiresTs - ($now ?? time());
    return max(0, $ttl);
  }
}
