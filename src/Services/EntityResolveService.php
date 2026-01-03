<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/EntityResolveService.php
 *
 * Resolves entity names from cached data, refreshing from ESI when stale.
 */
final class EntityResolveService
{
  private const CHARACTER_TTL_SECONDS = 86400;

  public function __construct(
    private Db $db,
    private EsiClient $esi,
    private int $characterTtlSeconds = self::CHARACTER_TTL_SECONDS
  ) {
  }

  public function resolveCharacterName(int $characterId): string
  {
    if ($characterId <= 0) {
      return '';
    }

    $cached = $this->db->one(
      "SELECT name, last_seen_at
         FROM eve_entity
        WHERE entity_id = :id AND entity_type = 'character'
        LIMIT 1",
      ['id' => $characterId]
    );

    $cachedName = $cached ? trim((string)($cached['name'] ?? '')) : '';
    $cachedSeen = $cached ? (string)($cached['last_seen_at'] ?? '') : '';

    $freshThreshold = gmdate('Y-m-d H:i:s', time() - $this->characterTtlSeconds);
    if ($cachedName !== '' && $cachedSeen !== '' && $cachedSeen >= $freshThreshold) {
      return $cachedName;
    }

    $resp = $this->esi->get("/v5/characters/{$characterId}/", null, null, 3600);
    if ($resp['ok'] && is_array($resp['json'])) {
      $name = trim((string)($resp['json']['name'] ?? ''));
      if ($name !== '') {
        $this->db->upsertEntity($characterId, 'character', $name, $resp['json']);
        return $name;
      }
    }

    return $cachedName;
  }
}
