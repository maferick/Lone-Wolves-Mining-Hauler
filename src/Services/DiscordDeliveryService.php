<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class DiscordDeliveryService
{
  private const OPS_THREAD_EVENTS = [
    'request.created',
    'request.status_changed',
    'contract.matched',
    'contract.picked_up',
    'contract.completed',
    'contract.failed',
    'contract.expired',
    'alert.system',
  ];

  public function __construct(private Db $db, private DiscordMessageRenderer $renderer, private array $config = [])
  {
  }

  public function sendPending(int $limit = 25): array
  {
    $limit = max(1, min($limit, 100));
    $rows = $this->db->select(
      "SELECT o.outbox_id, o.corp_id, o.channel_map_id, o.event_key, o.payload_json, o.attempts,
              o.next_attempt_at, m.mode, m.channel_id, m.webhook_url, m.is_enabled
         FROM discord_outbox o
         LEFT JOIN discord_channel_map m ON m.channel_map_id = o.channel_map_id
        WHERE o.status IN ('queued','failed')
          AND (o.next_attempt_at IS NULL OR o.next_attempt_at <= UTC_TIMESTAMP())
        ORDER BY
          CASE
            WHEN o.event_key IN ('discord.bot.permissions_test', 'discord.commands.register', 'discord.members.onboard', 'discord.bot.test_message', 'discord.roles.sync_user', 'discord.onboarding.dm', 'discord.template.test', 'discord.thread.test', 'discord.thread.test_close', 'discord.thread.delete') THEN 0
            ELSE 1
          END,
          o.created_at ASC
        LIMIT {$limit}"
    );

    $results = [
      'processed' => 0,
      'sent' => 0,
      'failed' => 0,
      'pending' => 0,
    ];

    foreach ($rows as $row) {
      $results['processed']++;
      $outboxId = (int)$row['outbox_id'];
      $corpId = (int)$row['corp_id'];
      $attempt = (int)($row['attempts'] ?? 0);

      $locked = $this->db->execute(
        "UPDATE discord_outbox
            SET status = 'sending', attempts = :attempts, updated_at = UTC_TIMESTAMP()
          WHERE outbox_id = :id AND status IN ('queued','failed')",
        [
          'attempts' => $attempt,
          'id' => $outboxId,
        ]
      );
      if ($locked === 0) {
        $results['pending']++;
        continue;
      }

      $payload = Db::jsonDecode((string)$row['payload_json'], []);
      if (!is_array($payload)) {
        $payload = [];
      }

      $eventKey = (string)($row['event_key'] ?? '');
      if ($this->isAdminTask($eventKey)) {
        $resp = $this->handleAdminTask($corpId, $eventKey, $payload);
        $this->applyResult($outboxId, $attempt, $resp, $results, $corpId, true);
        continue;
      }

      if ($this->isBotTask($eventKey)) {
        $resp = $this->handleBotTask($corpId, $eventKey, $payload);
        $this->applyResult($outboxId, $attempt, $resp, $results, $corpId, true);
        continue;
      }

      if (empty($row['is_enabled'])) {
        $this->markFailed($outboxId, $attempt + 1, 'Channel mapping disabled.');
        $results['failed']++;
        continue;
      }

      $mode = (string)($row['mode'] ?? '');
      if ($mode === 'webhook') {
        $resp = $this->sendWebhookMessage($corpId, $row, $payload);
      } elseif ($mode === 'bot') {
        $resp = $this->sendBotMessage($corpId, $row, $payload);
      } else {
        $resp = [
          'ok' => false,
          'status' => 0,
          'error' => 'invalid_mode',
          'retry_after' => null,
          'body' => '',
        ];
      }

      $this->applyResult($outboxId, $attempt, $resp, $results, $corpId, $mode === 'bot');
    }

    return $results;
  }

  private function sendWebhookMessage(int $corpId, array $row, array $payload): array
  {
    $webhookUrl = trim((string)($row['webhook_url'] ?? ''));
    if ($webhookUrl === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'webhook_url_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    if (!$this->checkRateLimit($corpId, (int)($row['channel_map_id'] ?? 0))) {
      return [
        'ok' => false,
        'status' => 429,
        'error' => 'rate_limited_local',
        'retry_after' => 60,
        'body' => '',
      ];
    }

    $message = $this->renderer->render($corpId, (string)($row['event_key'] ?? ''), $payload);
    return $this->postJson($webhookUrl, $message);
  }

  private function sendBotMessage(int $corpId, array $row, array $payload): array
  {
    if (!$this->checkRateLimit($corpId, (int)($row['channel_map_id'] ?? 0))) {
      return [
        'ok' => false,
        'status' => 429,
        'error' => 'rate_limited_local',
        'retry_after' => 60,
        'body' => '',
      ];
    }

    $token = (string)($this->config['discord']['bot_token'] ?? '');
    if ($token === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'bot_token_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $eventKey = (string)($row['event_key'] ?? '');
    $configRow = $this->loadConfigRow($corpId);
    if ($this->shouldUseThreadDelivery($configRow, $eventKey, $payload)) {
      return $this->sendThreadedOpsMessage($corpId, $configRow, $eventKey, $payload, $token);
    }

    $channelId = trim((string)($row['channel_id'] ?? ''));
    if ($channelId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'channel_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $message = $this->renderer->render($corpId, $eventKey, $payload);
    $endpoint = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/')
      . '/channels/' . $channelId . '/messages';

    return $this->postJson($endpoint, $message, [
      'Authorization: Bot ' . $token,
    ]);
  }

  private function shouldUseThreadDelivery(array $configRow, string $eventKey, array $payload): bool
  {
    if (($configRow['channel_mode'] ?? 'threads') !== 'threads') {
      return false;
    }
    if (!in_array($eventKey, self::OPS_THREAD_EVENTS, true)) {
      return false;
    }
    if (empty($payload['request_id'])) {
      return false;
    }
    return trim((string)($configRow['hauling_channel_id'] ?? '')) !== '';
  }

  private function sendThreadedOpsMessage(
    int $corpId,
    array $configRow,
    string $eventKey,
    array $payload,
    string $token
  ): array {
    $isRequestCreated = $eventKey === 'request.created';
    $requestId = (int)($payload['request_id'] ?? 0);
    if ($requestId <= 0) {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'request_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $opsChannelId = trim((string)($configRow['hauling_channel_id'] ?? ''));
    if ($opsChannelId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'hauling_channel_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $threadRow = $this->loadThreadRow($corpId, $requestId);
    $threadId = $threadRow ? trim((string)($threadRow['thread_id'] ?? '')) : '';
    $anchorMessageId = $threadRow ? trim((string)($threadRow['anchor_message_id'] ?? '')) : '';
    $message = $this->renderer->render($corpId, $eventKey, $payload);

    if ($threadId !== '') {
      if ($isRequestCreated) {
        return [
          'ok' => true,
          'status' => 200,
          'error' => null,
          'retry_after' => null,
          'body' => '',
        ];
      }
      $resp = $this->postJson($base . '/channels/' . $threadId . '/messages', $message, [
        'Authorization: Bot ' . $token,
      ]);
      if (!empty($resp['ok'])) {
        $this->markThreadActivity($corpId, $requestId, $threadId, 'active');
        return $resp;
      }

      return $this->recoverThreadDelivery($corpId, $configRow, $eventKey, $payload, $token, $resp, $threadRow);
    }

    if ($anchorMessageId !== '') {
      $threadResp = $this->createThreadFromAnchor($base, $opsChannelId, $anchorMessageId, $payload, $configRow, $token);
      if (empty($threadResp['ok'])) {
        return $threadResp;
      }

      $threadId = (string)($threadResp['thread_id'] ?? '');
      if ($threadId === '') {
        return [
          'ok' => false,
          'status' => 0,
          'error' => 'thread_id_missing',
          'retry_after' => null,
          'body' => '',
        ];
      }

      $this->storeThreadRecord($corpId, $requestId, $opsChannelId, $anchorMessageId, $threadId, 'active');
      $this->addRequesterToThread($corpId, $payload, $configRow, $threadId, $token, $base);
      if ($isRequestCreated) {
        return [
          'ok' => true,
          'status' => 200,
          'error' => null,
          'retry_after' => null,
          'body' => '',
        ];
      }

      $postResp = $this->postJson($base . '/channels/' . $threadId . '/messages', $message, [
        'Authorization: Bot ' . $token,
      ]);
      if (!empty($postResp['ok'])) {
        $this->markThreadActivity($corpId, $requestId, $threadId, 'active');
      }
      return $postResp;
    }

    $claimed = $threadRow
      ? $this->markThreadCreating($corpId, $requestId)
      : $this->claimThreadCreation($corpId, $requestId, $opsChannelId);
    if (!$claimed) {
      return [
        'ok' => false,
        'status' => 409,
        'error' => 'thread_pending',
        'retry_after' => 15,
        'body' => '',
      ];
    }

    $anchor = $this->sendAnchorMessage($corpId, 'request.created', $payload, $opsChannelId, $token, $base, $configRow);
    if (empty($anchor['ok'])) {
      $this->releaseThreadClaim($corpId, $requestId);
      return $anchor;
    }

    $messageId = $anchor['message_id'] ?? '';
    $threadResp = $this->createThreadFromAnchor($base, $opsChannelId, $messageId, $payload, $configRow, $token);
    if (empty($threadResp['ok'])) {
      $this->releaseThreadClaim($corpId, $requestId);
      return $threadResp;
    }

    $newThreadId = (string)($threadResp['thread_id'] ?? '');
    if ($newThreadId === '') {
      $this->releaseThreadClaim($corpId, $requestId);
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'thread_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $this->storeThreadRecord($corpId, $requestId, $opsChannelId, $messageId, $newThreadId, 'active');
    $this->addRequesterToThread($corpId, $payload, $configRow, $newThreadId, $token, $base);

    if (!$isRequestCreated) {
      $repeatResp = $this->postJson($base . '/channels/' . $newThreadId . '/messages', $message, [
        'Authorization: Bot ' . $token,
      ]);
      if (!empty($repeatResp['ok'])) {
        $this->markThreadActivity($corpId, $requestId, $newThreadId, 'active');
      }
      return $repeatResp;
    }

    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => '',
    ];
  }

  private function recoverThreadDelivery(
    int $corpId,
    array $configRow,
    string $eventKey,
    array $payload,
    string $token,
    array $resp,
    ?array $threadRow
  ): array {
    $isRequestCreated = $eventKey === 'request.created';
    $requestId = (int)($payload['request_id'] ?? 0);
    $threadId = $threadRow ? trim((string)($threadRow['thread_id'] ?? '')) : '';
    $anchorMessageId = $threadRow ? trim((string)($threadRow['anchor_message_id'] ?? '')) : '';
    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $parsed = $this->parseDiscordError($resp);

    $shouldRecreate = false;
    if ($threadId !== '' && $this->isThreadRecoverable($parsed)) {
      $unarchive = $this->patchJson($base . '/channels/' . $threadId, [
        'archived' => false,
        'locked' => false,
      ], [
        'Authorization: Bot ' . $token,
      ]);
      if (!empty($unarchive['ok'])) {
        $message = $this->renderer->render($corpId, $eventKey, $payload);
        $retry = $this->postJson($base . '/channels/' . $threadId . '/messages', $message, [
          'Authorization: Bot ' . $token,
        ]);
        if (!empty($retry['ok'])) {
          $this->markThreadActivity($corpId, $requestId, $threadId, 'active');
          return $retry;
        }
      }
      $shouldRecreate = true;
    }

    if (!$shouldRecreate && !$this->isThreadMissing($parsed)) {
      return $resp;
    }

    if ($requestId <= 0) {
      return $resp;
    }

    $opsChannelId = trim((string)($configRow['hauling_channel_id'] ?? ''));
    if ($opsChannelId === '') {
      return $resp;
    }

    $messageId = $anchorMessageId;
    if ($messageId === '') {
      $anchor = $this->sendAnchorMessage($corpId, 'request.created', $payload, $opsChannelId, $token, $base, $configRow);
      if (empty($anchor['ok'])) {
        $this->markThreadState($corpId, $requestId, $threadId, 'missing');
        return $anchor;
      }
      $messageId = $anchor['message_id'] ?? '';
    }

    $threadResp = $this->createThreadFromAnchor($base, $opsChannelId, $messageId, $payload, $configRow, $token);
    if (empty($threadResp['ok'])) {
      $this->markThreadState($corpId, $requestId, $threadId, 'missing');
      return $threadResp;
    }

    $newThreadId = (string)($threadResp['thread_id'] ?? '');
    if ($newThreadId === '') {
      $this->markThreadState($corpId, $requestId, $threadId, 'missing');
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'thread_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $this->storeThreadRecord($corpId, $requestId, $opsChannelId, $messageId, $newThreadId, 'active');
    $this->addRequesterToThread($corpId, $payload, $configRow, $newThreadId, $token, $base);
    $this->auditThreadRecovery($corpId, $requestId, $threadId, $newThreadId);

    if ($isRequestCreated) {
      return [
        'ok' => true,
        'status' => 200,
        'error' => null,
        'retry_after' => null,
        'body' => '',
      ];
    }

    $message = $this->renderer->render($corpId, $eventKey, $payload);
    $retry = $this->postJson($base . '/channels/' . $newThreadId . '/messages', $message, [
      'Authorization: Bot ' . $token,
    ]);
    if (!empty($retry['ok'])) {
      $this->markThreadActivity($corpId, $requestId, $newThreadId, 'active');
    }
    return $retry;
  }

  private function sendAnchorMessage(
    int $corpId,
    string $eventKey,
    array $payload,
    string $opsChannelId,
    string $token,
    string $base,
    array $configRow
  ): array {
    $message = $this->renderer->render($corpId, $eventKey, $payload);
    $message = $this->applyAnchorMentions($corpId, $eventKey, $payload, $message, $configRow);

    $messageResp = $this->postJson($base . '/channels/' . $opsChannelId . '/messages', $message, [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($messageResp['ok'])) {
      return $messageResp;
    }

    $messageData = json_decode((string)($messageResp['body'] ?? ''), true);
    $messageId = is_array($messageData) ? (string)($messageData['id'] ?? '') : '';
    if ($messageId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'message_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => '',
      'message_id' => $messageId,
    ];
  }

  private function applyAnchorMentions(int $corpId, string $eventKey, array $payload, array $message, array $configRow): array
  {
    if ($eventKey !== 'request.created') {
      return $message;
    }

    $roleMap = $this->normalizeRoleMap($configRow['role_map_json'] ?? null);
    $mentionParts = [];
    if (!empty($roleMap['hauling.hauler'])) {
      $mentionParts[] = '<@&' . $roleMap['hauling.hauler'] . '>';
    }

    $requesterDiscordId = $this->resolveRequesterDiscordId($payload, $configRow);
    if ($requesterDiscordId !== '') {
      $mentionParts[] = '<@' . $requesterDiscordId . '>';
    }

    $mentionLine = trim(implode(' ', $mentionParts));
    if ($mentionLine !== '') {
      if (!empty($message['content'])) {
        $message['content'] = trim($mentionLine . ' ' . $message['content']);
      } else {
        $message['content'] = $mentionLine;
      }
      $message['allowed_mentions'] = [
        'parse' => [],
        'roles' => !empty($roleMap['hauling.hauler']) ? [$roleMap['hauling.hauler']] : [],
        'users' => $requesterDiscordId !== '' ? [$requesterDiscordId] : [],
      ];
    }

    return $message;
  }

  private function buildThreadName(array $payload): string
  {
    $requestCode = trim((string)($payload['request_code'] ?? ''));
    $threadLabel = $requestCode !== '' ? strtoupper($requestCode) : (string)($payload['request_id'] ?? '');
    $prefix = $threadLabel !== '' ? 'HAUL-' . $threadLabel : 'HAUL';
    $pickup = trim((string)($payload['pickup'] ?? ''));
    $delivery = trim((string)($payload['delivery'] ?? ''));
    $route = $pickup !== '' && $delivery !== '' ? $pickup . ' → ' . $delivery : trim((string)($payload['route'] ?? ''));
    if ($route !== '') {
      return $prefix . ' • ' . $route;
    }
    return $prefix;
  }

  private function resolveThreadAutoArchiveDuration(array $configRow): ?int
  {
    $duration = (int)($configRow['thread_auto_archive_minutes'] ?? 1440);
    if (in_array($duration, [60, 1440, 4320, 10080], true)) {
      return $duration;
    }
    return null;
  }

  private function resolveRequesterDiscordId(array $payload, array $configRow): string
  {
    if (empty($payload['requester_user_id']) || ($configRow['requester_thread_access'] ?? 'read_only') === 'none') {
      return '';
    }
    $link = $this->db->one(
      "SELECT discord_user_id FROM discord_user_link WHERE user_id = :uid LIMIT 1",
      ['uid' => (int)$payload['requester_user_id']]
    );
    return $link ? (string)($link['discord_user_id'] ?? '') : '';
  }

  private function createThreadFromAnchor(
    string $base,
    string $opsChannelId,
    string $anchorMessageId,
    array $payload,
    array $configRow,
    string $token
  ): array {
    $threadName = $this->buildThreadName($payload);
    $threadPayload = [
      'name' => $threadName,
    ];
    $autoArchive = $this->resolveThreadAutoArchiveDuration($configRow);
    if ($autoArchive !== null) {
      $threadPayload['auto_archive_duration'] = $autoArchive;
    }

    $threadResp = $this->postJson($base . '/channels/' . $opsChannelId . '/messages/' . $anchorMessageId . '/threads', $threadPayload, [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($threadResp['ok'])) {
      return $threadResp;
    }

    $threadData = json_decode((string)($threadResp['body'] ?? ''), true);
    $threadId = is_array($threadData) ? (string)($threadData['id'] ?? '') : '';
    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => $threadResp['body'] ?? '',
      'thread_id' => $threadId,
    ];
  }

  private function addRequesterToThread(
    int $corpId,
    array $payload,
    array $configRow,
    string $threadId,
    string $token,
    string $base
  ): void {
    $requesterDiscordId = $this->resolveRequesterDiscordId($payload, $configRow);
    if ($requesterDiscordId === '') {
      return;
    }

    $this->putJson($base . '/channels/' . $threadId . '/thread-members/' . $requesterDiscordId, [], [
      'Authorization: Bot ' . $token,
    ]);
  }

  private function loadThreadRow(int $corpId, int $requestId): ?array
  {
    $row = $this->db->one(
      "SELECT request_id, thread_id, ops_channel_id, anchor_message_id, thread_state
         FROM discord_thread
        WHERE request_id = :rid AND corp_id = :cid
        LIMIT 1",
      ['rid' => $requestId, 'cid' => $corpId]
    );
    return $row ?: null;
  }

  private function claimThreadCreation(int $corpId, int $requestId, string $opsChannelId): bool
  {
    $claimed = $this->db->execute(
      "INSERT IGNORE INTO discord_thread
        (request_id, corp_id, ops_channel_id, thread_state, thread_created_at, created_at, updated_at)
       VALUES
        (:rid, :cid, :ops_channel_id, 'creating', NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
      [
        'rid' => $requestId,
        'cid' => $corpId,
        'ops_channel_id' => $opsChannelId,
      ]
    );

    return $claimed > 0;
  }

  private function markThreadCreating(int $corpId, int $requestId): bool
  {
    $updated = $this->db->execute(
      "UPDATE discord_thread
          SET thread_state = 'creating',
              updated_at = UTC_TIMESTAMP()
        WHERE request_id = :rid
          AND corp_id = :cid
          AND thread_id IS NULL
          AND (thread_state IS NULL OR thread_state <> 'creating')",
      [
        'rid' => $requestId,
        'cid' => $corpId,
      ]
    );

    return $updated > 0;
  }

  private function releaseThreadClaim(int $corpId, int $requestId): void
  {
    $this->db->execute(
      "UPDATE discord_thread
          SET thread_state = 'missing',
              updated_at = UTC_TIMESTAMP()
        WHERE request_id = :rid
          AND corp_id = :cid
          AND thread_id IS NULL",
      ['rid' => $requestId, 'cid' => $corpId]
    );
  }

  private function storeThreadRecord(
    int $corpId,
    int $requestId,
    string $opsChannelId,
    string $anchorMessageId,
    string $threadId,
    string $state
  ): void {
    $this->db->execute(
      "INSERT INTO discord_thread
        (request_id, corp_id, thread_id, ops_channel_id, anchor_message_id, thread_state, thread_created_at)
       VALUES
        (:rid, :cid, :thread_id, :ops_channel_id, :anchor_message_id, :thread_state, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE
        thread_id = VALUES(thread_id),
        ops_channel_id = VALUES(ops_channel_id),
        anchor_message_id = VALUES(anchor_message_id),
        thread_state = VALUES(thread_state),
        thread_created_at = VALUES(thread_created_at),
        updated_at = UTC_TIMESTAMP()",
      [
        'rid' => $requestId,
        'cid' => $corpId,
        'thread_id' => $threadId,
        'ops_channel_id' => $opsChannelId,
        'anchor_message_id' => $anchorMessageId,
        'thread_state' => $state,
      ]
    );
  }

  private function markThreadActivity(int $corpId, int $requestId, string $threadId, string $state): void
  {
    $this->db->execute(
      "UPDATE discord_thread
          SET thread_last_posted_at = UTC_TIMESTAMP(),
              thread_state = :thread_state,
              updated_at = UTC_TIMESTAMP()
        WHERE request_id = :rid AND corp_id = :cid AND thread_id = :thread_id",
      [
        'thread_state' => $state,
        'rid' => $requestId,
        'cid' => $corpId,
        'thread_id' => $threadId,
      ]
    );
  }

  private function markThreadState(int $corpId, int $requestId, string $threadId, string $state): void
  {
    $this->db->execute(
      "UPDATE discord_thread
          SET thread_state = :thread_state,
              updated_at = UTC_TIMESTAMP()
        WHERE request_id = :rid AND corp_id = :cid AND thread_id = :thread_id",
      [
        'thread_state' => $state,
        'rid' => $requestId,
        'cid' => $corpId,
        'thread_id' => $threadId,
      ]
    );
  }

  private function buildIdempotencyKey(int $corpId, ?int $channelMapId, string $eventKey, array $payload): string
  {
    $payloadHash = sha1(Db::jsonEncode($payload));
    $mapKey = $channelMapId !== null ? (string)$channelMapId : '0';
    return 'discord:' . $corpId . ':' . $mapKey . ':event:' . $eventKey . ':' . $payloadHash;
  }

  private function parseDiscordError(array $resp): array
  {
    $body = (string)($resp['body'] ?? '');
    $decoded = json_decode($body, true);
    return [
      'status' => (int)($resp['status'] ?? 0),
      'code' => is_array($decoded) ? (int)($decoded['code'] ?? 0) : 0,
      'message' => is_array($decoded) ? (string)($decoded['message'] ?? '') : $body,
    ];
  }

  private function isThreadRecoverable(array $error): bool
  {
    $message = strtolower($error['message'] ?? '');
    $code = (int)($error['code'] ?? 0);
    return $code === 50083
      || $code === 50084
      || str_contains($message, 'thread is archived')
      || str_contains($message, 'thread is locked');
  }

  private function isThreadMissing(array $error): bool
  {
    $message = strtolower($error['message'] ?? '');
    $code = (int)($error['code'] ?? 0);
    $status = (int)($error['status'] ?? 0);
    return $code === 10003
      || str_contains($message, 'unknown channel')
      || str_contains($message, 'unknown thread')
      || $status === 404;
  }

  private function auditThreadRecovery(int $corpId, int $requestId, string $oldThreadId, string $newThreadId): void
  {
    $this->db->audit(
      $corpId,
      null,
      null,
      'discord.thread.recovered',
      'discord_thread',
      (string)$requestId,
      ['thread_id' => $oldThreadId],
      ['thread_id' => $newThreadId],
      null,
      null
    );
  }

  private function handleBotTask(int $corpId, string $eventKey, array $payload): array
  {
    $configRow = $this->loadConfigRow($corpId);
    $token = (string)($this->config['discord']['bot_token'] ?? '');
    if ($token === '' && $eventKey !== 'discord.template.test') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'bot_token_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    return match ($eventKey) {
      'discord.bot.test_message' => $this->sendBotTestMessage($configRow, $token),
      'discord.template.test' => $this->sendTemplateTestMessage($corpId, $configRow, $payload, $token),
      'discord.roles.sync_user' => $this->syncUserRoles($corpId, $configRow, $payload, $token),
      'discord.onboarding.dm' => $this->sendOnboardingDm($corpId, $configRow, $payload, $token),
      'discord.thread.create' => $this->createThreadForRequest($corpId, $configRow, $payload, $token),
      'discord.thread.complete' => $this->completeThreadForRequest($corpId, $configRow, $payload, $token),
      'discord.thread.test' => $this->createTestThread($corpId, $configRow, $payload, $token),
      'discord.thread.test_close' => $this->closeTestThread($corpId, $payload, $token),
      'discord.thread.delete' => $this->deleteThreadForRequest($corpId, $payload, $token),
      default => [
        'ok' => false,
        'status' => 0,
        'error' => 'unknown_bot_task',
        'retry_after' => null,
        'body' => '',
      ],
    };
  }

  private function handleAdminTask(int $corpId, string $eventKey, array $payload): array
  {
    $token = (string)($this->config['discord']['bot_token'] ?? '');
    $appId = trim((string)($payload['application_id'] ?? $this->config['discord']['application_id'] ?? ''));
    $guildId = trim((string)($payload['guild_id'] ?? $this->config['discord']['guild_id'] ?? ''));
    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');

    if ($eventKey === 'discord.members.onboard') {
      if ($token === '') {
        return [
          'ok' => false,
          'status' => 0,
          'error' => 'bot_token_missing',
          'retry_after' => null,
          'body' => '',
        ];
      }
      if ($guildId === '') {
        return [
          'ok' => false,
          'status' => 0,
          'error' => 'guild_id_missing',
          'retry_after' => null,
          'body' => '',
        ];
      }
      return $this->queueOnboardingDms($corpId, $guildId, $token, $base);
    }

    if ($token === '' || $appId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'discord_credentials_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    if ($eventKey === 'discord.bot.permissions_test') {
      return $this->runBotPermissionsTest($corpId, $token, $guildId, $base);
    }

    if ($eventKey === 'discord.commands.register') {
      $commands = $this->buildCommandDefinitions();
      $endpoint = $guildId !== ''
        ? $base . '/applications/' . $appId . '/guilds/' . $guildId . '/commands'
        : $base . '/applications/' . $appId . '/commands';

      return $this->putJson($endpoint, $commands, [
        'Authorization: Bot ' . $token,
      ]);
    }

    return [
      'ok' => false,
      'status' => 0,
      'error' => 'unknown_admin_task',
      'retry_after' => null,
      'body' => '',
    ];
  }

  private function sendBotTestMessage(array $configRow, string $token): array
  {
    $channelId = trim((string)($configRow['hauling_channel_id'] ?? ''));
    if ($channelId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'hauling_channel_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $endpoint = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/')
      . '/channels/' . $channelId . '/messages';
    $payload = [
      'content' => '✅ Discord bot test message from the hauling portal.',
    ];

    return $this->postJson($endpoint, $payload, [
      'Authorization: Bot ' . $token,
    ]);
  }

  private function sendTemplateTestMessage(int $corpId, array $configRow, array $payload, string $token): array
  {
    $templateEventKey = trim((string)($payload['template_event_key'] ?? ''));
    if ($templateEventKey === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'template_event_key_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $templatePayload = $payload['template_payload'] ?? [];
    if (!is_array($templatePayload)) {
      $templatePayload = [];
    }

    $delivery = strtolower(trim((string)($payload['delivery'] ?? 'bot')));
    $channelId = trim((string)($payload['channel_id'] ?? $configRow['hauling_channel_id'] ?? ''));
    $webhookUrl = trim((string)($payload['webhook_url'] ?? ''));
    $provider = strtolower(trim((string)($payload['webhook_provider'] ?? 'discord')));

    $message = $this->renderer->render($corpId, $templateEventKey, $templatePayload);

    if ($delivery === 'bot') {
      if ($token === '') {
        return [
          'ok' => false,
          'status' => 0,
          'error' => 'bot_token_missing',
          'retry_after' => null,
          'body' => '',
        ];
      }
      if ($channelId === '') {
        return [
          'ok' => false,
          'status' => 0,
          'error' => 'channel_id_missing',
          'retry_after' => null,
          'body' => '',
        ];
      }
      $endpoint = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/')
        . '/channels/' . $channelId . '/messages';
      return $this->postJson($endpoint, $message, [
        'Authorization: Bot ' . $token,
      ]);
    }

    if ($webhookUrl === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'webhook_url_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    if ($delivery === 'slack' || $provider === 'slack') {
      return $this->postJson($webhookUrl, $this->buildSlackTemplatePayload($message));
    }

    return $this->postJson($webhookUrl, $message);
  }

  private function syncUserRoles(int $corpId, array $configRow, array $payload, string $token): array
  {
    $guildId = trim((string)($configRow['guild_id'] ?? $this->config['discord']['guild_id'] ?? ''));
    if ($guildId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'guild_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }
    $discordUserId = trim((string)($payload['discord_user_id'] ?? ''));
    $userId = (int)($payload['user_id'] ?? 0);
    if ($discordUserId === '' || $userId <= 0) {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'user_reference_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $roleMap = $this->normalizeRoleMap($configRow['role_map_json'] ?? null);
    if ($roleMap === []) {
      return [
        'ok' => true,
        'status' => 200,
        'error' => null,
        'retry_after' => null,
        'body' => '',
      ];
    }

    $managedRoles = array_values($roleMap);
    $action = (string)($payload['action'] ?? 'sync');
    $rightsSource = (string)($configRow['rights_source'] ?? 'portal');
    if ($rightsSource === 'discord') {
      if ($action === 'unlink') {
        $this->removePortalHaulerRole($corpId, $userId);
        return [
          'ok' => true,
          'status' => 200,
          'error' => null,
          'retry_after' => null,
          'body' => '',
        ];
      }
      return $this->syncPortalHaulerFromDiscord($corpId, $userId, $discordUserId, $configRow);
    }
    $desiredRoles = [];
    if ($action !== 'unlink') {
      $permRows = $this->db->select(
        "SELECT DISTINCT p.perm_key
           FROM user_role ur
           JOIN role r ON r.role_id = ur.role_id
           JOIN role_permission rp ON rp.role_id = ur.role_id AND rp.allow = 1
           JOIN permission p ON p.perm_id = rp.perm_id
          WHERE ur.user_id = :uid
            AND r.role_key <> 'admin'",
        ['uid' => $userId]
      );
      $permKeys = array_map(static fn($row) => (string)($row['perm_key'] ?? ''), $permRows);
      foreach ($roleMap as $permKey => $roleId) {
        if (in_array($permKey, $permKeys, true)) {
          $desiredRoles[] = $roleId;
        }
      }
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $memberResp = $this->getJson($base . '/guilds/' . $guildId . '/members/' . $discordUserId, [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($memberResp['ok'])) {
      return $memberResp;
    }

    $member = json_decode((string)($memberResp['body'] ?? ''), true);
    $currentRoles = is_array($member) ? ($member['roles'] ?? []) : [];
    if (!is_array($currentRoles)) {
      $currentRoles = [];
    }

    $toAdd = array_values(array_diff($desiredRoles, $currentRoles));
    $managedCurrent = array_values(array_intersect($currentRoles, $managedRoles));
    $toRemove = array_values(array_diff($managedCurrent, $desiredRoles));

    foreach ($toAdd as $roleId) {
      $resp = $this->putJson($base . '/guilds/' . $guildId . '/members/' . $discordUserId . '/roles/' . $roleId, [], [
        'Authorization: Bot ' . $token,
      ]);
      if (empty($resp['ok'])) {
        return $resp;
      }
    }

    foreach ($toRemove as $roleId) {
      $resp = $this->deleteJson($base . '/guilds/' . $guildId . '/members/' . $discordUserId . '/roles/' . $roleId, [
        'Authorization: Bot ' . $token,
      ]);
      if (empty($resp['ok'])) {
        return $resp;
      }
    }

    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => '',
    ];
  }

  private function sendOnboardingDm(int $corpId, array $configRow, array $payload, string $token): array
  {
    $discordUserId = trim((string)($payload['discord_user_id'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));
    if ($discordUserId === '' || $message === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'onboarding_payload_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $bypassList = $this->normalizeOnboardingBypass($configRow['onboarding_dm_bypass_json'] ?? null);
    if (empty($configRow['onboarding_dm_enabled']) && !in_array($discordUserId, $bypassList, true)) {
      return [
        'ok' => true,
        'status' => 200,
        'error' => null,
        'retry_after' => null,
        'body' => 'onboarding_dm_disabled',
      ];
    }

    if (!$this->checkRateLimit($corpId, null)) {
      return [
        'ok' => false,
        'status' => 429,
        'error' => 'rate_limited_local',
        'retry_after' => 60,
        'body' => '',
      ];
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $channelResp = $this->postJson($base . '/users/@me/channels', [
      'recipient_id' => $discordUserId,
    ], [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($channelResp['ok'])) {
      return $channelResp;
    }

    $channel = json_decode((string)($channelResp['body'] ?? ''), true);
    $channelId = is_array($channel) ? trim((string)($channel['id'] ?? '')) : '';
    if ($channelId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'dm_channel_missing',
        'retry_after' => null,
        'body' => (string)($channelResp['body'] ?? ''),
      ];
    }

    return $this->postJson($base . '/channels/' . $channelId . '/messages', [
      'content' => $message,
    ], [
      'Authorization: Bot ' . $token,
    ]);
  }

  private function queueOnboardingDms(int $corpId, string $guildId, string $token, string $base): array
  {
    $configRow = $this->loadConfigRow($corpId);
    $rightsSource = (string)($configRow['rights_source'] ?? 'portal');
    $roleMap = $this->normalizeRoleMap($configRow['role_map_json'] ?? null);
    $onboardingEnabled = !empty($configRow['onboarding_dm_enabled']);
    $bypassList = $this->normalizeOnboardingBypass($configRow['onboarding_dm_bypass_json'] ?? null);
    $bypassLookup = $bypassList !== [] ? array_fill_keys($bypassList, true) : [];
    $targetRoleId = (string)($roleMap['hauling.member'] ?? '');
    if ($targetRoleId === '') {
      return [
        'ok' => true,
        'status' => 200,
        'error' => null,
        'retry_after' => null,
        'body' => Db::jsonEncode(['queued' => 0, 'scanned' => 0]),
      ];
    }
    $targetRoles = [$targetRoleId];

    $linkedIds = $this->fetchLinkedDiscordUserIds($corpId);
    $linkedLookup = array_fill_keys($linkedIds, true);
    $message = $this->buildOnboardingMessage();

    $after = '';
    $limit = 1000;
    $queued = 0;
    $scanned = 0;

    do {
      $endpoint = $base . '/guilds/' . $guildId . '/members?limit=' . $limit;
      if ($after !== '') {
        $endpoint .= '&after=' . urlencode($after);
      }

      $resp = $this->getJson($endpoint, [
        'Authorization: Bot ' . $token,
      ]);
      if (empty($resp['ok'])) {
        return $resp;
      }

      $members = json_decode((string)($resp['body'] ?? ''), true);
      if (!is_array($members)) {
        $members = [];
      }

      foreach ($members as $member) {
        if (!is_array($member)) {
          continue;
        }
        $user = is_array($member['user'] ?? null) ? $member['user'] : [];
        $discordUserId = trim((string)($user['id'] ?? ''));
        if ($discordUserId === '') {
          continue;
        }
        $after = $discordUserId;
        $scanned += 1;

        if (!empty($user['bot'])) {
          continue;
        }
        if (isset($linkedLookup[$discordUserId])) {
          continue;
        }
        if (!$onboardingEnabled && !isset($bypassLookup[$discordUserId])) {
          continue;
        }
        if ($targetRoles !== []) {
          $roles = $member['roles'] ?? [];
          if (!is_array($roles) || array_intersect($targetRoles, $roles) === []) {
            if ($rightsSource !== 'discord' || $roles !== []) {
              continue;
            }
          }
        }

        $queued += $this->enqueueOnboardingDm($corpId, $discordUserId, $message);
      }
    } while (count($members) === $limit);

    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => Db::jsonEncode(['queued' => $queued, 'scanned' => $scanned]),
    ];
  }

  private function enqueueOnboardingDm(int $corpId, string $discordUserId, string $message): int
  {
    $payload = [
      'discord_user_id' => $discordUserId,
      'message' => $message,
    ];
    $messageHash = md5($message);
    $idempotencyKey = 'discord:onboarding:dm:' . $corpId . ':' . $discordUserId . ':' . $messageHash;

    return $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, NULL, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.onboarding.dm',
        'payload_json' => Db::jsonEncode($payload),
        'dedupe_key' => 'discord:onboarding:dm:' . $corpId . ':' . $discordUserId,
        'idempotency_key' => $idempotencyKey,
      ]
    );
  }

  private function buildOnboardingMessage(): string
  {
    $baseUrl = trim((string)($this->config['app']['base_url'] ?? ''));
    $portalLink = '';
    if ($baseUrl !== '') {
      $parts = parse_url($baseUrl);
      if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
        $portalLink = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
          $portalLink .= ':' . $parts['port'];
        }
        $portalLink .= '/';
      } else {
        $portalLink = rtrim($baseUrl, '/') . '/';
      }
    }
    if ($portalLink === '') {
      $portalLink = 'https://lonewolves.online/';
    }
    $lines = [
      '🔒 Identity verification required',
      '',
      'Capsuleer, your Discord identity is not yet linked to the Lone Wolves Mining logistics network.',
      'To proceed, you must establish a secure link between your Discord account and your hauling profile.',
      '',
      '🧭 How to link your account',
      '',
      'Open the Lone Wolves Mining portal:',
      '👉 https://lonewolves.online/',
      '',
      'Log in and navigate to My Contracts',
      'Generate a Discord link code',
      'Return here and run the following command in this server:',
      '',
      '/link <your-code>',
      '',
      'Once completed, your identity will be fully synchronized.',
      '',
      '📦 Why this matters',
      '',
      'Linking your account allows:',
      'Automatic association of hauling requests and contracts',
      'Accurate dispatch notifications',
      'Proper access control based on corp roles',
      '',
      'No link means no clearance.',
      '',
      'Lone Wolves Mining',
      'Logistics Command · Identity Verification',
    ];

    return implode("\n", $lines);
  }

  private function fetchLinkedDiscordUserIds(int $corpId): array
  {
    $rows = $this->db->select(
      "SELECT l.discord_user_id
         FROM discord_user_link l
         JOIN app_user u ON u.user_id = l.user_id
        WHERE u.corp_id = :cid",
      ['cid' => $corpId]
    );
    $ids = [];
    foreach ($rows as $row) {
      $id = trim((string)($row['discord_user_id'] ?? ''));
      if ($id !== '') {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  public function syncPortalHaulerFromDiscord(int $corpId, int $userId, string $discordUserId, array $configRow): array
  {
    $guildId = trim((string)($configRow['guild_id'] ?? $this->config['discord']['guild_id'] ?? ''));
    if ($guildId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'guild_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }
    $token = (string)($this->config['discord']['bot_token'] ?? '');
    if ($token === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'bot_token_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }
    if ($discordUserId === '' || $userId <= 0) {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'user_reference_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $roleMap = $this->normalizeRoleMap($configRow['role_map_json'] ?? null);
    $haulerRoleId = (string)($roleMap['hauling.hauler'] ?? '');
    if ($haulerRoleId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'hauler_role_mapping_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $memberResp = $this->getJson($base . '/guilds/' . $guildId . '/members/' . $discordUserId, [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($memberResp['ok'])) {
      return $memberResp;
    }

    $member = json_decode((string)($memberResp['body'] ?? ''), true);
    $currentRoles = is_array($member) ? ($member['roles'] ?? []) : [];
    if (!is_array($currentRoles)) {
      $currentRoles = [];
    }

    $hasHaulerRole = in_array($haulerRoleId, $currentRoles, true);
    $portalHaulerRoleId = (int)$this->db->fetchValue(
      "SELECT role_id FROM role WHERE corp_id = :cid AND role_key = 'hauler' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($portalHaulerRoleId <= 0) {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'portal_hauler_role_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    if ($hasHaulerRole) {
      $this->db->execute(
        "INSERT IGNORE INTO user_role (user_id, role_id) VALUES (:uid, :rid)",
        ['uid' => $userId, 'rid' => $portalHaulerRoleId]
      );
    } else {
      $this->db->execute(
        "DELETE FROM user_role WHERE user_id = :uid AND role_id = :rid",
        ['uid' => $userId, 'rid' => $portalHaulerRoleId]
      );
    }

    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => '',
    ];
  }

  private function createThreadForRequest(int $corpId, array $configRow, array $payload, string $token): array
  {
    if (($configRow['channel_mode'] ?? 'threads') !== 'threads') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'channel_mode_not_threads',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $channelId = trim((string)($configRow['hauling_channel_id'] ?? ''));
    if ($channelId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'hauling_channel_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $requestId = (int)($payload['request_id'] ?? 0);
    $threadName = $this->buildThreadName($payload);
    if ($requestId > 0) {
      $existing = $this->db->one(
        "SELECT thread_id FROM discord_thread WHERE request_id = :rid AND corp_id = :cid LIMIT 1",
        ['rid' => $requestId, 'cid' => $corpId]
      );
      if ($existing && !empty($existing['thread_id'])) {
        return [
          'ok' => true,
          'status' => 200,
          'error' => null,
          'retry_after' => null,
          'body' => '',
        ];
      }
    }

    $message = $this->renderer->render($corpId, 'request.created', $payload);
    $message = $this->applyAnchorMentions($corpId, 'request.created', $payload, $message, $configRow);

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $messageResp = $this->postJson($base . '/channels/' . $channelId . '/messages', $message, [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($messageResp['ok'])) {
      return $messageResp;
    }

    $messageData = json_decode((string)($messageResp['body'] ?? ''), true);
    $messageId = is_array($messageData) ? (string)($messageData['id'] ?? '') : '';
    if ($messageId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'message_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $threadPayload = [
      'name' => $threadName,
    ];
    $autoArchive = $this->resolveThreadAutoArchiveDuration($configRow);
    if ($autoArchive !== null) {
      $threadPayload['auto_archive_duration'] = $autoArchive;
    }
    $threadResp = $this->postJson($base . '/channels/' . $channelId . '/messages/' . $messageId . '/threads', $threadPayload, [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($threadResp['ok'])) {
      return $threadResp;
    }

    $threadData = json_decode((string)($threadResp['body'] ?? ''), true);
    $threadId = is_array($threadData) ? (string)($threadData['id'] ?? '') : '';
    if ($threadId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'thread_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $this->storeThreadRecord($corpId, $requestId, $channelId, $messageId, $threadId, 'active');
    $this->addRequesterToThread($corpId, $payload, $configRow, $threadId, $token, $base);
    if (!empty($configRow['auto_thread_create_on_request'])) {
      $threadMessage = $this->renderer->render($corpId, 'request.created', $payload);
      $repeatResp = $this->postJson($base . '/channels/' . $threadId . '/messages', $threadMessage, [
        'Authorization: Bot ' . $token,
      ]);
      if (!empty($repeatResp['ok'])) {
        $this->markThreadActivity($corpId, $requestId, $threadId, 'active');
      }
    }

    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => '',
    ];
  }

  private function createTestThread(int $corpId, array $configRow, array $payload, string $token): array
  {
    if (($configRow['channel_mode'] ?? 'threads') !== 'threads') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'channel_mode_not_threads',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $channelId = trim((string)($configRow['hauling_channel_id'] ?? ''));
    if ($channelId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'hauling_channel_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $durationMinutes = (int)($payload['duration_minutes'] ?? 1);
    if (!in_array($durationMinutes, [1, 2], true)) {
      $durationMinutes = 1;
    }
    $threadName = 'TEST THREAD • ' . gmdate('H:i:s');

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $messageResp = $this->postJson($base . '/channels/' . $channelId . '/messages', [
      'content' => '🧪 Starting a test thread. It will close automatically in ' . $durationMinutes . ' minute' . ($durationMinutes === 1 ? '' : 's') . '.',
    ], [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($messageResp['ok'])) {
      return $messageResp;
    }

    $messageData = json_decode((string)($messageResp['body'] ?? ''), true);
    $messageId = is_array($messageData) ? (string)($messageData['id'] ?? '') : '';
    if ($messageId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'message_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $threadResp = $this->postJson($base . '/channels/' . $channelId . '/messages/' . $messageId . '/threads', [
      'name' => $threadName,
    ], [
      'Authorization: Bot ' . $token,
    ]);
    if (empty($threadResp['ok'])) {
      return $threadResp;
    }

    $threadData = json_decode((string)($threadResp['body'] ?? ''), true);
    $threadId = is_array($threadData) ? (string)($threadData['id'] ?? '') : '';
    if ($threadId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'thread_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $this->postJson($base . '/channels/' . $threadId . '/messages', [
      'content' => '✅ Test thread created. This thread will close in ' . $durationMinutes . ' minute' . ($durationMinutes === 1 ? '' : 's') . '.',
    ], [
      'Authorization: Bot ' . $token,
    ]);

    $payload = [
      'thread_id' => $threadId,
    ];
    $idempotencyKey = $this->buildIdempotencyKey($corpId, null, 'discord.thread.test_close', $payload);

    $this->db->execute(
      "INSERT IGNORE INTO discord_outbox
        (corp_id, channel_map_id, event_key, payload_json, status, attempts, next_attempt_at, dedupe_key, idempotency_key)
       VALUES
        (:corp_id, :channel_map_id, :event_key, :payload_json, 'queued', 0, :next_attempt_at, :dedupe_key, :idempotency_key)",
      [
        'corp_id' => $corpId,
        'channel_map_id' => null,
        'event_key' => 'discord.thread.test_close',
        'payload_json' => Db::jsonEncode($payload),
        'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + ($durationMinutes * 60)),
        'dedupe_key' => null,
        'idempotency_key' => $idempotencyKey,
      ]
    );

    return [
      'ok' => true,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => '',
    ];
  }

  private function closeTestThread(int $corpId, array $payload, string $token): array
  {
    $threadId = trim((string)($payload['thread_id'] ?? ''));
    if ($threadId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'thread_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $resp = $this->patchJson($base . '/channels/' . $threadId, [
      'archived' => true,
      'locked' => true,
    ], [
      'Authorization: Bot ' . $token,
    ]);
    if (!empty($resp['ok'])) {
      $this->db->execute(
        "UPDATE discord_thread
            SET thread_state = 'archived',
                updated_at = UTC_TIMESTAMP()
          WHERE thread_id = :thread_id AND corp_id = :cid",
        [
          'thread_id' => $threadId,
          'cid' => $corpId,
        ]
      );
    }
    return $resp;
  }

  private function deleteThreadForRequest(int $corpId, array $payload, string $token): array
  {
    $threadId = trim((string)($payload['thread_id'] ?? ''));
    $opsChannelId = trim((string)($payload['ops_channel_id'] ?? ''));
    $anchorMessageId = trim((string)($payload['anchor_message_id'] ?? ''));
    if ($threadId === '' && $anchorMessageId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'thread_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }
    if ($anchorMessageId !== '' && $opsChannelId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'ops_channel_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $headers = ['Authorization: Bot ' . $token];
    $threadOk = true;
    $messageOk = true;

    if ($threadId !== '') {
      $threadResp = $this->deleteJson($base . '/channels/' . $threadId, $headers);
      $threadOk = !empty($threadResp['ok']) || (int)($threadResp['status'] ?? 0) === 404;
      if (!$threadOk) {
        return $threadResp;
      }
    }

    if ($anchorMessageId !== '') {
      $messageResp = $this->deleteJson($base . '/channels/' . $opsChannelId . '/messages/' . $anchorMessageId, $headers);
      $messageOk = !empty($messageResp['ok']) || (int)($messageResp['status'] ?? 0) === 404;
      if (!$messageOk) {
        return $messageResp;
      }
    }

    return [
      'ok' => $threadOk && $messageOk,
      'status' => 200,
      'error' => null,
      'retry_after' => null,
      'body' => '',
    ];
  }

  private function completeThreadForRequest(int $corpId, array $configRow, array $payload, string $token): array
  {
    $threadId = trim((string)($payload['thread_id'] ?? ''));
    if ($threadId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'thread_id_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $patch = [];
    if (!empty($configRow['auto_archive_on_complete'])) {
      $patch['archived'] = true;
    }
    if (!empty($configRow['auto_lock_on_complete'])) {
      $patch['locked'] = true;
    }
    if ($patch === []) {
      return [
        'ok' => true,
        'status' => 200,
        'error' => null,
        'retry_after' => null,
        'body' => '',
      ];
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');
    $resp = $this->patchJson($base . '/channels/' . $threadId, $patch, [
      'Authorization: Bot ' . $token,
    ]);
    if (!empty($resp['ok'])) {
      $this->db->execute(
        "UPDATE discord_thread
            SET thread_state = 'archived',
                updated_at = UTC_TIMESTAMP()
          WHERE thread_id = :thread_id AND corp_id = :cid",
        [
          'thread_id' => $threadId,
          'cid' => $corpId,
        ]
      );
    }
    return $resp;
  }

  private function applyResult(int $outboxId, int $attempt, array $resp, array &$results, int $corpId, bool $isBotAction): void
  {
    if (!empty($resp['ok'])) {
      $this->db->execute(
        "UPDATE discord_outbox
            SET status = 'sent',
                attempts = :attempts,
                sent_at = UTC_TIMESTAMP(),
                last_error = NULL,
                next_attempt_at = NULL
          WHERE outbox_id = :id",
        [
          'attempts' => $attempt + 1,
          'id' => $outboxId,
        ]
      );
      if ($isBotAction) {
        $this->touchBotAction($corpId);
      }
      $results['sent']++;
      return;
    }

    $attemptNext = $attempt + 1;
    $retryAfter = isset($resp['retry_after']) ? (int)$resp['retry_after'] : null;
    $backoffSeconds = $retryAfter !== null && $retryAfter > 0
      ? $retryAfter
      : min(3600, (int)(pow(2, $attemptNext) * 30));
    $nextAttempt = gmdate('Y-m-d H:i:s', time() + $backoffSeconds);
    $status = $attemptNext >= 10 ? 'failed' : 'queued';
    $errorText = trim((string)($resp['error'] ?? 'HTTP error'));
    $body = $this->snippet((string)($resp['body'] ?? ''));
    if ($body !== '') {
      $errorText .= ' | Response: ' . $body;
    }

    $this->db->execute(
      "UPDATE discord_outbox
          SET status = :status,
              attempts = :attempts,
              next_attempt_at = :next_attempt_at,
              last_error = :last_error
        WHERE outbox_id = :id",
      [
        'status' => $status,
        'attempts' => $attemptNext,
        'next_attempt_at' => $status === 'failed' ? null : $nextAttempt,
        'last_error' => $errorText,
        'id' => $outboxId,
      ]
    );

    if ($status === 'failed') {
      $results['failed']++;
    } else {
      $results['pending']++;
    }
  }

  private function markFailed(int $outboxId, int $attempt, string $error): void
  {
    $this->db->execute(
      "UPDATE discord_outbox
          SET status = 'failed',
              attempts = :attempts,
              next_attempt_at = NULL,
              last_error = :last_error
        WHERE outbox_id = :id",
      [
        'attempts' => $attempt,
        'last_error' => $error,
        'id' => $outboxId,
      ]
    );
  }

  private function checkRateLimit(int $corpId, ?int $channelMapId): bool
  {
    $channelMapId = $channelMapId ?? 0;
    $row = $this->db->one(
      "SELECT rate_limit_per_minute
         FROM discord_config
        WHERE corp_id = :cid
        LIMIT 1",
      ['cid' => $corpId]
    );
    $limit = (int)($row['rate_limit_per_minute'] ?? 0);
    if ($limit <= 0) {
      return true;
    }
    if ($channelMapId > 0) {
      $count = (int)($this->db->fetchValue(
        "SELECT COUNT(*)
           FROM discord_outbox
          WHERE channel_map_id = :channel_map_id
            AND status = 'sent'
            AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)",
        ['channel_map_id' => $channelMapId]
      ) ?? 0);
      return $count < $limit;
    }

    $count = (int)($this->db->fetchValue(
      "SELECT COUNT(*)
         FROM discord_outbox
        WHERE corp_id = :cid
          AND status = 'sent'
          AND sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)",
      ['cid' => $corpId]
    ) ?? 0);
    return $count < $limit;
  }

  private function removePortalHaulerRole(int $corpId, int $userId): void
  {
    $portalHaulerRoleId = (int)$this->db->fetchValue(
      "SELECT role_id FROM role WHERE corp_id = :cid AND role_key = 'hauler' LIMIT 1",
      ['cid' => $corpId]
    );
    if ($portalHaulerRoleId <= 0) {
      return;
    }

    $this->db->execute(
      "DELETE FROM user_role WHERE user_id = :uid AND role_id = :rid",
      ['uid' => $userId, 'rid' => $portalHaulerRoleId]
    );
  }

  private function postJson(string $url, array $payload, array $headers = []): array
  {
    return $this->sendJson('POST', $url, $payload, $headers);
  }

  private function putJson(string $url, array $payload, array $headers = []): array
  {
    return $this->sendJson('PUT', $url, $payload, $headers);
  }

  private function patchJson(string $url, array $payload, array $headers = []): array
  {
    return $this->sendJson('PATCH', $url, $payload, $headers);
  }

  private function getJson(string $url, array $headers = []): array
  {
    return $this->sendJson('GET', $url, null, $headers);
  }

  private function deleteJson(string $url, array $headers = []): array
  {
    return $this->sendJson('DELETE', $url, null, $headers);
  }

  private function sendJson(string $method, string $url, ?array $payload, array $headers = []): array
  {
    $bodyJson = $payload !== null ? Db::jsonEncode($payload) : '';
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($payload !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $header) use (&$respHeaders) {
      $len = strlen($header);
      $parts = explode(':', $header, 2);
      if (count($parts) === 2) {
        $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
      }
      return $len;
    });

    $headers[] = 'Content-Type: application/json';
    $headers[] = 'User-Agent: ' . ($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = (string)curl_exec($ch);
    $errno = curl_errno($ch);
    $error = $errno ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $retryAfter = null;
    if (isset($respHeaders['retry-after'])) {
      $retryAfter = (int)$respHeaders['retry-after'];
    } elseif (isset($respHeaders['x-ratelimit-reset-after'])) {
      $retryAfter = (int)ceil((float)$respHeaders['x-ratelimit-reset-after']);
    }

    if ($errno || $status < 200 || $status >= 300) {
      return [
        'ok' => false,
        'status' => $status,
        'error' => $error ?: "HTTP {$status}",
        'retry_after' => $status === 429 ? ($retryAfter ?? 60) : null,
        'body' => $body,
      ];
    }

    return [
      'ok' => true,
      'status' => $status,
      'error' => null,
      'retry_after' => null,
      'body' => $body,
    ];
  }

  private function snippet(string $body, int $max = 400): string
  {
    $body = trim($body);
    if ($body === '') {
      return '';
    }
    if (strlen($body) <= $max) {
      return $body;
    }
    return substr($body, 0, $max) . '…';
  }

  private function isAdminTask(string $eventKey): bool
  {
    return in_array($eventKey, ['discord.commands.register', 'discord.bot.permissions_test', 'discord.members.onboard'], true);
  }

  private function isBotTask(string $eventKey): bool
  {
    return in_array($eventKey, [
      'discord.bot.test_message',
      'discord.onboarding.dm',
      'discord.roles.sync_user',
      'discord.thread.create',
      'discord.thread.complete',
      'discord.thread.delete',
      'discord.template.test',
      'discord.thread.test',
      'discord.thread.test_close',
    ], true);
  }

  private function buildSlackTemplatePayload(array $message): array
  {
    $lines = [];
    $content = trim((string)($message['content'] ?? ''));
    if ($content !== '') {
      $lines[] = $content;
    }

    $embed = [];
    if (!empty($message['embeds']) && is_array($message['embeds'])) {
      $embed = $message['embeds'][0] ?? [];
      if (!is_array($embed)) {
        $embed = [];
      }
    }

    $title = trim((string)($embed['title'] ?? ''));
    if ($title !== '') {
      $lines[] = '*' . $title . '*';
    }
    $description = trim((string)($embed['description'] ?? ''));
    if ($description !== '') {
      $lines[] = $description;
    }
    $footer = '';
    if (!empty($embed['footer']) && is_array($embed['footer'])) {
      $footer = trim((string)($embed['footer']['text'] ?? ''));
    }
    if ($footer !== '') {
      $lines[] = '_' . $footer . '_';
    }

    $text = trim(implode("\n", $lines));
    if ($text === '') {
      $text = 'Template test message.';
    }

    return ['text' => $text];
  }

  private function runBotPermissionsTest(int $corpId, string $token, string $guildId, string $base): array
  {
    $headers = ['Authorization: Bot ' . $token];
    $checks = [];
    $overallOk = true;
    $firstFailure = null;

    $tokenResp = $this->getJson($base . '/users/@me', $headers);
    $tokenCheck = $this->buildPermissionCheck(
      'token',
      'Token valid (GET /users/@me)',
      $tokenResp['ok'] ?? false,
      (int)($tokenResp['status'] ?? 0),
      $this->responseMessage($tokenResp)
    );
    $checks[] = $tokenCheck;
    if (!$tokenCheck['ok']) {
      $overallOk = false;
      $firstFailure = $firstFailure ?? $tokenCheck;
    }

    if ($guildId === '') {
      $guildCheck = $this->buildPermissionCheck(
        'guild_id',
        'Guild ID configured',
        false,
        0,
        'Guild ID is not configured.'
      );
      $checks[] = $guildCheck;
      $overallOk = false;
      $firstFailure = $firstFailure ?? $guildCheck;
      $this->storePermissionTestResult($corpId, $checks, $overallOk);
      return $this->permissionTestResponse($overallOk, $firstFailure, $checks);
    }

    $guildResp = $this->getJson($base . '/guilds/' . $guildId, $headers);
    $guildCheck = $this->buildPermissionCheck(
      'guild_access',
      'Guild access (GET /guilds/{guild_id})',
      $guildResp['ok'] ?? false,
      (int)($guildResp['status'] ?? 0),
      $this->responseMessage($guildResp)
    );
    $checks[] = $guildCheck;
    if (!$guildCheck['ok']) {
      $overallOk = false;
      $firstFailure = $firstFailure ?? $guildCheck;
    }

    $rolesResp = $this->getJson($base . '/guilds/' . $guildId . '/roles', $headers);
    $rolesCheck = $this->buildPermissionCheck(
      'role_list',
      'Read roles (GET /guilds/{guild_id}/roles)',
      $rolesResp['ok'] ?? false,
      (int)($rolesResp['status'] ?? 0),
      $this->responseMessage($rolesResp)
    );
    $checks[] = $rolesCheck;
    if (!$rolesCheck['ok']) {
      $overallOk = false;
      $firstFailure = $firstFailure ?? $rolesCheck;
    }

    $botId = '';
    if (!empty($tokenResp['ok'])) {
      $tokenBody = json_decode((string)($tokenResp['body'] ?? ''), true);
      if (is_array($tokenBody)) {
        $botId = (string)($tokenBody['id'] ?? '');
      }
    }
    $memberEndpoint = $botId !== ''
      ? $base . '/guilds/' . $guildId . '/members/' . $botId
      : $base . '/guilds/' . $guildId . '/members/@me';
    $memberResp = $this->getJson($memberEndpoint, $headers);
    $memberCheck = $this->buildPermissionCheck(
      'bot_member',
      'Bot membership (GET /guilds/{guild_id}/members/@me)',
      $memberResp['ok'] ?? false,
      (int)($memberResp['status'] ?? 0),
      $this->responseMessage($memberResp)
    );
    $memberCheck['required'] = false;
    $checks[] = $memberCheck;

    $roleEval = $this->evaluateRoleHierarchy($corpId, $rolesResp, $memberResp);
    if ($roleEval !== null) {
      $roleEval['required'] = false;
      $checks[] = $roleEval;
    }

    $this->storePermissionTestResult($corpId, $checks, $overallOk);
    return $this->permissionTestResponse($overallOk, $firstFailure, $checks);
  }

  private function buildPermissionCheck(
    string $id,
    string $label,
    bool $ok,
    int $status,
    string $message
  ): array {
    return [
      'id' => $id,
      'label' => $label,
      'ok' => $ok,
      'status' => $status,
      'message' => $message,
      'required' => true,
    ];
  }

  private function permissionTestResponse(bool $overallOk, ?array $firstFailure, array $checks): array
  {
    if ($overallOk) {
      return [
        'ok' => true,
        'status' => 200,
        'error' => null,
        'retry_after' => null,
        'body' => Db::jsonEncode(['checks' => $checks]),
      ];
    }

    $error = 'Permission test failed';
    if ($firstFailure && !empty($firstFailure['label'])) {
      $error .= ': ' . $firstFailure['label'];
    }

    return [
      'ok' => false,
      'status' => (int)($firstFailure['status'] ?? 400),
      'error' => $error,
      'retry_after' => null,
      'body' => Db::jsonEncode(['checks' => $checks]),
    ];
  }

  private function responseMessage(array $resp): string
  {
    $body = trim((string)($resp['body'] ?? ''));
    if ($body === '') {
      return trim((string)($resp['error'] ?? ''));
    }
    $decoded = json_decode($body, true);
    if (is_array($decoded) && isset($decoded['message'])) {
      return trim((string)$decoded['message']);
    }
    return $body;
  }

  private function evaluateRoleHierarchy(int $corpId, array $rolesResp, array $memberResp): ?array
  {
    if (empty($rolesResp['ok']) || empty($memberResp['ok'])) {
      return null;
    }

    $rolesBody = json_decode((string)($rolesResp['body'] ?? ''), true);
    $memberBody = json_decode((string)($memberResp['body'] ?? ''), true);
    if (!is_array($rolesBody) || !is_array($memberBody)) {
      return [
        'id' => 'role_hierarchy',
        'label' => 'Role hierarchy (bot role above mapped roles)',
        'ok' => false,
        'status' => 0,
        'message' => 'Unable to parse role/member response.',
        'required' => false,
      ];
    }

    $roleMap = $this->loadRoleMap($corpId);
    if ($roleMap === []) {
      return [
        'id' => 'role_hierarchy',
        'label' => 'Role hierarchy (bot role above mapped roles)',
        'ok' => true,
        'status' => 200,
        'message' => 'No mapped roles configured.',
        'required' => false,
      ];
    }

    $roleIndex = [];
    foreach ($rolesBody as $role) {
      if (!is_array($role)) {
        continue;
      }
      $roleId = (string)($role['id'] ?? '');
      if ($roleId === '') {
        continue;
      }
      $roleIndex[$roleId] = [
        'position' => (int)($role['position'] ?? 0),
        'permissions' => (int)($role['permissions'] ?? 0),
        'name' => (string)($role['name'] ?? ''),
      ];
    }

    $botRoleIds = is_array($memberBody['roles'] ?? null) ? $memberBody['roles'] : [];
    $botRolePositions = [];
    $botPermissions = 0;
    foreach ($botRoleIds as $roleId) {
      $roleId = (string)$roleId;
      if (!isset($roleIndex[$roleId])) {
        continue;
      }
      $botRolePositions[] = $roleIndex[$roleId]['position'];
      $botPermissions |= $roleIndex[$roleId]['permissions'];
    }

    $highestBotRole = $botRolePositions !== [] ? max($botRolePositions) : 0;
    $hasManageRoles = ($botPermissions & 0x10000000) !== 0;

    $blockedRoles = [];
    foreach ($roleMap as $mappedRoleId) {
      if (!isset($roleIndex[$mappedRoleId])) {
        $blockedRoles[] = $mappedRoleId . ' (missing)';
        continue;
      }
      if ($roleIndex[$mappedRoleId]['position'] >= $highestBotRole) {
        $label = $roleIndex[$mappedRoleId]['name'] !== ''
          ? $roleIndex[$mappedRoleId]['name'] . ' (' . $mappedRoleId . ')'
          : $mappedRoleId;
        $blockedRoles[] = $label;
      }
    }

    if (!$hasManageRoles) {
      return [
        'id' => 'manage_roles',
        'label' => 'Manage Roles permission',
        'ok' => false,
        'status' => 0,
        'message' => 'Bot role lacks MANAGE_ROLES.',
        'required' => false,
      ];
    }

    if ($blockedRoles !== []) {
      return [
        'id' => 'role_hierarchy',
        'label' => 'Role hierarchy (bot role above mapped roles)',
        'ok' => false,
        'status' => 0,
        'message' => 'Roles above bot: ' . implode(', ', $blockedRoles),
        'required' => false,
      ];
    }

    return [
      'id' => 'role_hierarchy',
      'label' => 'Role hierarchy (bot role above mapped roles)',
      'ok' => true,
      'status' => 200,
      'message' => 'Bot can manage mapped roles.',
      'required' => false,
    ];
  }

  private function loadRoleMap(int $corpId): array
  {
    $row = $this->db->one(
      "SELECT role_map_json FROM discord_config WHERE corp_id = :cid LIMIT 1",
      ['cid' => $corpId]
    );
    if (!$row || empty($row['role_map_json'])) {
      return [];
    }
    $decoded = json_decode((string)$row['role_map_json'], true);
    if (!is_array($decoded)) {
      return [];
    }
    $roles = [];
    foreach ($decoded as $roleId) {
      $roleId = trim((string)$roleId);
      if ($roleId !== '') {
        $roles[] = $roleId;
      }
    }
    return $roles;
  }

  private function storePermissionTestResult(int $corpId, array $checks, bool $overallOk): void
  {
    $payload = Db::jsonEncode([
      'overall_ok' => $overallOk,
      'checks' => $checks,
    ]);

    $this->db->execute(
      "INSERT INTO discord_config (corp_id, bot_permissions_test_json, bot_permissions_test_at)
       VALUES (:cid, :json, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE
         bot_permissions_test_json = VALUES(bot_permissions_test_json),
         bot_permissions_test_at = VALUES(bot_permissions_test_at)",
      [
        'cid' => $corpId,
        'json' => $payload,
      ]
    );
  }

  private function loadConfigRow(int $corpId): array
  {
    $row = $this->db->one(
      "SELECT * FROM discord_config WHERE corp_id = :cid LIMIT 1",
      ['cid' => $corpId]
    );
    return $row ? $row : [];
  }

  private function touchBotAction(int $corpId): void
  {
    $this->db->execute(
      "UPDATE discord_config SET last_bot_action_at = UTC_TIMESTAMP() WHERE corp_id = :cid",
      ['cid' => $corpId]
    );
  }

  private function normalizeRoleMap($roleMapJson): array
  {
    if (is_string($roleMapJson)) {
      $decoded = json_decode($roleMapJson, true);
      $roleMapJson = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($roleMapJson)) {
      return [];
    }

    $normalized = [];
    foreach ($roleMapJson as $key => $value) {
      $roleId = trim((string)$value);
      if ($this->isValidSnowflake($roleId)) {
        $normalized[(string)$key] = $roleId;
      }
    }
    return $normalized;
  }

  private function normalizeOnboardingBypass($bypassJson): array
  {
    if (is_string($bypassJson)) {
      $decoded = json_decode($bypassJson, true);
      $bypassJson = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($bypassJson)) {
      return [];
    }

    $normalized = [];
    foreach ($bypassJson as $value) {
      $userId = trim((string)$value);
      if ($this->isValidSnowflake($userId)) {
        $normalized[] = $userId;
      }
    }
    return array_values(array_unique($normalized));
  }

  private function isValidSnowflake(string $value): bool
  {
    return $value !== '' && ctype_digit($value) && strlen($value) >= 17;
  }

  private function buildCommandDefinitions(): array
  {
    return [
      [
        'name' => 'quote',
        'description' => 'Get a hauling quote.',
        'options' => [
          [
            'type' => 3,
            'name' => 'pickup',
            'description' => 'Pickup system or location.',
            'required' => true,
          ],
          [
            'type' => 3,
            'name' => 'delivery',
            'description' => 'Delivery system or location.',
            'required' => true,
          ],
          [
            'type' => 10,
            'name' => 'volume',
            'description' => 'Volume in m3.',
            'required' => true,
          ],
          [
            'type' => 3,
            'name' => 'collateral',
            'description' => 'Collateral in ISK.',
            'required' => true,
          ],
          [
            'type' => 3,
            'name' => 'priority',
            'description' => 'Priority (normal/high).',
            'required' => false,
            'choices' => [
              ['name' => 'normal', 'value' => 'normal'],
              ['name' => 'high', 'value' => 'high'],
            ],
          ],
        ],
      ],
      [
        'name' => 'request',
        'description' => 'Lookup a hauling request by id or code.',
        'options' => [
          [
            'type' => 3,
            'name' => 'id',
            'description' => 'Request id or code.',
            'required' => true,
          ],
        ],
      ],
      [
        'name' => 'link',
        'description' => 'Link your portal account using a one-time code.',
        'options' => [
          [
            'type' => 3,
            'name' => 'code',
            'description' => 'One-time link code from the portal.',
            'required' => true,
          ],
        ],
      ],
      [
        'name' => 'myrequests',
        'description' => 'List your recent hauling requests.',
      ],
      [
        'name' => 'rates',
        'description' => 'Show current rate plan snapshot.',
      ],
      [
        'name' => 'help',
        'description' => 'Show command help and links.',
      ],
      [
        'name' => 'ping',
        'description' => 'Check bot health.',
      ],
    ];
  }
}
