<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class DiscordWebhookService
{
  private const EVENT_SETTING_KEY = 'discord.webhook_events';
  private array $eventSettingsCache = [];

  public function __construct(private Db $db, private array $config = [])
  {
  }

  public function enqueue(int $corpId, string $eventKey, array $payload, ?int $webhookId = null): int
  {
    if (!$this->isEventEnabled($corpId, $eventKey)) {
      return 0;
    }

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

  public function enqueueContractLinked(int $corpId, array $payload): int
  {
    $hooks = $this->db->select(
      "SELECT webhook_id
         FROM discord_webhook
        WHERE corp_id = :cid AND is_enabled = 1 AND notify_on_contract_link = 1",
      ['cid' => $corpId]
    );

    $count = 0;
    foreach ($hooks as $hook) {
      $this->db->insert('webhook_delivery', [
        'corp_id' => $corpId,
        'webhook_id' => (int)$hook['webhook_id'],
        'event_key' => 'haul.contract.linked',
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
    $requesterCharacterId = (int)($details['requester_character_id'] ?? 0);
    $requesterAvatarUrl = trim((string)($details['requester_avatar_url'] ?? ''));
    $shipClass = trim((string)($details['ship_class'] ?? ''));
    $requestUrl = $this->buildRequestUrl($details['request_key'] ?? null);
    $description = trim($from . ' → ' . $to);
    if ($description === '→') {
      $description = '';
    }

    $requesterAvatarUrl = $requesterAvatarUrl !== ''
      ? $requesterAvatarUrl
      : $this->buildCharacterPortraitUrl($requesterCharacterId);
    $shipLabel = $this->formatShipClassLabel($shipClass);
    $priceLabel = $this->formatIskShort($price);
    $fields = [
      [
        'name' => 'Route',
        'value' => sprintf("%d jumps\n(%d HS / %d LS / %d NS)", $jumps, $hs, $ls, $ns),
        'inline' => true,
      ],
      [
        'name' => 'Transport',
        'value' => $shipLabel !== '' ? $shipLabel : 'N/A',
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
        'value' => $priceLabel,
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

    $embed = [
      'title' => $title,
      'description' => $description,
      'fields' => $fields,
      'timestamp' => gmdate('c'),
    ];

    if ($requester !== '') {
      $author = ['name' => $requester];
      if ($requesterAvatarUrl !== '') {
        $author['icon_url'] = $requesterAvatarUrl;
      }
      $embed['author'] = $author;
    }

    return [
      'username' => $appName,
      'embeds' => [
        $embed,
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

  public function buildHaulAssignmentPayload(array $details): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $title = (string)($details['title'] ?? 'Haul Assignment');
    $route = $details['route'] ?? [];
    $from = (string)($details['from_system'] ?? $this->extractRouteEndpoint($route, 'from'));
    $to = (string)($details['to_system'] ?? $this->extractRouteEndpoint($route, 'to'));
    $volume = (float)($details['volume_m3'] ?? 0);
    $reward = (float)($details['reward_isk'] ?? 0);
    $requester = trim((string)($details['requester'] ?? ''));
    $requesterCharacterId = (int)($details['requester_character_id'] ?? 0);
    $requesterAvatarUrl = trim((string)($details['requester_avatar_url'] ?? ''));
    $hauler = trim((string)($details['hauler'] ?? ''));
    $haulerCharacterId = (int)($details['hauler_character_id'] ?? 0);
    $haulerAvatarUrl = trim((string)($details['hauler_avatar_url'] ?? ''));
    $status = trim((string)($details['status'] ?? ''));
    $actor = trim((string)($details['actor'] ?? ''));
    $actorLabel = trim((string)($details['actor_label'] ?? 'Actor'));
    $actorCharacterId = (int)($details['actor_character_id'] ?? 0);
    $actorAvatarUrl = trim((string)($details['actor_avatar_url'] ?? ''));
    $shipClass = trim((string)($details['ship_class'] ?? ''));
    $requestUrl = $this->buildRequestUrl($details['request_key'] ?? null);
    $description = trim($from . ' → ' . $to);

    $requesterAvatarUrl = $requesterAvatarUrl !== ''
      ? $requesterAvatarUrl
      : $this->buildCharacterPortraitUrl($requesterCharacterId);
    $haulerAvatarUrl = $haulerAvatarUrl !== ''
      ? $haulerAvatarUrl
      : $this->buildCharacterPortraitUrl($haulerCharacterId);
    $actorAvatarUrl = $actorAvatarUrl !== ''
      ? $actorAvatarUrl
      : $this->buildCharacterPortraitUrl($actorCharacterId);
    $shipLabel = $this->formatShipClassLabel($shipClass !== '' ? $shipClass : 'JF');
    $shipImageUrl = $this->buildShipClassImageUrl($shipClass !== '' ? $shipClass : 'JF');
    $fields = [];
    if ($description !== '') {
      $fields[] = [
        'name' => 'Route',
        'value' => $description,
        'inline' => false,
      ];
    }
    if ($volume > 0) {
      $fields[] = [
        'name' => 'Volume',
        'value' => number_format($volume, 0) . ' m³',
        'inline' => true,
      ];
    }
    if ($reward > 0) {
      $fields[] = [
        'name' => 'Reward',
        'value' => $this->formatIskShort($reward),
        'inline' => true,
      ];
    }
    if ($shipLabel !== '') {
      $fields[] = [
        'name' => 'Transport',
        'value' => $shipLabel,
        'inline' => true,
      ];
    }
    if ($requester !== '') {
      $fields[] = [
        'name' => 'Requester',
        'value' => $requester,
        'inline' => true,
      ];
    }
    if ($hauler !== '') {
      $fields[] = [
        'name' => 'Hauler',
        'value' => $hauler,
        'inline' => true,
      ];
    }
    if ($actor !== '') {
      $fields[] = [
        'name' => $actorLabel !== '' ? $actorLabel : 'Actor',
        'value' => $actor,
        'inline' => true,
      ];
    }
    if ($status !== '') {
      $fields[] = [
        'name' => 'Status',
        'value' => $status,
        'inline' => true,
      ];
    }

    if ($requestUrl !== '') {
      $fields[] = [
        'name' => 'Request',
        'value' => '[' . $title . '](' . $requestUrl . ')',
        'inline' => false,
      ];
    }

    $embed = [
      'title' => $title,
      'description' => $description,
      'fields' => $fields,
      'timestamp' => gmdate('c'),
    ];

    if ($hauler !== '') {
      $author = ['name' => $hauler];
      if ($haulerAvatarUrl !== '') {
        $author['icon_url'] = $haulerAvatarUrl;
      }
      $embed['author'] = $author;
    }
    if ($requesterAvatarUrl !== '') {
      $embed['thumbnail'] = ['url' => $requesterAvatarUrl];
    }
    if ($actor !== '') {
      $footer = ['text' => ($actorLabel !== '' ? $actorLabel : 'Actor') . ': ' . $actor];
      if ($actorAvatarUrl !== '') {
        $footer['icon_url'] = $actorAvatarUrl;
      }
      $embed['footer'] = $footer;
    }
    if ($shipImageUrl !== '') {
      $embed['image'] = ['url' => $shipImageUrl];
    }

    return [
      'username' => $appName,
      'embeds' => [
        $embed,
      ],
    ];
  }

  public function buildContractLinkedPayload(array $details): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $requestId = (int)($details['request_id'] ?? 0);
    $route = trim((string)($details['route'] ?? ''));
    $shipClass = trim((string)($details['ship_class'] ?? ''));
    $volume = (float)($details['volume_m3'] ?? 0.0);
    $collateral = (float)($details['collateral_isk'] ?? 0.0);
    $price = (float)($details['price_isk'] ?? 0.0);
    $contractId = (int)($details['contract_id'] ?? 0);
    $requestUrl = $this->buildRequestUrl($details['request_key'] ?? null);

    $lines = [
      sprintf('Contract linked for request #%s', $requestId > 0 ? (string)$requestId : 'N/A'),
    ];
    if ($route !== '') {
      $lines[] = 'Route: ' . $route;
    }
    if ($shipClass !== '') {
      $lines[] = 'Ship class: ' . $shipClass;
    }
    $lines[] = 'Volume: ' . number_format($volume, 0) . ' m³';
    $lines[] = 'Collateral: ' . number_format($collateral, 2) . ' ISK';
    $lines[] = 'Price: ' . number_format($price, 2) . ' ISK';
    if ($contractId > 0) {
      $lines[] = 'Contract ID: ' . (string)$contractId;
    }
    if ($requestUrl !== '') {
      $lines[] = $requestUrl;
    }

    return [
      'username' => $appName,
      'content' => implode("\n", $lines),
    ];
  }

  private function isEventEnabled(int $corpId, string $eventKey): bool
  {
    $settings = $this->getEventSettings($corpId);
    if (!array_key_exists($eventKey, $settings)) {
      return true;
    }

    return !empty($settings[$eventKey]);
  }

  private function getEventSettings(int $corpId): array
  {
    if (isset($this->eventSettingsCache[$corpId])) {
      return $this->eventSettingsCache[$corpId];
    }

    $defaults = $this->defaultEventSettings();
    $row = $this->db->one(
      "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = :key LIMIT 1",
      ['cid' => $corpId, 'key' => self::EVENT_SETTING_KEY]
    );

    if (!$row || empty($row['setting_json'])) {
      $this->eventSettingsCache[$corpId] = $defaults;
      return $defaults;
    }

    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
    if (!is_array($decoded)) {
      $this->eventSettingsCache[$corpId] = $defaults;
      return $defaults;
    }

    $this->eventSettingsCache[$corpId] = array_replace($defaults, $decoded);
    return $this->eventSettingsCache[$corpId];
  }

  private function defaultEventSettings(): array
  {
    return [
      'haul.request.created' => true,
      'haul.quote.created' => true,
      'haul.contract.attached' => true,
      'haul.assignment.created' => true,
      'haul.assignment.picked_up' => true,
      'esi.contracts.pulled' => true,
      'webhook.test' => true,
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

  private function formatShipClassLabel(string $shipClass): string
  {
    $normalized = strtoupper(trim($shipClass));
    $labels = [
      'BR' => 'Blockade Runner',
      'DST' => 'Deep Space Transport',
      'JF' => 'Jump Freighter',
      'FREIGHTER' => 'Freighter',
    ];

    return $labels[$normalized] ?? $shipClass;
  }

  private function formatIskShort(float $amount): string
  {
    $abs = abs($amount);
    $suffix = ' ISK';
    $value = $amount;
    $unit = '';

    if ($abs >= 1_000_000_000_000) {
      $value = $amount / 1_000_000_000_000;
      $unit = 'T';
    } elseif ($abs >= 1_000_000_000) {
      $value = $amount / 1_000_000_000;
      $unit = 'B';
    } elseif ($abs >= 1_000_000) {
      $value = $amount / 1_000_000;
      $unit = 'M';
    } elseif ($abs >= 1_000) {
      $value = $amount / 1_000;
      $unit = 'K';
    }

    if ($unit === '') {
      return number_format($amount, 0) . $suffix;
    }

    $decimals = abs($value) < 10 ? 1 : 0;
    $formatted = number_format($value, $decimals);
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted . $unit . $suffix;
  }

  private function buildCharacterPortraitUrl(int $characterId): string
  {
    if ($characterId <= 0) {
      return '';
    }
    return 'https://images.evetech.net/characters/' . $characterId . '/portrait?size=64';
  }

  private function buildShipClassImageUrl(string $shipClass): string
  {
    $normalized = strtoupper(trim($shipClass));
    $typeIds = match ($normalized) {
      'BR' => [12733, 12735, 12743, 12745],
      'DST' => [12727, 12729, 12731, 12737],
      'FREIGHTER' => [20183, 20185, 20187, 20189],
      'JF', '' => [28844, 28846, 28848, 28850],
      default => [],
    };

    if ($typeIds === []) {
      return '';
    }

    $typeId = $typeIds[array_rand($typeIds)];
    return $this->buildTypeRenderUrl($typeId);
  }

  private function buildTypeRenderUrl(int $typeId): string
  {
    if ($typeId <= 0) {
      return '';
    }
    return 'https://images.evetech.net/types/' . $typeId . '/render?size=512';
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
