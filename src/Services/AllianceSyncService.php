<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

/**
 * src/Services/AllianceSyncService.php
 *
 * Pulls the global alliance list and caches alliance names in eve_entity.
 */
final class AllianceSyncService
{
  public function __construct(
    private Db $db,
    private EsiClient $esi
  ) {}

  public function syncAlliances(?callable $progress = null, int $listTtl = 86400, int $detailTtl = 86400): array
  {
    $startedAt = gmdate('c');
    $resp = $this->esi->get('/latest/alliances/', null, null, $listTtl);
    if (!$resp['ok'] || !is_array($resp['json'])) {
      throw new \RuntimeException('Failed to fetch alliance list from ESI.');
    }

    $ids = array_values(array_filter($resp['json'], static function ($value): bool {
      return is_int($value) || ctype_digit((string)$value);
    }));
    $total = count($ids);
    $processed = 0;
    $updated = 0;
    $errors = 0;

    foreach ($ids as $index => $allianceIdRaw) {
      $allianceId = (int)$allianceIdRaw;
      if ($allianceId <= 0) {
        continue;
      }
      try {
        $detail = $this->esi->get("/v4/alliances/{$allianceId}/", null, null, $detailTtl);
        if (!$detail['ok'] || !is_array($detail['json'])) {
          $errors++;
          continue;
        }
        $data = $detail['json'];
        $name = trim((string)($data['name'] ?? ''));
        if ($name !== '') {
          $this->db->upsertEntity($allianceId, 'alliance', $name, $data);
          $updated++;
        }
      } catch (\Throwable $e) {
        $errors++;
      }

      $processed++;
      if ($progress && (($processed % 25) === 0 || $processed === $total)) {
        $progress([
          'current' => $processed,
          'total' => $total,
          'label' => sprintf('Syncing alliances (%d/%d)', $processed, $total),
          'stage' => 'alliances',
        ]);
      }
    }

    return [
      'total' => $total,
      'processed' => $processed,
      'updated' => $updated,
      'errors' => $errors,
      'started_at' => $startedAt,
      'finished_at' => gmdate('c'),
    ];
  }
}
