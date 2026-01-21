<?php
declare(strict_types=1);

namespace App\Cache;

final class WriteThroughCacheStore implements CacheStoreInterface
{
  public function __construct(
    private DbCacheStore $dbStore,
    private ?RedisCacheStore $redisStore,
    private ?CacheMetricsCollector $metrics = null
  ) {
  }

  public function get(?int $corpId, string $cacheKeyBin): array
  {
    $this->metrics?->recordGetTotal();

    if ($this->redisStore !== null) {
      try {
        $redisHit = $this->redisStore->get($corpId, $cacheKeyBin);
        if (!empty($redisHit['hit'])) {
          $this->metrics?->recordGetHitRedis();
          return $redisHit;
        }
      } catch (\Throwable $e) {
        error_log('[cache] Redis read failed: ' . $e->getMessage());
      }
    }

    $start = microtime(true);
    $dbHit = $this->dbStore->get($corpId, $cacheKeyBin);
    $elapsedMs = (microtime(true) - $start) * 1000;
    $this->metrics?->recordDbQueryTime($elapsedMs);

    if (!empty($dbHit['hit'])) {
      $this->metrics?->recordGetHitDb();
      if ($this->redisStore !== null) {
        $this->repopulateRedis($corpId, $cacheKeyBin, $dbHit);
      }
    }

    return $dbHit;
  }

  public function put(
    ?int $corpId,
    string $cacheKeyBin,
    string $method,
    string $url,
    ?array $query,
    ?array $body,
    int $statusCode,
    ?string $etag,
    ?string $lastModified,
    int $ttlSeconds,
    string $responseJson,
    ?string $errorText = null
  ): void {
    $this->metrics?->recordSetTotal();

    $this->dbStore->put(
      $corpId,
      $cacheKeyBin,
      $method,
      $url,
      $query,
      $body,
      $statusCode,
      $etag,
      $lastModified,
      $ttlSeconds,
      $responseJson,
      $errorText
    );

    if ($this->redisStore === null) {
      return;
    }

    try {
      $expiresAt = CacheTtl::expiresAt($ttlSeconds);
      $this->redisStore->putWithExpiresAt(
        $corpId,
        $cacheKeyBin,
        $statusCode,
        $etag,
        $expiresAt,
        $responseJson
      );
    } catch (\Throwable $e) {
      $this->metrics?->recordSetRedisFail();
      error_log('[cache] Redis write failed: ' . $e->getMessage());
    }
  }

  private function repopulateRedis(?int $corpId, string $cacheKeyBin, array $entry): void
  {
    if ($this->redisStore === null) {
      return;
    }

    $expiresAt = $entry['expires_at'] ?? null;
    $expiresAt = is_string($expiresAt) ? $expiresAt : null;
    if ($expiresAt === null || CacheTtl::ttlFromExpiresAt($expiresAt) <= 0) {
      try {
        $this->redisStore->putWithExpiresAt(
          $corpId,
          $cacheKeyBin,
          (int)($entry['status_code'] ?? 0),
          $entry['etag'] ?? null,
          gmdate('Y-m-d H:i:s', time() - 1),
          (string)($entry['json'] ?? '')
        );
      } catch (\Throwable $e) {
        $this->metrics?->recordSetRedisFail();
        error_log('[cache] Redis delete failed: ' . $e->getMessage());
      }
      return;
    }

    try {
      $this->redisStore->putWithExpiresAt(
        $corpId,
        $cacheKeyBin,
        (int)($entry['status_code'] ?? 0),
        $entry['etag'] ?? null,
        $expiresAt,
        (string)($entry['json'] ?? '')
      );
    } catch (\Throwable $e) {
      $this->metrics?->recordSetRedisFail();
      error_log('[cache] Redis repopulate failed: ' . $e->getMessage());
    }
  }
}
