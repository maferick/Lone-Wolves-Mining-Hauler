<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/EsiService.php
 *
 * This is a stub. Next step will:
 * - Implement HTTP client (curl) with ETag + cache integration (Db::esiCacheGet/Put)
 * - Implement token refresh via sso_token table
 * - Implement corp contract pulls into esi_corp_contract tables
 */
final class EsiService
{
  public function __construct(private Db $db, private array $config) {}

  public function ping(): array
  {
    return [
      'ok' => true,
      'esi_cache_enabled' => (bool)($this->config['esi']['cache']['enabled'] ?? true),
    ];
  }
}
