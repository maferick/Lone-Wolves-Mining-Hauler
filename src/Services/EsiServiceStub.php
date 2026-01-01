<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Used when DB is unavailable during bootstrap, to keep the site up (read-only).
 */
final class EsiServiceStub
{
  public function ping(): array
  {
    return [
      'ok' => false,
      'error' => 'DB unavailable; ESI services disabled',
    ];
  }

  public function __call(string $name, array $args)
  {
    throw new \RuntimeException("ESI service unavailable (DB offline).");
  }
}
