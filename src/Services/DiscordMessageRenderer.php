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
    if ($footer !== '') {
      $embed['footer'] = ['text' => $footer];
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
    ];

    if (!empty($payload['content'])) {
      $message['content'] = $this->clip((string)$payload['content'], 2000);
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
        'title' => 'New Hauling Request #{request_id}',
        'body' => "Pickup: {pickup}\nDelivery: {delivery}\nVolume: {volume}\nCollateral: {collateral}\nReward: {reward}\nPriority: {priority}\nStatus: {status}\n{link_request}",
        'footer' => 'Requested by {user}',
      ],
      'request.status_changed' => [
        'title' => 'Request #{request_id} status updated',
        'body' => "Status: {status}\nPickup: {pickup}\nDelivery: {delivery}\n{link_request}",
        'footer' => 'Updated by {user}',
      ],
      'contract.matched' => [
        'title' => 'Contract matched for request #{request_id}',
        'body' => "Contract ID: {contract_id}\nRoute: {pickup} → {delivery}\nReward: {reward}\nCollateral: {collateral}\n{link_request}",
        'footer' => 'Awaiting pickup',
      ],
      'contract.picked_up' => [
        'title' => 'Contract picked up for request #{request_id}',
        'body' => "Hauler: {hauler}\nRoute: {pickup} → {delivery}\nReward: {reward}\n{link_request}",
        'footer' => 'In transit',
      ],
      'contract.completed' => [
        'title' => 'Contract completed for request #{request_id}',
        'body' => "Hauler: {hauler}\nRoute: {pickup} → {delivery}\nReward: {reward}\n{link_request}",
        'footer' => 'Completed',
      ],
      'alert.system' => [
        'title' => '⚠️ System alert',
        'body' => "{message}\n{link_request}",
        'footer' => '{reason}',
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
}
