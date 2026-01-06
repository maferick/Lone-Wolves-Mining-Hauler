<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class DiscordDeliveryService
{
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
        ORDER BY o.created_at ASC
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
        $this->applyResult($outboxId, $attempt, $resp, $results);
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

      $this->applyResult($outboxId, $attempt, $resp, $results);
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

    if (!$this->checkRateLimit((int)($row['channel_map_id'] ?? 0), $corpId)) {
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

    if (!$this->checkRateLimit((int)($row['channel_map_id'] ?? 0), $corpId)) {
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

    $message = $this->renderer->render($corpId, (string)($row['event_key'] ?? ''), $payload);
    $endpoint = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/')
      . '/channels/' . $channelId . '/messages';

    return $this->postJson($endpoint, $message, [
      'Authorization: Bot ' . $token,
    ]);
  }

  private function handleAdminTask(int $corpId, string $eventKey, array $payload): array
  {
    $token = (string)($this->config['discord']['bot_token'] ?? '');
    $appId = trim((string)($payload['application_id'] ?? $this->config['discord']['application_id'] ?? ''));
    $guildId = trim((string)($payload['guild_id'] ?? $this->config['discord']['guild_id'] ?? ''));

    if ($token === '' || $appId === '') {
      return [
        'ok' => false,
        'status' => 0,
        'error' => 'discord_credentials_missing',
        'retry_after' => null,
        'body' => '',
      ];
    }

    $base = rtrim((string)($this->config['discord']['api_base'] ?? 'https://discord.com/api/v10'), '/');

    if ($eventKey === 'discord.bot.permissions_test') {
      $endpoint = $base . '/users/@me';
      return $this->getJson($endpoint, [
        'Authorization: Bot ' . $token,
      ]);
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

  private function applyResult(int $outboxId, int $attempt, array $resp, array &$results): void
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

  private function checkRateLimit(int $channelMapId, int $corpId): bool
  {
    if ($channelMapId <= 0) {
      return true;
    }
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

  private function postJson(string $url, array $payload, array $headers = []): array
  {
    return $this->sendJson('POST', $url, $payload, $headers);
  }

  private function putJson(string $url, array $payload, array $headers = []): array
  {
    return $this->sendJson('PUT', $url, $payload, $headers);
  }

  private function getJson(string $url, array $headers = []): array
  {
    return $this->sendJson('GET', $url, null, $headers);
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
    return in_array($eventKey, ['discord.commands.register', 'discord.bot.permissions_test'], true);
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
