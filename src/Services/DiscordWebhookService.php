<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class DiscordWebhookService
{
  public function __construct(private Db $db, private array $config = [])
  {
  }

  public function enqueue(int $corpId, string $eventKey, array $payload, ?int $webhookId = null): int
  {
    $params = ['cid' => $corpId];
    $webhookClause = '';
    if ($webhookId !== null) {
      $webhookClause = ' AND webhook_id = :wid';
      $params['wid'] = $webhookId;
    }

    $hooks = $this->db->select(
      "SELECT webhook_id
         FROM discord_webhook
        WHERE corp_id = :cid AND is_enabled = 1{$webhookClause}",
      $params
    );

    $count = 0;
    foreach ($hooks as $hook) {
      $this->db->insert('webhook_delivery', [
        'corp_id' => $corpId,
        'webhook_id' => (int)$hook['webhook_id'],
        'event_key' => $eventKey,
        'status' => 'pending',
        'payload_json' => Db::jsonEncode($payload),
      ]);
      $count++;
    }

    return $count;
  }

  public function sendPending(int $limit = 25): array
  {
    $rows = $this->db->select(
      "SELECT d.delivery_id, d.corp_id, d.webhook_id, d.event_key, d.payload_json, d.attempts,
              d.next_attempt_at, w.webhook_url
         FROM webhook_delivery d
         JOIN discord_webhook w ON w.webhook_id = d.webhook_id
        WHERE d.status = 'pending'
          AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= UTC_TIMESTAMP())
        ORDER BY d.created_at ASC
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
      $deliveryId = (int)$row['delivery_id'];
      $attempt = (int)($row['attempts'] ?? 0);
      $attemptNext = $attempt + 1;
      $payload = Db::jsonDecode((string)$row['payload_json'], []);

      try {
        $resp = $this->postWebhook((string)$row['webhook_url'], $payload);
      } catch (\Throwable $e) {
        $resp = [
          'ok' => false,
          'status' => 0,
          'error' => $e->getMessage(),
          'body' => '',
        ];
      }

      if ($resp['ok']) {
        $this->db->execute(
          "UPDATE webhook_delivery
              SET status = 'sent',
                  attempts = :attempts,
                  sent_at = UTC_TIMESTAMP(),
                  last_http_status = :http_status,
                  last_error = NULL,
                  next_attempt_at = NULL
            WHERE delivery_id = :id",
          [
            'attempts' => $attemptNext,
            'http_status' => $resp['status'],
            'id' => $deliveryId,
          ]
        );
        $results['sent']++;
        continue;
      }

      $backoffSeconds = min(3600, (int)(pow(2, $attemptNext) * 30));
      $nextAttempt = gmdate('Y-m-d H:i:s', time() + $backoffSeconds);
      $status = $attemptNext >= 10 ? 'failed' : 'pending';
      $snippet = $this->snippet((string)($resp['body'] ?? ''));
      $error = trim((string)($resp['error'] ?? ''));
      $errorText = $error !== '' ? $error : 'HTTP error';
      if ($snippet !== '') {
        $errorText .= ' | Response: ' . $snippet;
      }
      $this->db->execute(
        "UPDATE webhook_delivery
            SET status = :status,
                attempts = :attempts,
                next_attempt_at = :next_attempt_at,
                last_http_status = :http_status,
                last_error = :last_error
          WHERE delivery_id = :id",
        [
          'status' => $status,
          'attempts' => $attemptNext,
          'next_attempt_at' => $status === 'failed' ? null : $nextAttempt,
          'http_status' => $resp['status'],
          'last_error' => $errorText,
          'id' => $deliveryId,
        ]
      );
      if ($status === 'failed') {
        $results['failed']++;
      } else {
        $results['pending']++;
      }
    }

    return $results;
  }

  public function dispatchQueued(int $limit = 10): array
  {
    return $this->sendPending($limit);
  }

  public function buildHaulRequestEmbed(array $details): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $title = (string)($details['title'] ?? 'Haul Request');
    $route = $details['route'] ?? [];
    $from = (string)($details['from_system'] ?? $this->extractRouteEndpoint($route, 'from'));
    $to = (string)($details['to_system'] ?? $this->extractRouteEndpoint($route, 'to'));
    $jumps = (int)($details['jumps'] ?? ($route['jumps'] ?? 0));
    $hs = (int)($details['hs'] ?? ($route['hs_count'] ?? 0));
    $ls = (int)($details['ls'] ?? ($route['ls_count'] ?? 0));
    $ns = (int)($details['ns'] ?? ($route['ns_count'] ?? 0));
    $volume = (float)($details['volume_m3'] ?? 0);
    $collateral = (float)($details['collateral_isk'] ?? 0);
    $price = (float)($details['price_isk'] ?? 0);
    $requester = (string)($details['requester'] ?? 'Unknown');
    $requestUrl = $this->buildRequestUrl($details['request_id'] ?? null);
    $description = trim($from . ' → ' . $to);

    $fields = [
      [
        'name' => 'Route',
        'value' => sprintf('%d jumps (%d HS / %d LS / %d NS)', $jumps, $hs, $ls, $ns),
        'inline' => true,
      ],
      [
        'name' => 'Volume',
        'value' => number_format($volume, 0) . ' m³',
        'inline' => true,
      ],
      [
        'name' => 'Collateral',
        'value' => number_format($collateral, 2) . ' ISK',
        'inline' => true,
      ],
      [
        'name' => 'Price',
        'value' => number_format($price, 2) . ' ISK',
        'inline' => true,
      ],
      [
        'name' => 'Requester',
        'value' => $requester,
        'inline' => true,
      ],
    ];

    if ($requestUrl !== '') {
      $fields[] = [
        'name' => 'Request',
        'value' => '[' . $title . '](' . $requestUrl . ')',
        'inline' => false,
      ];
    }

    return [
      'username' => $appName,
      'embeds' => [
        [
          'title' => $title,
          'description' => $description,
          'fields' => $fields,
          'timestamp' => gmdate('c'),
        ],
      ],
    ];
  }

  public function buildContractsPulledPayload(array $summary): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $fields = [
      [
        'name' => 'Fetched',
        'value' => (string)($summary['fetched_contracts'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Upserted',
        'value' => (string)($summary['upserted_contracts'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Items',
        'value' => (string)($summary['upserted_items'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Pages',
        'value' => (string)($summary['pages'] ?? 0),
        'inline' => true,
      ],
    ];

    return [
      'username' => $appName,
      'embeds' => [
        [
          'title' => 'ESI Contracts Pulled',
          'description' => 'Corp contracts refresh completed.',
          'fields' => $fields,
          'timestamp' => gmdate('c'),
        ],
      ],
    ];
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

  private function extractRouteEndpoint(array $route, string $type): string
  {
    if (!isset($route['path']) || !is_array($route['path']) || $route['path'] === []) {
      return '';
    }
    $node = $type === 'to' ? end($route['path']) : $route['path'][0];
    if (!is_array($node)) {
      return '';
    }
    return (string)($node['system_name'] ?? '');
  }

  private function buildRequestUrl(?int $requestId): string
  {
    if (!$requestId) {
      return '';
    }
    $baseUrl = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
    $basePath = rtrim((string)($this->config['app']['base_path'] ?? ''), '/');
    $baseUrlPath = rtrim((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''), '/');
    $pathPrefix = ($baseUrlPath !== '' && $baseUrlPath !== '/') ? '' : $basePath;
    $path = ($pathPrefix ?: '') . '/request?request_id=' . (string)$requestId;
    return $baseUrl !== '' ? $baseUrl . $path : $path;
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
}
