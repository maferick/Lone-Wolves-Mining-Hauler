<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/EvePublicDataService.php
 *
 * Pulls public entity data (character/corp/alliance) and writes name caches.
 */
final class EvePublicDataService
{
  public function __construct(private Db $db, private array $config, private EsiClient $esi) {}

  public function character(int $characterId): array
  {
    $resp = $this->esi->get("/v5/characters/{$characterId}/", null, null, 3600);
    if (!$resp['ok'] || !is_array($resp['json'])) {
      throw new \RuntimeException("Failed to fetch character {$characterId} from ESI (HTTP {$resp['status']}).");
    }
    $data = $resp['json'];
    $name = (string)($data['name'] ?? '');
    if ($name !== '') $this->db->upsertEntity($characterId, 'character', $name, $data);
    return $data;
  }

  public function corporation(int $corpId): array
  {
    $resp = $this->esi->get("/v4/corporations/{$corpId}/", null, null, 3600);
    if (!$resp['ok'] || !is_array($resp['json'])) {
      throw new \RuntimeException("Failed to fetch corporation {$corpId} from ESI (HTTP {$resp['status']}).");
    }
    $data = $resp['json'];
    $name = (string)($data['name'] ?? '');
    if ($name !== '') $this->db->upsertEntity($corpId, 'corporation', $name, $data);
    return $data;
  }

  public function alliance(int $allianceId): array
  {
    $resp = $this->esi->get("/v4/alliances/{$allianceId}/", null, null, 3600);
    if (!$resp['ok'] || !is_array($resp['json'])) {
      throw new \RuntimeException("Failed to fetch alliance {$allianceId} from ESI (HTTP {$resp['status']}).");
    }
    $data = $resp['json'];
    $name = (string)($data['name'] ?? '');
    if ($name !== '') $this->db->upsertEntity($allianceId, 'alliance', $name, $data);
    return $data;
  }
}
