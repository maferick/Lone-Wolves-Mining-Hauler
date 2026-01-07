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
    $dedupeKey = $this->buildParentMessageDedupeKey($requestId);
    return $this->enqueueEvent($corpId, 'request.created', $payload, $dedupeKey);
  }

  public function enqueueRequestStatusChanged(int $corpId, int $requestId, string $status, ?string $previousStatus = null, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['status'] = $status;
    $payload['previous_status'] = $previousStatus ?? '';
    $dedupeKey = $this->buildThreadMessageDedupeKey('request.status_changed', $payload);
    $queued = $this->enqueueEvent($corpId, 'request.status_changed', $payload, $dedupeKey);
    if ($status === 'completed') {
      $queued += $this->enqueueThreadComplete($corpId, $requestId);
    }
    return $queued;
  }

  public function enqueueContractMatched(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    $dedupeKey = $this->buildThreadMessageDedupeKey('contract.matched', $payload);
    return $this->enqueueEvent($corpId, 'contract.matched', $payload, $dedupeKey);
  }

  public function enqueueContractPickedUp(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    $dedupeKey = $this->buildThreadMessageDedupeKey('contract.picked_up', $payload);
    return $this->enqueueEvent($corpId, 'contract.picked_up', $payload, $dedupeKey);
  }

  public function enqueueContractCompleted(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    $dedupeKey = $this->buildThreadMessageDedupeKey('contract.completed', $payload);
    $queued = $this->enqueueEvent($corpId, 'contract.completed', $payload, $dedupeKey);
    $queued += $this->enqueueThreadComplete($corpId, $requestId);
    return $queued;
  }

  public function enqueueContractFailed(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    $dedupeKey = $this->buildThreadMessageDedupeKey('contract.failed', $payload);
    return $this->enqueueEvent($corpId, 'contract.failed', $payload, $dedupeKey);
  }

  public function enqueueContractExpired(int $corpId, int $requestId, int $contractId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $payload['contract_id'] = $contractId;
    $dedupeKey = $this->buildThreadMessageDedupeKey('contract.expired', $payload);
    return $this->enqueueEvent($corpId, 'contract.expired', $payload, $dedupeKey);
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
    $dedupeKey = $details['dedupe_key'] ?? $this->buildThreadMessageDedupeKey('alert.system', $payload);
    return $this->enqueueEvent($corpId, 'alert.system', $payload, $dedupeKey);
  }

  public function enqueueTestMessage(int $corpId, string $eventKey, ?int $channelMapId = null): int
  {
    $payload = $this->buildSamplePayload($corpId, $eventKey);
    return $this->enqueueEvent($corpId, $eventKey, $payload, null, $channelMapId, false);
  }

  public function enqueueTemplateTest(int $corpId, string $eventKey, array $options = []): int
  {
    $delivery = strtolower(trim((string)($options['delivery'] ?? 'bot')));
    $channelId = (string)($options['channel_id'] ?? '');
    if ($delivery === 'bot' && $channelId === '') {
      $config = $this->loadConfig($corpId);
      $channelId = (string)($config['hauling_channel_id'] ?? '');
    }

    $payload = [
      'event_key' => 'discord.template.test',
      'template_event_key' => $eventKey,
      'template_payload' => $this->buildSamplePayload($corpId, $eventKey),
      'delivery' => $delivery,
      'webhook_provider' => (string)($options['webhook_provider'] ?? ''),
      'webhook_url' => (string)($options['webhook_url'] ?? ''),
      'channel_id' => $channelId,
    ];

    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.template.test', $payload);

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.template.test',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => null,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  public function enqueueThreadTest(int $corpId, int $durationMinutes): int
  {
    $payload = [
      'event_key' => 'discord.thread.test',
      'duration_minutes' => $durationMinutes,
    ];

    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.thread.test', $payload);

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.thread.test',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => null,
        'idempotency_key' => $idempotencyKey,
      ]
    );
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
    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, $eventKey, $payload);

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => $eventKey,
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => null,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  public function enqueueRoleSyncUser(int $corpId, int $userId, string $discordUserId, string $action = 'sync'): int
  {
    $payload = [
      'user_id' => $userId,
      'discord_user_id' => $discordUserId,
      'action' => $action,
    ];
    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.roles.sync_user', $payload);

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.roles.sync_user',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => null,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  public function enqueueRoleSyncAll(int $corpId): int
  {
    $rows = $this->db->select(
      "SELECT l.user_id, l.discord_user_id
         FROM discord_user_link l
         JOIN app_user u ON u.user_id = l.user_id
        WHERE u.corp_id = :cid",
      ['cid' => $corpId]
    );
    $queued = 0;
    foreach ($rows as $row) {
      $this->enqueueRoleSyncUser($corpId, (int)$row['user_id'], (string)$row['discord_user_id']);
      $queued += 1;
    }
    return $queued;
  }

  public function enqueueThreadCreate(int $corpId, int $requestId, array $context = []): int
  {
    $payload = $this->buildRequestPayload($corpId, $requestId, $context);
    $requesterRow = $this->db->one(
      "SELECT requester_user_id FROM haul_request WHERE request_id = :rid LIMIT 1",
      ['rid' => $requestId]
    );
    if ($requesterRow) {
      $payload['requester_user_id'] = (int)$requesterRow['requester_user_id'];
    }
    $payload['event_key'] = 'discord.thread.create';
    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.thread.create', $payload);
    $dedupeKey = $this->buildThreadCreateDedupeKey($requestId);

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.thread.create',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => $dedupeKey,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  public function enqueueThreadComplete(int $corpId, int $requestId): int
  {
    $config = $this->loadConfig($corpId);
    if (($config['channel_mode'] ?? 'threads') !== 'threads') {
      return 0;
    }
    if (empty($config['auto_archive_on_complete']) && empty($config['auto_lock_on_complete'])) {
      return 0;
    }

    $thread = $this->db->one(
      "SELECT thread_id FROM discord_thread WHERE request_id = :rid AND corp_id = :cid LIMIT 1",
      ['rid' => $requestId, 'cid' => $corpId]
    );
    if (!$thread) {
      return 0;
    }

    $payload = [
      'request_id' => $requestId,
      'thread_id' => (string)$thread['thread_id'],
      'event_key' => 'discord.thread.complete',
    ];
    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.thread.complete', $payload);

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.thread.complete',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => null,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  public function enqueueThreadDelete(int $corpId, int $requestId, array $threadRow): int
  {
    $config = $this->loadConfig($corpId);
    if (($config['channel_mode'] ?? 'threads') !== 'threads') {
      return 0;
    }

    $threadId = trim((string)($threadRow['thread_id'] ?? ''));
    $opsChannelId = trim((string)($threadRow['ops_channel_id'] ?? ''));
    $anchorMessageId = trim((string)($threadRow['anchor_message_id'] ?? ''));
    if ($threadId === '' && $anchorMessageId === '') {
      return 0;
    }

    $payload = [
      'request_id' => $requestId,
      'thread_id' => $threadId,
      'ops_channel_id' => $opsChannelId,
      'anchor_message_id' => $anchorMessageId,
      'event_key' => 'discord.thread.delete',
    ];
    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.thread.delete', $payload);
    $dedupeKey = 'discord:thread-delete:' . $requestId;

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.thread.delete',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => $dedupeKey,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  public function enqueueBotTestMessage(int $corpId): int
  {
    $payload = ['event_key' => 'discord.bot.test_message'];
    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.bot.test_message', $payload);

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.bot.test_message',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => null,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  public function buildSamplePayloadForEvent(int $corpId, string $eventKey): array
  {
    return $this->buildSamplePayload($corpId, $eventKey);
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
      'application_id' => (string)($this->config['discord']['application_id'] ?? ''),
      'public_key' => (string)($this->config['discord']['public_key'] ?? ''),
      'guild_id' => (string)($this->config['discord']['guild_id'] ?? ''),
      'rate_limit_per_minute' => 20,
      'dedupe_window_seconds' => 60,
      'commands_ephemeral_default' => 1,
      'channel_mode' => 'threads',
      'hauling_channel_id' => '',
      'requester_thread_access' => 'read_only',
      'auto_thread_create_on_request' => 0,
      'thread_auto_archive_minutes' => 1440,
      'auto_archive_on_complete' => 1,
      'auto_lock_on_complete' => 1,
      'role_map_json' => null,
      'last_bot_action_at' => null,
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
      $mapDedupe = $dedupeKey ?? $eventKey . ':' . $keySuffix . ':' . $map['channel_map_id'];
      $idempotencyKey = $this->buildIdempotencyKey($corpId, (int)$map['channel_map_id'], $eventKey, $payload);

      if ($dedupeKey !== null) {
        $exists = $this->db->fetchValue(
          "SELECT outbox_id FROM discord_outbox
            WHERE dedupe_key = :dedupe_key
            LIMIT 1",
          [
            'dedupe_key' => $mapDedupe,
          ]
        );
        if ($exists) {
          continue;
        }
      } elseif ($dedupeWindow > 0) {
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
        "INSERT IGNORE INTO discord_outbox
          (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
         VALUES
          (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
        [
          'corp_id' => $corpId,
          'channel_map_id' => (int)$map['channel_map_id'],
          'event_key' => $eventKey,
          'payload_json' => Db::jsonEncode($payload),
          'dedupe_key' => $mapDedupe,
          'idempotency_key' => $idempotencyKey,
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

  private function buildIdempotencyKey(int $corpId, ?int $channelMapId, string $eventKey, array $payload): string
  {
    $identity = $this->resolveIdempotencyIdentity($eventKey, $payload);
    $mapKey = $channelMapId !== null ? (string)$channelMapId : '0';
    return 'discord:' . $corpId . ':' . $mapKey . ':' . $identity;
  }

  private function resolveIdempotencyIdentity(string $eventKey, array $payload): string
  {
    $contractStatus = $this->canonicalContractStatusForEvent($eventKey);
    $contractId = (int)($payload['contract_id'] ?? 0);
    if ($contractStatus !== null && $contractId > 0) {
      return 'contract:' . $contractId . ':' . $contractStatus;
    }

    $requestId = (int)($payload['request_id'] ?? 0);
    $requestStatus = (string)($payload['status'] ?? $payload['request_status'] ?? '');
    if ($requestId > 0 && $requestStatus !== '') {
      return 'request:' . $requestId . ':' . $this->normalizeStatus($requestStatus);
    }

    $payloadHash = sha1(Db::jsonEncode($payload));
    return 'event:' . $eventKey . ':' . $payloadHash;
  }

  private function canonicalContractStatusForEvent(string $eventKey): ?string
  {
    return match ($eventKey) {
      'contract.matched' => 'matched',
      'contract.picked_up' => 'picked_up',
      'contract.completed' => 'completed',
      'contract.failed' => 'failed',
      'contract.expired' => 'expired',
      default => null,
    };
  }

  private function normalizeStatus(string $status): string
  {
    return strtolower(trim($status));
  }

  private function buildParentMessageDedupeKey(int $requestId): string
  {
    return 'discord:parent:' . $requestId;
  }

  private function buildThreadCreateDedupeKey(int $requestId): string
  {
    return 'discord:thread-create:' . $requestId;
  }

  private function buildThreadMessageDedupeKey(string $eventKey, array $payload): ?string
  {
    $requestId = (int)($payload['request_id'] ?? 0);
    if ($requestId <= 0) {
      return null;
    }
    $stateVersion = $this->resolveThreadStateVersion($eventKey, $payload);
    return 'discord:thread:' . $requestId . ':' . $eventKey . ':' . $stateVersion;
  }

  private function resolveThreadStateVersion(string $eventKey, array $payload): string
  {
    $status = (string)($payload['status'] ?? '');
    if ($eventKey === 'request.created' && $status !== '') {
      return $this->normalizeStatus($status);
    }
    if ($eventKey === 'request.status_changed' && $status !== '') {
      return $this->normalizeStatus($status);
    }
    if (str_starts_with($eventKey, 'contract.')) {
      $contractId = (int)($payload['contract_id'] ?? 0);
      if ($contractId > 0) {
        return (string)$contractId;
      }
    }
    if ($eventKey === 'alert.system') {
      $message = trim((string)($payload['message'] ?? ''));
      if ($message !== '') {
        return sha1($message);
      }
    }

    return sha1(Db::jsonEncode($payload));
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
