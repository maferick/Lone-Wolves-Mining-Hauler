<?php
declare(strict_types=1);

namespace App\Cache;

use App\Db\Db;

final class RedisCacheStore implements CacheStoreInterface
{
  private RedisClientInterface $client;
  private string $prefix;

  public function __construct(RedisClientInterface $client, string $prefix = 'esi_cache:')
  {
    $this->client = $client;
    $this->prefix = $prefix;
  }

  public static function fromConfig(array $config): ?self
  {
    $redisCfg = $config['cache']['redis'] ?? [];
    $host = (string)($redisCfg['host'] ?? '');
    if ($host === '') {
      return null;
    }

    $port = (int)($redisCfg['port'] ?? 6379);
    $timeout = (float)($redisCfg['timeout_seconds'] ?? 1.5);
    $password = $redisCfg['password'] ?? null;
    $database = (int)($redisCfg['database'] ?? 0);
    $prefix = (string)($redisCfg['prefix'] ?? 'esi_cache:');

    try {
      $client = new PhpRedisClient($host, $port, $timeout, $password, $database);
    } catch (\Throwable $e) {
      error_log('[cache] Redis disabled: ' . $e->getMessage());
      return null;
    }

    return new self($client, $prefix);
  }

  public function get(?int $corpId, string $cacheKeyBin): array
  {
    $key = $this->keyFor($corpId, $cacheKeyBin);
    $value = $this->client->get($key);
    if ($value === null) {
      return ['hit' => false, 'json' => null, 'etag' => null];
    }

    $decoded = Db::jsonDecode($value, []);
    if (!is_array($decoded)) {
      $this->client->del($key);
      return ['hit' => false, 'json' => null, 'etag' => null];
    }

    $expiresAt = $decoded['expires_at'] ?? null;
    $ttl = CacheTtl::ttlFromExpiresAt(is_string($expiresAt) ? $expiresAt : null);
    if ($ttl <= 0) {
      $this->client->del($key);
      return ['hit' => false, 'json' => null, 'etag' => null];
    }

    return [
      'hit' => true,
      'json' => $decoded['response_json'] ?? null,
      'etag' => $decoded['etag'] ?? null,
      'expires_at' => $decoded['expires_at'] ?? null,
      'status_code' => $decoded['status_code'] ?? null,
    ];
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
    $expiresAt = CacheTtl::expiresAt($ttlSeconds);
    $this->putWithExpiresAt(
      $corpId,
      $cacheKeyBin,
      $statusCode,
      $etag,
      $expiresAt,
      $responseJson
    );
  }

  private function keyFor(?int $corpId, string $cacheKeyBin): string
  {
    $scope = $corpId === null ? 'shared' : (string)$corpId;
    return $this->prefix . $scope . ':' . bin2hex($cacheKeyBin);
  }

  public function putWithExpiresAt(
    ?int $corpId,
    string $cacheKeyBin,
    int $statusCode,
    ?string $etag,
    string $expiresAt,
    string $responseJson
  ): void {
    $ttl = CacheTtl::ttlFromExpiresAt($expiresAt);
    $key = $this->keyFor($corpId, $cacheKeyBin);

    if ($ttl <= 0) {
      $this->client->del($key);
      return;
    }

    $payload = [
      'response_json' => $responseJson,
      'etag' => $etag,
      'expires_at' => $expiresAt,
      'status_code' => $statusCode,
    ];
    $encoded = Db::jsonEncode($payload);
    $this->client->setex($key, $ttl, $encoded);
  }
}
