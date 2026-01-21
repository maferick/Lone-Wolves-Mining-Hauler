<?php
declare(strict_types=1);

namespace App\Cache;

interface CacheStoreInterface
{
  /**
   * @return array{hit: bool, json: ?string, etag: ?string, expires_at?: ?string, status_code?: ?int}
   */
  public function get(?int $corpId, string $cacheKeyBin): array;

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
  ): void;
}
