<?php
declare(strict_types=1);

namespace App\Cache;

final class PhpRedisClient implements RedisClientInterface
{
  private \Redis $redis;

  public function __construct(
    string $host,
    int $port,
    float $timeoutSeconds,
    ?string $password,
    int $database
  ) {
    if (!class_exists('Redis')) {
      throw new \RuntimeException('phpredis extension not available.');
    }

    $redis = new \Redis();
    $connected = $redis->connect($host, $port, $timeoutSeconds);
    if (!$connected) {
      throw new \RuntimeException('Failed to connect to Redis.');
    }

    if ($password !== null && $password !== '') {
      if (!$redis->auth($password)) {
        throw new \RuntimeException('Redis AUTH failed.');
      }
    }

    if ($database > 0 && !$redis->select($database)) {
      throw new \RuntimeException('Redis SELECT failed.');
    }

    $this->redis = $redis;
  }

  public function get(string $key): ?string
  {
    $value = $this->redis->get($key);
    if ($value === false) {
      return null;
    }
    return is_string($value) ? $value : null;
  }

  public function setex(string $key, int $ttlSeconds, string $value): bool
  {
    return (bool)$this->redis->setex($key, $ttlSeconds, $value);
  }

  public function del(string $key): void
  {
    $this->redis->del($key);
  }

  public function ping(): bool
  {
    $response = $this->redis->ping();
    if ($response === true) {
      return true;
    }
    if (is_string($response)) {
      return strtoupper($response) === 'PONG';
    }
    return false;
  }
}
