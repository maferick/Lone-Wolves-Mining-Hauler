<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class HaulRequestService
{
  public function __construct(private Db $db)
  {
  }

  public function createFromQuote(int $quoteId, array $authCtx, int $corpId): array
  {
    $quote = $this->db->one(
      "SELECT quote_id, corp_id, from_system_id, to_system_id, profile, route_json, breakdown_json, volume_m3, collateral_isk, price_total
         FROM quote
        WHERE quote_id = :qid AND corp_id = :cid
        LIMIT 1",
      ['qid' => $quoteId, 'cid' => $corpId]
    );

    if (!$quote) {
      throw new \RuntimeException('Quote not found.');
    }

    $route = Db::jsonDecode((string)$quote['route_json'], []);
    $breakdown = Db::jsonDecode((string)$quote['breakdown_json'], []);

    $service = $this->db->one(
      "SELECT service_id
         FROM haul_service
        WHERE corp_id = :cid AND is_enabled = 1
        ORDER BY service_id ASC
        LIMIT 1",
      ['cid' => $corpId]
    );

    if (!$service) {
      throw new \RuntimeException('No enabled hauling service configured.');
    }

    $routeIds = [];
    if (is_array($route) && isset($route['path']) && is_array($route['path'])) {
      foreach ($route['path'] as $node) {
        if (!is_array($node)) {
          continue;
        }
        $routeIds[] = (int)($node['system_id'] ?? 0);
      }
      $routeIds = array_values(array_filter($routeIds, static fn($id) => $id > 0));
    }

    $shipClass = (string)($breakdown['ship_class']['service_class'] ?? '');
    $routePolicy = $this->normalizeRoutePolicy((string)($route['profile'] ?? $route['route_profile'] ?? $quote['profile'] ?? 'balanced'));

    $requestId = $this->db->insert('haul_request', [
      'corp_id' => $corpId,
      'service_id' => (int)$service['service_id'],
      'requester_user_id' => (int)($authCtx['user_id'] ?? 0),
      'requester_character_id' => $authCtx['character_id'] ?? null,
      'requester_character_name' => $authCtx['character_name'] ?? null,
      'title' => 'Quote #' . (string)$quoteId,
      'notes' => null,
      'from_location_id' => (int)$quote['from_system_id'],
      'from_location_type' => 'system',
      'to_location_id' => (int)$quote['to_system_id'],
      'to_location_type' => 'system',
      'volume_m3' => (float)$quote['volume_m3'],
      'collateral_isk' => (float)$quote['collateral_isk'],
      'reward_isk' => (float)$quote['price_total'],
      'quote_id' => (int)$quote['quote_id'],
      'ship_class' => $shipClass !== '' ? $shipClass : null,
      'expected_jumps' => (int)($route['jumps'] ?? 0),
      'route_policy' => $routePolicy,
      'route_system_ids' => $routeIds ? Db::jsonEncode($routeIds) : null,
      'price_breakdown_json' => Db::jsonEncode($breakdown),
      'status' => 'requested',
    ]);

    $this->db->insert('haul_event', [
      'request_id' => $requestId,
      'event_type' => 'created',
      'message' => 'Request created from quote.',
      'payload_json' => Db::jsonEncode([
        'quote_id' => (int)$quote['quote_id'],
        'profile' => (string)($quote['profile'] ?? 'normal'),
      ]),
      'created_by_user_id' => (int)($authCtx['user_id'] ?? 0) ?: null,
    ]);

    return [
      'request_id' => $requestId,
      'quote' => $quote,
      'route' => $route,
      'breakdown' => $breakdown,
    ];
  }

  private function normalizeRoutePolicy(string $policy): string
  {
    $normalized = strtolower(trim($policy));
    if (in_array($normalized, ['normal', 'high'], true)) {
      return 'balanced';
    }
    $allowed = ['shortest', 'balanced', 'safest', 'avoid_low', 'avoid_null', 'custom'];
    if (!in_array($normalized, $allowed, true)) {
      return 'balanced';
    }
    return $normalized;
  }
}
