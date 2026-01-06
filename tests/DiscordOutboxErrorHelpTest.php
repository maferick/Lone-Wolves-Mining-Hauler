<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/DiscordOutboxErrorHelp.php';

use App\Services\DiscordOutboxErrorHelp;

$cases = [
  [
    'error' => 'HTTP 403 | Response: {"message":"Missing Access","code":50001}',
    'expected' => 'discord.missing_access',
  ],
  [
    'error' => 'HTTP 401 Unauthorized',
    'expected' => 'discord.invalid_token',
  ],
  [
    'error' => 'HTTP 404 | Response: {"message":"Unknown interaction","code":10062}',
    'expected' => 'discord.unknown_interaction',
  ],
  [
    'error' => 'HTTP 403 | Response: {"message":"Missing Permissions","code":50013}',
    'expected' => 'discord.missing_permissions',
  ],
  [
    'error' => 'HTTP 429 | Response: {"message":"You are being rate limited.","code":20028}',
    'expected' => 'discord.rate_limited',
  ],
  [
    'error' => 'HTTP 404 | Response: {"message":"Unknown Webhook","code":10015}',
    'expected' => 'discord.webhook_not_found',
  ],
  [
    'error' => 'HTTP 404 | Response: {"message":"Unknown Channel","code":10003}',
    'expected' => 'discord.unknown_channel',
  ],
];

foreach ($cases as $case) {
  $normalized = DiscordOutboxErrorHelp::normalize($case['error']);
  if (($normalized['error_key'] ?? '') !== $case['expected']) {
    throw new RuntimeException(
      'Expected ' . $case['expected'] . ' for error "' . $case['error'] . '" but got ' . ($normalized['error_key'] ?? 'null')
    );
  }
}

if (!DiscordOutboxErrorHelp::resolve('discord.missing_access')) {
  throw new RuntimeException('Expected missing access playbook to resolve.');
}

echo "DiscordOutboxErrorHelp tests passed.\n";
