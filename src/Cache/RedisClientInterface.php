<?php
declare(strict_types=1);

namespace App\Cache;

interface RedisClientInterface
{
  public function get(string $key): ?string;

  public function setex(string $key, int $ttlSeconds, string $value): bool;

  public function del(string $key): void;

  public function ping(): bool;
}
