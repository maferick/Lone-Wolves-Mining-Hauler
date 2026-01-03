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
    return $this->enqueueInternal($corpId, $eventKey, $payload, $webhookId, true);
  }

  public function enqueueTest(int $corpId, string $eventKey, array $payload, ?int $webhookId = null): int
  {
    return $this->enqueueInternal($corpId, $eventKey, $payload, $webhookId, false);
  }

  public function enqueueUnique(
    int $corpId,
    string $eventKey,
    array $payload,
    string $entityType,
    int $entityId,
    string $transitionTo,
    ?int $webhookId = null
  ): int {
    $hooks = $this->selectWebhookTargets($corpId, $eventKey, $webhookId, true);

    $count = 0;
    $payloadJson = Db::jsonEncode($payload);
    foreach ($hooks as $hook) {
      $count += $this->db->execute(
        "INSERT IGNORE INTO webhook_delivery
          (corp_id, webhook_id, event_key, entity_type, entity_id, transition_to, status, payload_json)
         VALUES
          (:corp_id, :webhook_id, :event_key, :entity_type, :entity_id, :transition_to, 'pending', :payload_json)",
        [
          'corp_id' => $corpId,
          'webhook_id' => (int)$hook['webhook_id'],
          'event_key' => $eventKey,
          'entity_type' => $entityType,
          'entity_id' => $entityId,
          'transition_to' => $transitionTo,
          'payload_json' => $payloadJson,
        ]
      );
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
      $payload = $this->ensurePayload((string)($row['event_key'] ?? ''), $payload);

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

  public function buildContractsReconciledPayload(array $summary): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $fields = [
      [
        'name' => 'Scanned',
        'value' => (string)($summary['scanned'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Updated',
        'value' => (string)($summary['updated'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Skipped',
        'value' => (string)($summary['skipped'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Not found',
        'value' => (string)($summary['not_found'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Errors',
        'value' => (string)($summary['errors'] ?? 0),
        'inline' => true,
      ],
      [
        'name' => 'Pages',
        'value' => (string)($summary['pages'] ?? 0),
        'inline' => true,
      ],
    ];
    $errorDetails = array_values(array_filter($summary['error_details'] ?? [], fn($detail) => $detail !== null && $detail !== ''));
    if ($errorDetails !== []) {
      $detailText = implode("\n", array_map(fn($detail) => '• ' . (string)$detail, $errorDetails));
      if (strlen($detailText) > 1024) {
        $detailText = substr($detailText, 0, 1021) . '...';
      }
      $fields[] = [
        'name' => 'Error details',
        'value' => $detailText,
        'inline' => false,
      ];
    }

    return [
      'username' => $appName,
      'embeds' => [
        [
          'title' => 'ESI Contracts Reconciled',
          'description' => 'Linked contract status reconciliation completed.',
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

    if ($requester !== '') {
      $author = ['name' => $requester];
      if ($requesterAvatarUrl !== '') {
        $author['icon_url'] = $requesterAvatarUrl;
      }
      $embed['author'] = $author;
    } elseif ($hauler !== '') {
      $author = ['name' => $hauler];
      if ($haulerAvatarUrl !== '') {
        $author['icon_url'] = $haulerAvatarUrl;
      }
      $embed['author'] = $author;
    }
    if ($shipImageUrl !== '') {
      $embed['thumbnail'] = ['url' => $shipImageUrl];
    }
    if ($actor !== '') {
      $footer = ['text' => ($actorLabel !== '' ? $actorLabel : 'Actor') . ': ' . $actor];
      if ($actorAvatarUrl !== '') {
        $footer['icon_url'] = $actorAvatarUrl;
      }
      $embed['footer'] = $footer;
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
    $pickup = trim((string)($details['pickup'] ?? ''));
    $dropoff = trim((string)($details['dropoff'] ?? ''));
    $shipClass = trim((string)($details['ship_class'] ?? ''));
    $volume = (float)($details['volume_m3'] ?? 0.0);
    $collateral = (float)($details['collateral_isk'] ?? 0.0);
    $price = (float)($details['price_isk'] ?? 0.0);
    $contractId = (int)($details['contract_id'] ?? 0);
    $issuerId = (int)($details['issuer_id'] ?? 0);
    $issuerName = trim((string)($details['issuer_name'] ?? ''));

    $shipLabel = $this->formatShipClassLabel($shipClass);
    $fields = [];
    if ($pickup !== '') {
      $fields[] = ['name' => 'Pickup', 'value' => $pickup, 'inline' => true];
    }
    if ($dropoff !== '') {
      $fields[] = ['name' => 'Dropoff', 'value' => $dropoff, 'inline' => true];
    }
    if ($shipLabel !== '') {
      $fields[] = ['name' => 'Ship', 'value' => $shipLabel, 'inline' => true];
    }
    $fields[] = ['name' => 'Reward', 'value' => number_format($price, 2) . ' ISK', 'inline' => true];
    $fields[] = ['name' => 'Collateral', 'value' => number_format($collateral, 2) . ' ISK', 'inline' => true];
    $fields[] = ['name' => 'Volume', 'value' => number_format($volume, 0) . ' m³', 'inline' => true];
    if ($route !== '') {
      $fields[] = ['name' => 'Route', 'value' => str_replace('→', '-', $route), 'inline' => false];
    }
    if ($contractId > 0) {
      $fields[] = ['name' => 'Contract ID', 'value' => (string)$contractId, 'inline' => true];
    }

    $embed = [
      'title' => 'Contract ready for pickup and delivery',
      'description' => 'This contract has been checked and is OK.',
      'fields' => $fields,
      'timestamp' => gmdate('c'),
    ];

    if ($issuerName !== '') {
      $author = ['name' => $issuerName];
      $issuerAvatarUrl = $this->buildCharacterPortraitUrl($issuerId);
      if ($issuerAvatarUrl !== '') {
        $author['icon_url'] = $issuerAvatarUrl;
      }
      $embed['author'] = $author;
    } elseif ($requestId > 0) {
      $embed['footer'] = ['text' => 'Request #' . $requestId];
    }

    return [
      'username' => $appName,
      'embeds' => [
        $embed,
      ],
    ];
  }

  public function buildContractPickedUpPayload(array $details): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $requestId = (int)($details['request_id'] ?? 0);
    $route = trim((string)($details['route'] ?? ''));
    $pickup = trim((string)($details['pickup'] ?? ''));
    $dropoff = trim((string)($details['dropoff'] ?? ''));
    $shipClass = trim((string)($details['ship_class'] ?? ''));
    $volume = (float)($details['volume_m3'] ?? 0.0);
    $collateral = (float)($details['collateral_isk'] ?? 0.0);
    $reward = (float)($details['reward_isk'] ?? 0.0);
    $contractId = (int)($details['contract_id'] ?? 0);
    $acceptorId = (int)($details['acceptor_id'] ?? 0);
    $acceptorName = trim((string)($details['acceptor_name'] ?? ''));

    $shipLabel = $this->formatShipClassLabel($shipClass);
    $fields = [];
    if ($pickup !== '') {
      $fields[] = ['name' => 'Pickup', 'value' => $pickup, 'inline' => true];
    }
    if ($dropoff !== '') {
      $fields[] = ['name' => 'Dropoff', 'value' => $dropoff, 'inline' => true];
    }
    if ($shipLabel !== '') {
      $fields[] = ['name' => 'Ship', 'value' => $shipLabel, 'inline' => true];
    }
    if ($acceptorName !== '') {
      $fields[] = ['name' => 'Hauler', 'value' => $acceptorName, 'inline' => true];
    }
    $fields[] = ['name' => 'Reward', 'value' => number_format($reward, 2) . ' ISK', 'inline' => true];
    $fields[] = ['name' => 'Collateral', 'value' => number_format($collateral, 2) . ' ISK', 'inline' => true];
    $fields[] = ['name' => 'Volume', 'value' => number_format($volume, 0) . ' m³', 'inline' => true];
    if ($route !== '') {
      $fields[] = ['name' => 'Route', 'value' => str_replace('→', '-', $route), 'inline' => false];
    }
    if ($contractId > 0) {
      $fields[] = ['name' => 'Contract ID', 'value' => (string)$contractId, 'inline' => true];
    }

    $embed = [
      'title' => 'Contract picked up',
      'description' => 'This contract has been accepted in-game and is now in progress.',
      'fields' => $fields,
      'timestamp' => gmdate('c'),
    ];

    if ($acceptorName !== '') {
      $author = ['name' => $acceptorName];
      $acceptorAvatarUrl = $this->buildCharacterPortraitUrl($acceptorId);
      if ($acceptorAvatarUrl !== '') {
        $author['icon_url'] = $acceptorAvatarUrl;
      }
      $embed['author'] = $author;
    } elseif ($requestId > 0) {
      $embed['footer'] = ['text' => 'Request #' . $requestId];
    }

    return [
      'username' => $appName,
      'embeds' => [
        $embed,
      ],
    ];
  }

  public function buildContractDeliveredPayload(array $details): array
  {
    return $this->buildContractLifecyclePayload(
      $details,
      'Contract delivered',
      'This contract has been delivered and marked as complete.'
    );
  }

  public function buildContractFailedPayload(array $details): array
  {
    return $this->buildContractLifecyclePayload(
      $details,
      'Contract failed',
      'This contract has failed and is no longer active.'
    );
  }

  public function buildContractExpiredPayload(array $details): array
  {
    return $this->buildContractLifecyclePayload(
      $details,
      'Contract expired',
      'This contract expired before it could be completed.'
    );
  }

  public function buildTestPayloadForEvent(int $corpId, string $eventKey, array $options = []): array
  {
    $message = trim((string)($options['message'] ?? ''));
    $request = $this->fetchLatestHaulRequest($corpId);
    $assignment = $this->fetchLatestHaulAssignment($corpId);
    $contractRequest = $this->fetchLatestContractRequest($corpId);

    return match ($eventKey) {
      'haul.request.created' => $this->buildHaulRequestTestPayload($request, 'Haul Request'),
      'haul.quote.created' => $this->buildHaulQuoteTestPayload($corpId),
      'haul.contract.attached' => $this->buildContractAttachedTestPayload($request),
      'haul.contract.picked_up', 'contract.picked_up' => $this->buildContractPickedUpTestPayload($contractRequest),
      'contract.delivered' => $this->buildContractLifecycleTestPayload($contractRequest, 'delivered'),
      'contract.failed' => $this->buildContractLifecycleTestPayload($contractRequest, 'failed'),
      'contract.expired' => $this->buildContractLifecycleTestPayload($contractRequest, 'expired'),
      'haul.assignment.created' => $this->buildAssignmentTestPayload($assignment, 'assigned'),
      'haul.assignment.picked_up' => $this->buildAssignmentTestPayload($assignment, 'in_transit'),
      'esi.contracts.pulled' => $this->buildContractsPulledPayload($this->buildContractsPulledSummary($corpId)),
      'esi.contracts.reconciled' => $this->buildContractsReconciledPayload($this->buildContractsReconciledSummary($corpId)),
      'webhook.test' => $this->buildManualTestPayload($message),
      default => $this->ensurePayload($eventKey, []),
    };
  }

  private function enqueueInternal(
    int $corpId,
    string $eventKey,
    array $payload,
    ?int $webhookId,
    bool $respectSettings
  ): int {
    $hooks = $this->selectWebhookTargets($corpId, $eventKey, $webhookId, $respectSettings);

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

  private function selectWebhookTargets(
    int $corpId,
    string $eventKey,
    ?int $webhookId,
    bool $respectSubscriptions
  ): array
  {
    $params = [
      'cid' => $corpId,
    ];
    $webhookClause = '';
    if ($webhookId !== null) {
      $webhookClause = ' AND w.webhook_id = :wid';
      $params['wid'] = $webhookId;
    }

    if ($respectSubscriptions) {
      $params['event_key'] = $eventKey;
      return $this->db->select(
        "SELECT w.webhook_id
           FROM discord_webhook w
           LEFT JOIN discord_webhook_event e
             ON e.webhook_id = w.webhook_id AND e.event_key = :event_key
          WHERE w.corp_id = :cid
            AND w.is_enabled = 1{$webhookClause}
            AND (e.event_key IS NULL OR e.is_enabled = 1)",
        $params
      );
    }

    return $this->db->select(
      "SELECT w.webhook_id
         FROM discord_webhook w
        WHERE w.corp_id = :cid AND w.is_enabled = 1{$webhookClause}",
      $params
    );
  }

  private function buildContractLifecyclePayload(array $details, string $title, string $description): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $requestId = (int)($details['request_id'] ?? 0);
    $requestKey = (string)($details['request_key'] ?? '');
    $route = trim((string)($details['route'] ?? ''));
    $pickup = trim((string)($details['pickup'] ?? ''));
    $dropoff = trim((string)($details['dropoff'] ?? ''));
    $shipClass = trim((string)($details['ship_class'] ?? ''));
    $volume = (float)($details['volume_m3'] ?? 0.0);
    $collateral = (float)($details['collateral_isk'] ?? 0.0);
    $reward = (float)($details['reward_isk'] ?? 0.0);
    $contractId = (int)($details['contract_id'] ?? 0);
    $acceptorId = (int)($details['acceptor_id'] ?? 0);
    $acceptorName = trim((string)($details['acceptor_name'] ?? ''));
    $requestUrl = $this->buildRequestUrl($requestKey !== '' ? $requestKey : null);

    $shipLabel = $this->formatShipClassLabel($shipClass);
    $fields = [];
    if ($pickup !== '') {
      $fields[] = ['name' => 'Pickup', 'value' => $pickup, 'inline' => true];
    }
    if ($dropoff !== '') {
      $fields[] = ['name' => 'Dropoff', 'value' => $dropoff, 'inline' => true];
    }
    if ($shipLabel !== '') {
      $fields[] = ['name' => 'Ship', 'value' => $shipLabel, 'inline' => true];
    }
    if ($acceptorName !== '') {
      $fields[] = ['name' => 'Hauler', 'value' => $acceptorName, 'inline' => true];
    }
    if ($reward > 0) {
      $fields[] = ['name' => 'Reward', 'value' => number_format($reward, 2) . ' ISK', 'inline' => true];
    }
    if ($collateral > 0) {
      $fields[] = ['name' => 'Collateral', 'value' => number_format($collateral, 2) . ' ISK', 'inline' => true];
    }
    if ($volume > 0) {
      $fields[] = ['name' => 'Volume', 'value' => number_format($volume, 0) . ' m³', 'inline' => true];
    }
    if ($route !== '') {
      $fields[] = ['name' => 'Route', 'value' => str_replace('→', '-', $route), 'inline' => false];
    }
    if ($contractId > 0) {
      $fields[] = ['name' => 'Contract ID', 'value' => (string)$contractId, 'inline' => true];
    }
    if ($requestUrl !== '') {
      $fields[] = ['name' => 'Request', 'value' => '[Request #' . $requestId . '](' . $requestUrl . ')', 'inline' => false];
    }

    $embed = [
      'title' => $title,
      'description' => $description,
      'fields' => $fields,
      'timestamp' => gmdate('c'),
    ];

    if ($acceptorName !== '') {
      $author = ['name' => $acceptorName];
      $acceptorAvatarUrl = $this->buildCharacterPortraitUrl($acceptorId);
      if ($acceptorAvatarUrl !== '') {
        $author['icon_url'] = $acceptorAvatarUrl;
      }
      $embed['author'] = $author;
    } elseif ($requestId > 0) {
      $embed['footer'] = ['text' => 'Request #' . $requestId];
    }

    return [
      'username' => $appName,
      'embeds' => [
        $embed,
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

  private function ensurePayload(string $eventKey, array $payload): array
  {
    if ($this->hasPayloadContent($payload)) {
      return $payload;
    }

    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $title = match ($eventKey) {
      'contract.delivered' => 'Contract delivered',
      'contract.failed' => 'Contract failed',
      'contract.expired' => 'Contract expired',
      'contract.picked_up' => 'Contract picked up',
      default => 'Webhook notification',
    };
    $description = match ($eventKey) {
      'contract.delivered' => 'This contract has been delivered and marked as complete.',
      'contract.failed' => 'This contract has failed and is no longer active.',
      'contract.expired' => 'This contract expired before it could be completed.',
      'contract.picked_up' => 'This contract has been accepted in-game and is now in progress.',
      default => 'A webhook notification was generated.',
    };

    $fields = [];
    if (!empty($payload['request_id'])) {
      $fields[] = ['name' => 'Request ID', 'value' => (string)$payload['request_id'], 'inline' => true];
    }
    if (!empty($payload['contract_id'])) {
      $fields[] = ['name' => 'Contract ID', 'value' => (string)$payload['contract_id'], 'inline' => true];
    }
    if (!empty($payload['acceptor_name'])) {
      $fields[] = ['name' => 'Hauler', 'value' => (string)$payload['acceptor_name'], 'inline' => true];
    }
    if (!empty($payload['previous_state'])) {
      $fields[] = ['name' => 'Previous state', 'value' => (string)$payload['previous_state'], 'inline' => true];
    }
    if (!empty($payload['contract_state'])) {
      $fields[] = ['name' => 'Current state', 'value' => (string)$payload['contract_state'], 'inline' => true];
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

  private function hasPayloadContent(array $payload): bool
  {
    if (!empty($payload['content']) && trim((string)$payload['content']) !== '') {
      return true;
    }

    if (!empty($payload['embeds']) && is_array($payload['embeds'])) {
      foreach ($payload['embeds'] as $embed) {
        if (!is_array($embed)) {
          continue;
        }
        if ($this->hasEmbedContent($embed)) {
          return true;
        }
      }
    }

    return false;
  }

  private function hasEmbedContent(array $embed): bool
  {
    $textFields = ['title', 'description', 'url'];
    foreach ($textFields as $field) {
      if (!empty($embed[$field]) && trim((string)$embed[$field]) !== '') {
        return true;
      }
    }

    if (!empty($embed['fields']) && is_array($embed['fields'])) {
      return true;
    }

    $nestedFields = ['author', 'footer', 'image', 'thumbnail', 'video', 'provider'];
    foreach ($nestedFields as $field) {
      if (!empty($embed[$field]) && is_array($embed[$field])) {
        return true;
      }
    }

    return false;
  }

  private function buildManualTestPayload(string $message): array
  {
    $appName = (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling');
    $content = $message !== '' ? $message : 'Test notification from hauling.';

    return [
      'username' => $appName,
      'content' => $content,
    ];
  }

  private function buildHaulRequestTestPayload(?array $request, string $fallbackTitle): array
  {
    if (!$request) {
      return $this->ensurePayload('haul.request.created', []);
    }

    $fromName = $this->resolveLocationName(
      (int)($request['from_location_id'] ?? 0),
      (string)($request['from_location_type'] ?? '')
    );
    $toName = $this->resolveLocationName(
      (int)($request['to_location_id'] ?? 0),
      (string)($request['to_location_type'] ?? '')
    );
    $title = trim((string)($request['title'] ?? ''));
    if ($title === '') {
      $title = $fallbackTitle . ' #' . (string)($request['request_id'] ?? '');
    }
    $route = $this->buildRoutePayload($fromName, $toName, (int)($request['expected_jumps'] ?? 0));

    return $this->buildHaulRequestEmbed([
      'title' => $title,
      'route' => $route,
      'from_system' => $fromName,
      'to_system' => $toName,
      'volume_m3' => (float)($request['volume_m3'] ?? 0.0),
      'collateral_isk' => (float)($request['collateral_isk'] ?? 0.0),
      'price_isk' => (float)($request['reward_isk'] ?? 0.0),
      'requester' => (string)($request['requester_character_name'] ?? $request['requester_display_name'] ?? 'Unknown'),
      'requester_character_id' => (int)($request['requester_character_id'] ?? 0),
      'ship_class' => (string)($request['ship_class'] ?? ''),
      'request_key' => (string)($request['request_key'] ?? ''),
    ]);
  }

  private function buildHaulQuoteTestPayload(int $corpId): array
  {
    $quote = $this->db->one(
      "SELECT quote_id, route_json, breakdown_json, volume_m3, collateral_isk, price_total
         FROM quote
        WHERE corp_id = :cid
        ORDER BY created_at DESC, quote_id DESC
        LIMIT 1",
      ['cid' => $corpId]
    );

    if (!$quote) {
      return $this->ensurePayload('haul.quote.created', []);
    }

    $route = Db::jsonDecode((string)($quote['route_json'] ?? ''), []);
    $breakdown = Db::jsonDecode((string)($quote['breakdown_json'] ?? ''), []);
    if (!is_array($route)) {
      $route = [];
    }
    if (!is_array($breakdown)) {
      $breakdown = [];
    }

    return $this->buildHaulRequestEmbed([
      'title' => 'Haul Quote #' . (string)($quote['quote_id'] ?? ''),
      'route' => $route,
      'volume_m3' => (float)($quote['volume_m3'] ?? 0.0),
      'collateral_isk' => (float)($quote['collateral_isk'] ?? 0.0),
      'price_isk' => (float)($quote['price_total'] ?? 0.0),
      'requester' => 'Quote Request',
      'ship_class' => (string)($breakdown['ship_class']['service_class'] ?? ''),
    ]);
  }

  private function buildContractAttachedTestPayload(?array $request): array
  {
    if (!$request) {
      return $this->ensurePayload('haul.contract.attached', []);
    }

    $fromName = $this->resolveLocationName(
      (int)($request['from_location_id'] ?? 0),
      (string)($request['from_location_type'] ?? '')
    );
    $toName = $this->resolveLocationName(
      (int)($request['to_location_id'] ?? 0),
      (string)($request['to_location_type'] ?? '')
    );
    $jumps = (int)($request['expected_jumps'] ?? 0);
    $routeLabel = trim(($fromName !== '' ? $fromName : 'Unknown') . ' → ' . ($toName !== '' ? $toName : 'Unknown'));
    $requestUrl = $this->buildRequestUrl((string)($request['request_key'] ?? ''));

    return [
      'username' => (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling'),
      'content' => sprintf(
        "New haul request #%s (Quote #%s)\n%s • %d jumps\nShip: %s • Volume: %s m³ • Price: %s ISK • Collateral: %s ISK\n%s",
        (string)($request['request_id'] ?? ''),
        (string)($request['quote_id'] ?? 'n/a'),
        $routeLabel,
        $jumps,
        (string)($request['ship_class'] ?? 'N/A'),
        number_format((float)($request['volume_m3'] ?? 0.0), 0),
        number_format((float)($request['reward_isk'] ?? 0.0), 2),
        number_format((float)($request['collateral_isk'] ?? 0.0), 2),
        $requestUrl
      ),
    ];
  }

  private function buildContractPickedUpTestPayload(?array $request): array
  {
    if (!$request) {
      return $this->ensurePayload('contract.picked_up', []);
    }

    $contractId = (int)($request['contract_id'] ?? $request['esi_contract_id'] ?? 0);
    $acceptorName = (string)($request['acceptor_name'] ?? $request['esi_acceptor_name'] ?? '');
    $acceptorId = (int)($request['acceptor_id'] ?? $request['esi_acceptor_id'] ?? 0);
    $route = $this->buildRouteLabel($request);

    return $this->buildContractPickedUpPayload([
      'request_id' => (int)($request['request_id'] ?? 0),
      'request_key' => (string)($request['request_key'] ?? ''),
      'route' => $route,
      'pickup' => (string)($request['from_name'] ?? ''),
      'dropoff' => (string)($request['to_name'] ?? ''),
      'ship_class' => (string)($request['ship_class'] ?? ''),
      'volume_m3' => (float)($request['volume_m3'] ?? 0.0),
      'collateral_isk' => (float)($request['collateral_isk'] ?? 0.0),
      'reward_isk' => (float)($request['reward_isk'] ?? 0.0),
      'contract_id' => $contractId,
      'acceptor_id' => $acceptorId,
      'acceptor_name' => $acceptorName,
    ]);
  }

  private function buildContractLifecycleTestPayload(?array $request, string $state): array
  {
    if (!$request) {
      return $this->ensurePayload('contract.' . $state, []);
    }

    $contractId = (int)($request['contract_id'] ?? $request['esi_contract_id'] ?? 0);
    $acceptorName = (string)($request['acceptor_name'] ?? $request['esi_acceptor_name'] ?? '');
    $acceptorId = (int)($request['acceptor_id'] ?? $request['esi_acceptor_id'] ?? 0);
    $route = $this->buildRouteLabel($request);

    $details = [
      'request_id' => (int)($request['request_id'] ?? 0),
      'request_key' => (string)($request['request_key'] ?? ''),
      'route' => $route,
      'pickup' => (string)($request['from_name'] ?? ''),
      'dropoff' => (string)($request['to_name'] ?? ''),
      'ship_class' => (string)($request['ship_class'] ?? ''),
      'volume_m3' => (float)($request['volume_m3'] ?? 0.0),
      'collateral_isk' => (float)($request['collateral_isk'] ?? 0.0),
      'reward_isk' => (float)($request['reward_isk'] ?? 0.0),
      'contract_id' => $contractId,
      'acceptor_id' => $acceptorId,
      'acceptor_name' => $acceptorName,
    ];

    return match ($state) {
      'delivered' => $this->buildContractDeliveredPayload($details),
      'failed' => $this->buildContractFailedPayload($details),
      'expired' => $this->buildContractExpiredPayload($details),
      default => $this->ensurePayload('contract.' . $state, []),
    };
  }

  private function buildAssignmentTestPayload(?array $assignment, string $status): array
  {
    if (!$assignment) {
      return $this->ensurePayload('haul.assignment.created', []);
    }

    $route = $this->buildRoutePayload(
      (string)($assignment['from_name'] ?? ''),
      (string)($assignment['to_name'] ?? ''),
      (int)($assignment['expected_jumps'] ?? 0)
    );

    return $this->buildHaulAssignmentPayload([
      'title' => 'Haul Assignment #' . (string)($assignment['request_id'] ?? ''),
      'route' => $route,
      'volume_m3' => (float)($assignment['volume_m3'] ?? 0.0),
      'reward_isk' => (float)($assignment['reward_isk'] ?? 0.0),
      'requester' => (string)($assignment['requester_character_name'] ?? $assignment['requester_display_name'] ?? 'Unknown'),
      'requester_character_id' => (int)($assignment['requester_character_id'] ?? 0),
      'hauler' => (string)($assignment['hauler_name'] ?? ''),
      'hauler_character_id' => (int)($assignment['hauler_character_id'] ?? 0),
      'status' => $status,
      'actor' => (string)($assignment['assigned_by_name'] ?? ''),
      'actor_label' => 'Assigned by',
      'actor_character_id' => (int)($assignment['assigned_by_character_id'] ?? 0),
      'ship_class' => (string)($assignment['ship_class'] ?? ''),
      'request_key' => (string)($assignment['request_key'] ?? ''),
    ]);
  }

  private function buildContractsPulledSummary(int $corpId): array
  {
    $contractCount = (int)($this->db->fetchValue(
      "SELECT COUNT(*) FROM esi_corp_contract WHERE corp_id = :cid",
      ['cid' => $corpId]
    ) ?? 0);
    $itemCount = (int)($this->db->fetchValue(
      "SELECT COUNT(*) FROM esi_corp_contract_item WHERE corp_id = :cid",
      ['cid' => $corpId]
    ) ?? 0);

    return [
      'fetched_contracts' => $contractCount,
      'upserted_contracts' => $contractCount,
      'upserted_items' => $itemCount,
      'pages' => $contractCount > 0 ? 1 : 0,
    ];
  }

  private function buildContractsReconciledSummary(int $corpId): array
  {
    $scanned = (int)($this->db->fetchValue(
      "SELECT COUNT(*)
         FROM haul_request
        WHERE corp_id = :cid
          AND (contract_id IS NOT NULL OR esi_contract_id IS NOT NULL)",
      ['cid' => $corpId]
    ) ?? 0);
    $updated = (int)($this->db->fetchValue(
      "SELECT COUNT(*)
         FROM haul_request
        WHERE corp_id = :cid
          AND contract_state IS NOT NULL",
      ['cid' => $corpId]
    ) ?? 0);

    return [
      'scanned' => $scanned,
      'updated' => $updated,
      'skipped' => max(0, $scanned - $updated),
      'not_found' => 0,
      'errors' => 0,
      'pages' => $scanned > 0 ? 1 : 0,
    ];
  }

  private function fetchLatestHaulRequest(int $corpId): ?array
  {
    return $this->db->one(
      "SELECT *
         FROM v_haul_request_display
        WHERE corp_id = :cid
        ORDER BY updated_at DESC, request_id DESC
        LIMIT 1",
      ['cid' => $corpId]
    );
  }

  private function fetchLatestContractRequest(int $corpId): ?array
  {
    return $this->db->one(
      "SELECT *
         FROM v_haul_request_display
        WHERE corp_id = :cid
          AND (contract_id IS NOT NULL OR esi_contract_id IS NOT NULL)
        ORDER BY updated_at DESC, request_id DESC
        LIMIT 1",
      ['cid' => $corpId]
    );
  }

  private function fetchLatestHaulAssignment(int $corpId): ?array
  {
    return $this->db->one(
      "SELECT v.*,
              a.status AS assignment_status,
              a.hauler_user_id,
              a.assigned_by_user_id,
              h.display_name AS hauler_name,
              h.character_id AS hauler_character_id,
              h.avatar_url AS hauler_avatar_url,
              b.display_name AS assigned_by_name,
              b.character_id AS assigned_by_character_id,
              b.avatar_url AS assigned_by_avatar_url
         FROM haul_assignment a
         JOIN v_haul_request_display v ON v.request_id = a.request_id
         LEFT JOIN app_user h ON h.user_id = a.hauler_user_id
         LEFT JOIN app_user b ON b.user_id = a.assigned_by_user_id
        WHERE v.corp_id = :cid
        ORDER BY a.updated_at DESC, a.assignment_id DESC
        LIMIT 1",
      ['cid' => $corpId]
    );
  }

  private function resolveLocationName(int $locationId, string $locationType): string
  {
    if ($locationId <= 0) {
      return '';
    }

    return match ($locationType) {
      'station' => trim((string)$this->db->fetchValue(
        "SELECT station_name FROM eve_station WHERE station_id = :id LIMIT 1",
        ['id' => $locationId]
      )),
      'structure' => trim((string)$this->db->fetchValue(
        "SELECT structure_name FROM eve_structure WHERE structure_id = :id LIMIT 1",
        ['id' => $locationId]
      )),
      default => trim((string)$this->db->fetchValue(
        "SELECT system_name FROM eve_system WHERE system_id = :id LIMIT 1",
        ['id' => $locationId]
      )),
    };
  }

  private function buildRoutePayload(string $fromName, string $toName, int $jumps): array
  {
    $path = [];
    if ($fromName !== '') {
      $path[] = ['system_name' => $fromName];
    }
    if ($toName !== '') {
      $path[] = ['system_name' => $toName];
    }

    return [
      'path' => $path,
      'jumps' => $jumps,
      'hs_count' => 0,
      'ls_count' => 0,
      'ns_count' => 0,
    ];
  }

  private function buildRouteLabel(array $request): string
  {
    $fromName = (string)($request['from_name'] ?? '');
    $toName = (string)($request['to_name'] ?? '');
    if ($fromName === '') {
      $fromName = $this->resolveLocationName(
        (int)($request['from_location_id'] ?? 0),
        (string)($request['from_location_type'] ?? '')
      );
    }
    if ($toName === '') {
      $toName = $this->resolveLocationName(
        (int)($request['to_location_id'] ?? 0),
        (string)($request['to_location_type'] ?? '')
      );
    }

    $fromLabel = $fromName !== '' ? $fromName : ('Location #' . (string)($request['from_location_id'] ?? ''));
    $toLabel = $toName !== '' ? $toName : ('Location #' . (string)($request['to_location_id'] ?? ''));
    return trim($fromLabel . ' → ' . $toLabel);
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
