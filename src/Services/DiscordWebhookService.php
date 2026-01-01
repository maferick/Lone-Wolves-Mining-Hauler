<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class DiscordWebhookService
{
  public function __construct(private Db $db, private array $config = [])
  {
  }

  public function enqueue(int $corpId, int $requestId, array $payload): int
  {
    $hooks = $this->db->select(
      "SELECT webhook_id
         FROM discord_webhook
        WHERE corp_id = :cid AND is_enabled = 1",
      ['cid' => $corpId]
    );

    $count = 0;
    foreach ($hooks as $hook) {
      $this->db->insert('webhook_delivery', [
        'webhook_id' => (int)$hook['webhook_id'],
        'request_id' => $requestId,
        'status' => 'queued',
        'payload_json' => Db::jsonEncode($payload),
      ]);
      $count++;
    }

    return $count;
  }

  public function dispatchQueued(int $limit = 10): array
  {
    $rows = $this->db->select(
      "SELECT d.delivery_id, d.webhook_id, d.request_id, d.payload_json, d.attempt_count,
              w.webhook_url
         FROM webhook_delivery d
         JOIN discord_webhook w ON w.webhook_id = d.webhook_id
        WHERE d.status IN ('queued','retrying')
          AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= UTC_TIMESTAMP())
        ORDER BY d.created_at ASC
        LIMIT {$limit}"
    );

    $results = [
      'processed' => 0,
      'sent' => 0,
      'failed' => 0,
    ];

    foreach ($rows as $row) {
      $results['processed']++;
      $deliveryId = (int)$row['delivery_id'];
      $attempt = (int)($row['attempt_count'] ?? 0) + 1;
      $payload = Db::jsonDecode((string)$row['payload_json'], []);

      $resp = $this->postWebhook((string)$row['webhook_url'], $payload);
      if ($resp['ok']) {
        $this->db->execute(
          "UPDATE webhook_delivery
              SET status = 'sent',
                  attempt_count = :attempt,
                  last_attempt_at = UTC_TIMESTAMP(),
                  http_status = :http_status,
                  response_text = :response_text
            WHERE delivery_id = :id",
          [
            'attempt' => $attempt,
            'http_status' => $resp['status'],
            'response_text' => $resp['body'],
            'id' => $deliveryId,
          ]
        );
        $results['sent']++;
      } else {
        $nextAttempt = gmdate('Y-m-d H:i:s', time() + min(900, 60 * $attempt));
        $status = $attempt >= 5 ? 'dead' : 'retrying';
        $this->db->execute(
          "UPDATE webhook_delivery
              SET status = :status,
                  attempt_count = :attempt,
                  last_attempt_at = UTC_TIMESTAMP(),
                  next_attempt_at = :next_attempt_at,
                  http_status = :http_status,
                  error_text = :error_text,
                  response_text = :response_text
            WHERE delivery_id = :id",
          [
            'status' => $status,
            'attempt' => $attempt,
            'next_attempt_at' => $status === 'dead' ? null : $nextAttempt,
            'http_status' => $resp['status'],
            'error_text' => $resp['error'],
            'response_text' => $resp['body'],
            'id' => $deliveryId,
          ]
        );
        $results['failed']++;
      }

      if (!empty($row['request_id'])) {
        $this->db->insert('haul_event', [
          'request_id' => (int)$row['request_id'],
          'event_type' => $resp['ok'] ? 'discord_sent' : 'discord_failed',
          'message' => $resp['ok'] ? 'Discord webhook delivered.' : 'Discord webhook failed.',
          'payload_json' => Db::jsonEncode([
            'delivery_id' => $deliveryId,
            'http_status' => $resp['status'],
            'attempt' => $attempt,
          ]),
        ]);
      }
    }

    return $results;
  }

  private function postWebhook(string $url, array $payload): array
  {
    $payloadJson = Db::jsonEncode($payload);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'User-Agent: ' . ($this->config['app']['name'] ?? 'CorpHauling'),
    ]);
    $body = (string)curl_exec($ch);
    $errno = curl_errno($ch);
    $error = $errno ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno || $status < 200 || $status >= 300) {
      return [
        'ok' => false,
        'status' => $status,
        'error' => $error ?: "HTTP {$status}",
        'body' => $body,
      ];
    }

    return [
      'ok' => true,
      'status' => $status,
      'error' => null,
      'body' => $body,
    ];
  }
}
