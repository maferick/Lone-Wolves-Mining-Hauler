<?php
declare(strict_types=1);

namespace App\Services;

final class DiscordOutboxErrorHelp
{
  public static function normalize(string $errorText): array
  {
    $errorText = trim($errorText);
    $httpStatus = null;
    $discordCode = null;
    $message = null;
    $responseBody = null;

    if (preg_match('/HTTP\\s+(\\d{3})/i', $errorText, $matches)) {
      $httpStatus = (int)$matches[1];
    }

    if ($httpStatus === null && preg_match('/\\b(401|403|404|429|5\\d{2})\\b/', $errorText, $matches)) {
      $httpStatus = (int)$matches[1];
    }

    $responsePos = stripos($errorText, 'Response:');
    if ($responsePos !== false) {
      $responseBody = trim(substr($errorText, $responsePos + strlen('Response:')));
      $decoded = json_decode($responseBody, true);
      if (is_array($decoded)) {
        if (isset($decoded['code'])) {
          $discordCode = (int)$decoded['code'];
        }
        if (isset($decoded['message'])) {
          $message = trim((string)$decoded['message']);
        }
      }
    }

    if ($message === null) {
      if (stripos($errorText, 'Unknown interaction') !== false) {
        $message = 'Unknown interaction';
      } elseif (stripos($errorText, 'Missing Permissions') !== false) {
        $message = 'Missing Permissions';
      } elseif (stripos($errorText, 'Unknown Webhook') !== false) {
        $message = 'Unknown Webhook';
      } elseif (stripos($errorText, 'Unknown Channel') !== false) {
        $message = 'Unknown Channel';
      } elseif (stripos($errorText, 'Unauthorized') !== false) {
        $message = 'Unauthorized';
      }
    }

    $errorKey = self::selectErrorKey($httpStatus, $discordCode, $message, $errorText);

    return [
      'provider' => 'discord',
      'http_status' => $httpStatus,
      'discord_code' => $discordCode,
      'error_key' => $errorKey,
      'message' => $message,
      'response_body' => $responseBody,
      'raw' => $errorText,
    ];
  }

  public static function playbooks(): array
  {
    $channelNotFound = [
      'title' => 'Channel not found',
      'meaning' => 'Discord cannot find the channel ID referenced by this delivery.',
      'causes' => [
        'Channel deleted or moved.',
        'Channel ID typo or stale mapping.',
        'Bot lacks access to the channel.',
      ],
      'fix' => [
        'Confirm the channel ID in the Discord channel map.',
        'Ensure the bot still has access to the channel.',
        'Recreate the channel and update the mapping if it was deleted.',
      ],
    ];

    return [
      'discord.missing_access' => [
        'title' => 'Missing Access (403)',
        'meaning' => 'The bot token is valid, but Discord blocked access to the target guild, role, or member.',
        'causes' => [
          'Bot is not in the guild.',
          'Bot role lacks Manage Roles or required permissions.',
          'Role hierarchy puts the target role above the bot role.',
          'Guild ID or role IDs are incorrect.',
        ],
        'fix' => [
          'Invite the bot to the server if it is missing.',
          'Grant the bot role Manage Roles permission.',
          'Move the bot role above the mapped roles.',
          'Confirm guild_id and role IDs match your Discord server.',
          'Run the updated Bot Permissions Test.',
        ],
        'verify' => [
          'Bot Permissions Test shows guild + role checks passing.',
        ],
      ],
      'discord.invalid_token' => [
        'title' => 'Invalid Token (401 Unauthorized)',
        'meaning' => 'Discord rejected the bot token for this request.',
        'causes' => [
          'Token was rotated or revoked.',
          'Environment config not updated after rotation.',
          'Cached config or PHP-FPM not reloaded.',
        ],
        'fix' => [
          'Update the bot token in the environment config.',
          'Reload PHP-FPM or the app container.',
          'Verify the token in the admin UI.',
          'Run the token test endpoint.',
        ],
        'verify' => [
          'Bot Permissions Test token check passes.',
        ],
      ],
      'discord.unknown_interaction' => [
        'title' => 'Unknown Interaction (404)',
        'meaning' => 'The interaction token expired or the initial response exceeded Discord’s 3-second window.',
        'causes' => [
          'Slash command handler did not ACK within 3 seconds.',
          'Slow work done before responding.',
          'Proxy buffering delayed the response.',
        ],
        'fix' => [
          'Send an ACK or deferral within 3 seconds.',
          'Move heavy work after the initial response.',
          'Use follow-up webhooks for long-running tasks.',
          'Disable buffering that delays interaction responses.',
        ],
        'verify' => [
          'Interaction tests respond within 3 seconds.',
        ],
      ],
      'discord.missing_permissions' => [
        'title' => 'Missing Permissions (403)',
        'meaning' => 'The bot is in the guild but lacks permissions for the specific action.',
        'causes' => [
          'Bot role lacks Send Messages, Create Threads, or Embed Links.',
          'Channel permission overrides deny access.',
        ],
        'fix' => [
          'Grant the required permissions to the bot role.',
          'Check channel-specific permission overrides.',
        ],
        'verify' => [
          'Bot Permissions Test passes and channel actions succeed.',
        ],
      ],
      'discord.rate_limited' => [
        'title' => 'Rate Limited (429)',
        'meaning' => 'Discord rate limits were hit for this endpoint.',
        'causes' => [
          'Burst of messages or role sync operations.',
          'Queue concurrency too high.',
        ],
        'fix' => [
          'Respect Retry-After headers.',
          'Add backoff or throttling in the queue.',
          'Increase dedupe window or lower messages/minute.',
        ],
      ],
      'discord.webhook_not_found' => [
        'title' => 'Webhook not found (404)',
        'meaning' => 'The Discord webhook URL no longer exists or was deleted.',
        'causes' => [
          'Webhook removed in Discord.',
          'Webhook URL rotated without updating the portal.',
        ],
        'fix' => [
          'Recreate the webhook in Discord.',
          'Update the webhook URL in the channel map.',
          'Resend the failed delivery.',
        ],
      ],
      'discord.unknown_channel' => $channelNotFound,
      'discord.channel_not_found' => $channelNotFound,
      'discord.unknown_error' => [
        'title' => 'Discord delivery failed',
        'meaning' => 'Discord returned an error that does not have a dedicated playbook yet.',
        'causes' => [
          'Unexpected Discord API response.',
          'Temporary network or server issue.',
        ],
        'fix' => [
          'Check the error response details.',
          'Retry after a short delay.',
          'Add a playbook entry for this error if it repeats.',
        ],
      ],
    ];
  }

  public static function resolve(string $errorKey): ?array
  {
    $playbooks = self::playbooks();
    return $playbooks[$errorKey] ?? null;
  }

  private static function selectErrorKey(
    ?int $httpStatus,
    ?int $discordCode,
    ?string $message,
    string $errorText
  ): string {
    $message = $message ?? '';
    if ($httpStatus === 403 && $discordCode === 50001) {
      return 'discord.missing_access';
    }
    if ($httpStatus === 401 || stripos($errorText, '401 Unauthorized') !== false || stripos($message, 'Unauthorized') !== false) {
      return 'discord.invalid_token';
    }
    if ($httpStatus === 404 && stripos($message, 'Unknown interaction') !== false) {
      return 'discord.unknown_interaction';
    }
    if ($httpStatus === 403 && ($discordCode === 50013 || stripos($message, 'Missing Permissions') !== false)) {
      return 'discord.missing_permissions';
    }
    if ($httpStatus === 429) {
      return 'discord.rate_limited';
    }
    if ($httpStatus === 404 && ($discordCode === 10015 || stripos($message, 'Unknown Webhook') !== false)) {
      return 'discord.webhook_not_found';
    }
    if ($httpStatus === 404 && ($discordCode === 10003 || stripos($message, 'Unknown Channel') !== false)) {
      return 'discord.unknown_channel';
    }
    return 'discord.unknown_error';
  }
}
