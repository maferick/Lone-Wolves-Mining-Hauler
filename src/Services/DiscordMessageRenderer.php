<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class DiscordMessageRenderer
{
  private const TITLE_LIMIT = 256;
  private const DESCRIPTION_LIMIT = 4096;
  private const FOOTER_LIMIT = 2048;

  public function __construct(private Db $db, private array $config = [])
  {
  }

  public function render(int $corpId, string $eventKey, array $payload): array
  {
    $payload = $this->enrichPayload($corpId, $payload);
    $template = $this->loadTemplate($corpId, $eventKey);
    $title = $this->renderTemplate($template['title'] ?? '', $payload);
    $body = $this->renderTemplate($template['body'] ?? '', $payload);
    $footer = $this->renderTemplate($template['footer'] ?? '', $payload);

    $embed = [];
    $title = $this->clip($title, self::TITLE_LIMIT);
    $body = $this->clip($body, self::DESCRIPTION_LIMIT);
    $footer = $this->clip($footer, self::FOOTER_LIMIT);

    if ($title !== '') {
      $embed['title'] = $title;
    }
    if ($body !== '') {
      $embed['description'] = $body;
    }
    $authorName = trim((string)($payload['requester'] ?? $payload['user'] ?? ''));
    $requesterPortrait = trim((string)($payload['requester_portrait'] ?? ''));
    $haulerPortrait = trim((string)($payload['hauler_portrait'] ?? ''));
    $shipRender = trim((string)($payload['ship_render'] ?? ''));
    $linkRequest = trim((string)($payload['link_request'] ?? ''));

    if ($authorName !== '') {
      $embed['author'] = ['name' => $this->clip($authorName, 256)];
      if ($requesterPortrait !== '') {
        $embed['author']['icon_url'] = $requesterPortrait;
      }
    }
    if ($shipRender !== '') {
      $embed['thumbnail'] = ['url' => $shipRender];
    }
    if ($footer !== '') {
      $embed['footer'] = ['text' => $footer];
      if ($haulerPortrait !== '') {
        $embed['footer']['icon_url'] = $haulerPortrait;
      }
    }
    if ($linkRequest !== '') {
      $embed['url'] = $linkRequest;
    }

    if (!empty($payload['fields']) && is_array($payload['fields'])) {
      $fields = [];
      foreach ($payload['fields'] as $field) {
        if (!is_array($field)) {
          continue;
        }
        $name = $this->clip(trim((string)($field['name'] ?? '')), 256);
        $value = $this->clip(trim((string)($field['value'] ?? '')), 1024);
        if ($name === '' || $value === '') {
          continue;
        }
        $fields[] = [
          'name' => $name,
          'value' => $value,
          'inline' => !empty($field['inline']),
        ];
      }
      if ($fields !== []) {
        $embed['fields'] = $fields;
      }
    }

    if ($embed !== []) {
      $embed['timestamp'] = gmdate('c');
    }

    $message = [
      'username' => (string)($this->config['app']['name'] ?? 'Lone Wolves Hauling'),
      'allowed_mentions' => ['parse' => []],
    ];

    if (!empty($payload['content'])) {
      $message['content'] = $this->clip((string)$payload['content'], 2000);
    } elseif ($embed !== []) {
      $message['content'] = '';
    }
    if ($embed !== []) {
      $message['embeds'] = [$embed];
    }

    return $message;
  }

  public function renderPreview(int $corpId, string $eventKey, array $payload): array
  {
    return $this->render($corpId, $eventKey, $payload);
  }

  public function defaultTemplates(array $eventKeys): array
  {
    $defaults = [];
    foreach ($eventKeys as $eventKey) {
      $defaults[$eventKey] = $this->defaultTemplate((string)$eventKey);
    }
    return $defaults;
  }

  private function loadTemplate(int $corpId, string $eventKey): array
  {
    $row = $this->db->one(
      "SELECT title_template, body_template, footer_template
         FROM discord_template
        WHERE corp_id = :cid AND event_key = :event_key
        LIMIT 1",
      [
        'cid' => $corpId,
        'event_key' => $eventKey,
      ]
    );

    if ($row) {
      return [
        'title' => (string)($row['title_template'] ?? ''),
        'body' => (string)($row['body_template'] ?? ''),
        'footer' => (string)($row['footer_template'] ?? ''),
      ];
    }

    return $this->defaultTemplate($eventKey);
  }

  private function defaultTemplate(string $eventKey): array
  {
    return match ($eventKey) {
      'request.created' => [
        'title' => '🚚 New hauling request created — #{request_code}',
        'body' => "**Pickup:** {pickup}\n**Delivery:** {delivery}\n**Volume:** {volume} m³\n**Collateral:** {collateral} ISK\n**Estimated reward:** {reward} ISK\n**Priority:** {priority}\n**Requested by:** {requester}\n\nOpen: {link_request}",
        'footer' => 'Lone Wolves Mining • Hauling Ops',
      ],
      'request.status_changed' => [
        'title' => '🔄 Request update — #{request_code}',
        'body' => "**Status:** {status}\n**Pickup:** {pickup}\n**Delivery:** {delivery}\n\nDetails: {link_request}",
        'footer' => 'Ops tracking • Auto update',
      ],
      'contract.matched' => [
        'title' => '📎 Contract matched — #{request_code}',
        'body' => "Contract has been linked to this request.\n\n**Pickup:** {pickup}\n**Delivery:** {delivery}\n**Reward:** {reward} ISK\n**Collateral:** {collateral} ISK\n\nInstructions: {link_contract_instructions}",
        'footer' => 'Dispatch: validate contract details',
      ],
      'contract.picked_up' => [
        'title' => '✅ Picked up — #{request_code}',
        'body' => "A hauler has accepted the contract.\n\n**Pickup:** {pickup}\n**Delivery:** {delivery}\n**Volume:** {volume} m³\n**Priority:** {priority}\n\nTrack: {link_request}",
        'footer' => 'In transit • Fly smart',
      ],
      'contract.completed' => [
        'title' => '🎉 Delivered — #{request_code}',
        'body' => "Contract delivered and marked completed.\n\n**Pickup:** {pickup}\n**Delivery:** {delivery}\n**Volume:** {volume} m³\n**Reward:** {reward} ISK\n\nDetails: {link_request}",
        'footer' => 'Completed • Good work',
      ],
      'contract.failed' => [
        'title' => '⚠️ Contract failed — #{request_code}',
        'body' => "Contract failed / rejected / cancelled.\n\n**Pickup:** {pickup}\n**Delivery:** {delivery}\n**Status:** {status}\n\nReview: {link_request}",
        'footer' => 'Dispatch action required',
      ],
      'contract.expired' => [
        'title' => '⏰ Contract expired — #{request_code}',
        'body' => "The contract expired before completion.\n\n**Pickup:** {pickup}\n**Delivery:** {delivery}\n**Status:** {status}\n\nReview: {link_request}",
        'footer' => 'Ops follow-up needed',
      ],
      'alert.system' => [
        'title' => '🚨 System alert — Hauling platform',
        'body' => "An operational alert was triggered.\n\n**Status:** {status}\n**Context:** {request_code}\n**User:** {user}\n\nAdmins should investigate promptly.",
        'footer' => 'Lone Wolves Mining • System Monitoring',
      ],
      'discord.test' => [
        'title' => 'Discord test message',
        'body' => "Event: {event_key}\nRequest: {request_id}\n{link_request}",
        'footer' => 'Preview',
      ],
      default => [
        'title' => 'Discord notification',
        'body' => "Event: {event_key}\n{message}\n{link_request}",
        'footer' => '{status}',
      ],
    };
  }

  private function renderTemplate(string $template, array $payload): string
  {
    if ($template === '') {
      return '';
    }

    return (string)preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use ($payload) {
      $key = $matches[1] ?? '';
      if ($key === '') {
        return '';
      }
      $value = $payload[$key] ?? '';
      if (is_array($value)) {
        $value = Db::jsonEncode($value);
      }
      return $this->sanitize((string)$value);
    }, $template);
  }

  private function sanitize(string $value): string
  {
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return $value;
  }

  private function clip(string $value, int $limit): string
  {
    if ($value === '') {
      return '';
    }
    if (mb_strlen($value) <= $limit) {
      return $value;
    }
    return mb_substr($value, 0, $limit - 3) . '...';
  }

  private function enrichPayload(int $corpId, array $payload): array
  {
    $requestId = (int)($payload['request_id'] ?? 0);
    $requesterCharacterId = (int)($payload['requester_character_id'] ?? 0);
    $haulerCharacterId = (int)($payload['hauler_character_id'] ?? 0);
    $shipTypeId = (int)($payload['ship_type_id'] ?? 0);
    $shipClass = trim((string)($payload['ship_class'] ?? ''));

    if ($requestId > 0 && ($requesterCharacterId <= 0 || $haulerCharacterId <= 0 || $shipClass === '' || empty($payload['requester']))) {
      $row = $this->db->one(
        "SELECT r.request_id, r.request_key, r.ship_class, r.requester_character_id, r.requester_character_name,
                r.acceptor_id, r.acceptor_name, r.esi_acceptor_id, r.esi_acceptor_name,
                r.contract_acceptor_id, r.contract_acceptor_name,
                u.display_name AS requester_display_name, u.character_id AS user_character_id, u.character_name AS user_character_name
           FROM haul_request r
           JOIN app_user u ON u.user_id = r.requester_user_id
          WHERE r.request_id = :rid AND r.corp_id = :cid
          LIMIT 1",
        [
          'rid' => $requestId,
          'cid' => $corpId,
        ]
      );

      if ($row) {
        if (empty($payload['request_code'])) {
          $payload['request_code'] = (string)($row['request_key'] ?? '');
        }
        if ($shipClass === '') {
          $shipClass = (string)($row['ship_class'] ?? '');
          $payload['ship_class'] = $shipClass;
        }
        if ($requesterCharacterId <= 0) {
          $requesterCharacterId = (int)($row['requester_character_id'] ?? 0);
          if ($requesterCharacterId <= 0) {
            $requesterCharacterId = (int)($row['user_character_id'] ?? 0);
          }
          $payload['requester_character_id'] = $requesterCharacterId;
        }
        if ($haulerCharacterId <= 0) {
          $haulerCharacterId = (int)($row['esi_acceptor_id'] ?? 0);
          if ($haulerCharacterId <= 0) {
            $haulerCharacterId = (int)($row['contract_acceptor_id'] ?? 0);
          }
          if ($haulerCharacterId <= 0) {
            $haulerCharacterId = (int)($row['acceptor_id'] ?? 0);
          }
          $payload['hauler_character_id'] = $haulerCharacterId;
        }
        if (empty($payload['requester'])) {
          $payload['requester'] = (string)($row['requester_character_name'] ?? $row['user_character_name'] ?? $row['requester_display_name'] ?? '');
        }
        if (empty($payload['user'])) {
          $payload['user'] = (string)($row['user_character_name'] ?? $row['requester_display_name'] ?? '');
        }
        if (empty($payload['hauler'])) {
          $payload['hauler'] = (string)($row['esi_acceptor_name'] ?? $row['contract_acceptor_name'] ?? $row['acceptor_name'] ?? '');
        }
      }
    }

    $requesterName = trim((string)($payload['requester'] ?? ''));
    if ($requesterName === '') {
      $requesterName = trim((string)($payload['user'] ?? ''));
    }
    if ($requesterName !== '') {
      $payload['requester'] = $requesterName;
    }

    $haulerName = trim((string)($payload['hauler'] ?? ''));
    if ($haulerName === '') {
      $payload['hauler'] = 'Unassigned';
    }

    $payload['requester_portrait'] = $this->buildCharacterPortraitUrl($requesterCharacterId);
    $payload['hauler_portrait'] = $this->buildCharacterPortraitUrl($haulerCharacterId);
    $payload['ship_render'] = $this->buildTypeRenderUrl($shipTypeId);

    $shipName = trim((string)($payload['ship_name'] ?? ''));
    if ($shipName === '' && $shipTypeId > 0 && $requestId > 0) {
      $shipName = (string)($this->db->fetchValue(
        "SELECT type_name FROM haul_request_item WHERE request_id = :rid AND type_id = :type_id LIMIT 1",
        [
          'rid' => $requestId,
          'type_id' => $shipTypeId,
        ]
      ) ?? '');
    }
    if ($shipName === '') {
      $shipName = $this->formatShipClassLabel($shipClass);
    }
    if ($shipName === '') {
      $shipName = 'Unknown ship';
    }
    $payload['ship_name'] = $shipName;

    return $payload;
  }

  private function buildCharacterPortraitUrl(int $characterId): string
  {
    if ($characterId <= 0) {
      return '';
    }
    return 'https://images.evetech.net/characters/' . $characterId . '/portrait?size=64';
  }

  private function buildTypeRenderUrl(int $typeId): string
  {
    if ($typeId <= 0) {
      return '';
    }
    return 'https://images.evetech.net/types/' . $typeId . '/render?size=64';
  }

  private function formatShipClassLabel(string $shipClass): string
  {
    $normalized = strtoupper(trim($shipClass));
    $labels = [
      'BR' => 'Blockade Runner',
      'DST' => 'Deep Space Transport',
      'FREIGHTER' => 'Freighter',
      'JF' => 'Jump Freighter',
    ];

    return $labels[$normalized] ?? $shipClass;
  }
}
