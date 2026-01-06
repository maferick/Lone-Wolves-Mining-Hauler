<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class DiscordEventService
{
  public function __construct(private Db $db, private array $config = [])
  {
  }

  public function enqueueRequestCreated(int $corpId, int $requestId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    return $this->enqueueEvent($corpId, 'request.created', $payload);
  }

  public function enqueueRequestStatusChanged(int $corpId, int $requestId, string $status, ?string $previousStatus = null, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['status'] = $status;
    $payload['previous_status'] = $previousStatus ?? '';
    return $this->enqueueEvent($corpId, 'request.status_changed', $payload);
  }

  public function enqueueContractMatched(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    return $this->enqueueEvent($corpId, 'contract.matched', $payload);
  }

  public function enqueueContractPickedUp(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    return $this->enqueueEvent($corpId, 'contract.picked_up', $payload);
  }

  public function enqueueContractCompleted(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    return $this->enqueueEvent($corpId, 'contract.completed', $payload);
  }

  public function enqueueContractFailed(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    return $this->enqueueEvent($corpId, 'contract.failed', $payload);
  }

  public function enqueueContractExpired(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    return $this->enqueueEvent($corpId, 'contract.expired', $payload);
  }

  public function enqueueAlert(int $corpId, string $message, array $details = []): int
  {
    $payload = array_merge(
      [
        'message' => $message,
        'reason' => (string)($details['reason'] ?? 'alert'),
        'event_key' => 'alert.system',
      ],
      $details
    );
    return $this->enqueueEvent($corpId, 'alert.system', $payload, $details['dedupe_key'] ?? null);
  }

  public function enqueueTestMessage(int $corpId, string $eventKey, ?int $channelMapId = null): int
  {
    $payload = $this->buildSamplePayload($corpId, $eventKey);
    return $this->enqueueEvent($corpId, $eventKey, $payload, null, $channelMapId, false);
  }

  public function enqueueAdminTask(int $corpId, string $eventKey, array $payload = []): int
  {
    $config = $this->loadConfig($corpId);
    if (!empty($config['application_id']) && empty($payload['application_id'])) {
      $payload['application_id'] = $config['application_id'];
    }
    if (!empty($config['guild_id']) && empty($payload['guild_id'])) {
      $payload['guild_id'] = $config['guild_id'];
    }
    $payload['event_key'] = $eventKey;
    return $this->db->insert('discord_outbox', [
      'corp_id' => $corpId,
      'channel_map_id' => null,
      'event_key' => $eventKey,
      'payload_json' => Db::jsonEncode($payload),
      'status' => 'queued',
      'attempts' => 0,
      'next_attempt_at' => null,
      'dedupe_key' => null,
    ]);
  }

  public function loadConfig(int $corpId): array
  {
    $row = $this->db->one(
      "SELECT * FROM discord_config WHERE corp_id = :cid LIMIT 1",
      ['cid' => $corpId]
    );

    return $row ? $row : [
      'corp_id' => $corpId,
      'enabled_webhooks' => 1,
      'enabled_bot' => 0,
      'rate_limit_per_minute' => 20,
      'dedupe_window_seconds' => 60,
      'commands_ephemeral_default' => 1,
    ];
  }

  public function resolveCorpId(?string $guildId = null): ?int
  {
    if ($guildId !== null && $guildId !== '') {
      $row = $this->db->one(
        "SELECT corp_id FROM discord_config WHERE guild_id = :guild_id LIMIT 1",
        ['guild_id' => $guildId]
      );
      if ($row) {
        return (int)$row['corp_id'];
      }
    }

    $row = $this->db->one(
      "SELECT corp_id FROM discord_config WHERE enabled_webhooks = 1 OR enabled_bot = 1 ORDER BY corp_id ASC LIMIT 1"
    );
    if ($row) {
      return (int)$row['corp_id'];
    }
    return null;
  }

  private function enqueueEvent(
    int $corpId,
    string $eventKey,
    array $payload,
    ?string $dedupeKey = null,
    ?int $channelMapId = null,
    bool $respectConfig = true
  ): int {
    if (!isset($payload['event_key'])) {
      $payload['event_key'] = $eventKey;
    }
    $config = $this->loadConfig($corpId);
    $channelMaps = $this->loadChannelMaps($corpId, $eventKey, $channelMapId);
    if ($channelMaps === []) {
      return 0;
    }

    $dedupeWindow = (int)($config['dedupe_window_seconds'] ?? 60);
    $queued = 0;
    foreach ($channelMaps as $map) {
      if ($respectConfig) {
        if ($map['mode'] === 'webhook' && empty($config['enabled_webhooks'])) {
          continue;
        }
        if ($map['mode'] === 'bot' && empty($config['enabled_bot'])) {
          continue;
        }
      }

      $keySuffix = $dedupeKey
        ?? (string)($payload['request_id'] ?? $payload['request_code'] ?? $payload['contract_id'] ?? $map['channel_map_id']);
      $mapDedupe = $eventKey . ':' . $keySuffix . ':' . $map['channel_map_id'];

      if ($dedupeWindow > 0) {
        $exists = $this->db->fetchValue(
          "SELECT outbox_id FROM discord_outbox
            WHERE dedupe_key = :dedupe_key
              AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$dedupeWindow} SECOND)
            LIMIT 1",
          [
            'dedupe_key' => $mapDedupe,
          ]
        );
        if ($exists) {
          continue;
        }
      }

      $queued += $this->db->execute(
        "INSERT INTO discord_outbox
          (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key)
         VALUES
          (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key)",
        [
          'corp_id' => $corpId,
          'channel_map_id' => (int)$map['channel_map_id'],
          'event_key' => $eventKey,
          'payload_json' => Db::jsonEncode($payload),
          'dedupe_key' => $mapDedupe,
        ]
      );
    }

    return $queued;
  }

  private function loadChannelMaps(int $corpId, string $eventKey, ?int $channelMapId = null): array
  {
    $params = [
      'cid' => $corpId,
      'event_key' => $eventKey,
    ];
    $mapClause = '';
    if ($channelMapId !== null) {
      $mapClause = ' AND channel_map_id = :channel_map_id';
      $params['channel_map_id'] = $channelMapId;
    }

    return $this->db->select(
      "SELECT channel_map_id, event_key, mode, channel_id, webhook_url, is_enabled
         FROM discord_channel_map
        WHERE corp_id = :cid
          AND event_key = :event_key
          AND is_enabled = 1{$mapClause}",
      $params
    );
  }

  private function buildSamplePayload(int $corpId, string $eventKey): array
  {
    $request = $this->db->one(
      "SELECT * FROM v_haul_request_display
        WHERE corp_id = :cid
        ORDER BY updated_at DESC, request_id DESC
        LIMIT 1",
      ['cid' => $corpId]
    );

    if (!$request) {
      return [
        'event_key' => $eventKey,
        'message' => 'Sample payload unavailable (no requests yet).',
      ];
    }

    $payload = $this->buildRequestPayload($corpId, (int)$request['request_id'], []);
    $payload['event_key'] = $eventKey;
    return $payload;
  }

  private function buildRequestPayload(int $corpId, int $requestId, array $context): array
  {
    $row = $this->db->one(
      "SELECT * FROM v_haul_request_display WHERE request_id = :rid AND corp_id = :cid LIMIT 1",
      [
        'rid' => $requestId,
        'cid' => $corpId,
      ]
    );

    $from = $row ? (string)($row['from_name'] ?? '') : '';
    $to = $row ? (string)($row['to_name'] ?? '') : '';
    $route = trim($from . ' → ' . $to);
    $requestKey = (string)($row['request_key'] ?? '');
    $requestUrl = $this->buildRequestUrl($requestKey !== '' ? $requestKey : null);

    $payload = [
      'request_id' => (int)($row['request_id'] ?? $requestId),
      'request_code' => $requestKey,
      'pickup' => $from !== '' ? $from : 'Unknown',
      'delivery' => $to !== '' ? $to : 'Unknown',
      'route' => $route,
      'jumps' => (string)($row['expected_jumps'] ?? ''),
      'volume' => $this->formatVolume((float)($row['volume_m3'] ?? 0)),
      'collateral' => $this->formatIsk((float)($row['collateral_isk'] ?? 0)),
      'reward' => $this->formatIsk((float)($row['reward_isk'] ?? 0)),
      'status' => (string)($row['status'] ?? ''),
      'priority' => (string)($row['route_profile'] ?? $row['route_policy'] ?? 'normal'),
      'ship_class' => (string)($row['ship_class'] ?? ''),
      'user' => (string)($row['requester_character_name'] ?? $row['requester_display_name'] ?? ''),
      'requester' => (string)($row['requester_character_name'] ?? $row['requester_display_name'] ?? ''),
      'requester_character_id' => (int)($row['requester_character_id'] ?? 0),
      'hauler' => (string)($row['esi_acceptor_display_name'] ?? $row['acceptor_name'] ?? ''),
      'hauler_character_id' => (int)($row['esi_acceptor_id'] ?? $row['contract_acceptor_id'] ?? $row['acceptor_id'] ?? 0),
      'ship_type_id' => 0,
      'link_request' => $requestUrl,
      'link_contract_instructions' => $requestUrl,
    ];

    return array_merge($payload, $context);
  }

  private function buildRequestUrl(?string $requestKey): string
  {
    $requestKey = trim((string)$requestKey);
    if ($requestKey === '') {
      return '';
    }
    $baseUrl = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
    $basePath = rtrim((string)($this->config['app']['base_path'] ?? ''), '/');
    $baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
    $pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
    $path = ($pathPrefix ?: '') . '/request?request_key=' . urlencode($requestKey);
    return $baseUrl !== '' ? $baseUrl . $path : $path;
  }

  private function formatIsk(float $amount): string
  {
    return number_format($amount, 2);
  }

  private function formatVolume(float $volume): string
  {
    return number_format($volume, 0);
  }
}
