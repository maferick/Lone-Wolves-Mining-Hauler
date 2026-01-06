<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Services\DiscordOutboxErrorHelp;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'webhook.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);

$discordEventOptions = [
  'request.created' => 'Request created',
  'request.status_changed' => 'Request status changed',
  'contract.matched' => 'Contract matched',
  'contract.picked_up' => 'Contract picked up',
  'contract.completed' => 'Contract completed',
  'contract.failed' => 'Contract failed',
  'contract.expired' => 'Contract expired',
  'alert.system' => 'System alert',
  'discord.test' => 'Discord test',
];
$outboxEventLabels = array_merge($discordEventOptions, [
  'discord.bot.permissions_test' => 'Bot permissions test',
  'discord.commands.register' => 'Register slash commands',
  'discord.bot.test_message' => 'Bot test message',
  'discord.roles.sync_user' => 'Role sync',
  'discord.thread.create' => 'Thread create',
  'discord.thread.complete' => 'Thread complete',
]);

$renderOutboxErrorHelp = static function (string $errorKey, array $details): string {
  $playbook = DiscordOutboxErrorHelp::resolve($errorKey)
    ?? DiscordOutboxErrorHelp::resolve('discord.unknown_error');
  if (!$playbook) {
    return '';
  }

  $detailParts = [];
  if (!empty($details['http_status'])) {
    $detailParts[] = 'HTTP ' . (int)$details['http_status'];
  }
  if (!empty($details['discord_code'])) {
    $detailParts[] = 'Discord code ' . (int)$details['discord_code'];
  }
  if (!empty($details['message'])) {
    $detailParts[] = (string)$details['message'];
  }
  $detailLine = $detailParts !== [] ? implode(' • ', $detailParts) : 'Discord error details unavailable.';

  ob_start();
  ?>
  <div class="card" style="padding:10px; background:rgba(255,255,255,0.02);">
    <div style="font-weight:600;"><?= htmlspecialchars((string)($playbook['title'] ?? 'Discord error help'), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="muted" style="margin-top:4px;"><?= htmlspecialchars($detailLine, ENT_QUOTES, 'UTF-8') ?></div>
    <div style="margin-top:8px;">
      <div class="label" style="font-size:12px;">What this means</div>
      <div><?= htmlspecialchars((string)($playbook['meaning'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div style="margin-top:8px;">
      <div class="label" style="font-size:12px;">Most common causes</div>
      <ul style="margin:6px 0 0 18px;">
        <?php foreach (($playbook['causes'] ?? []) as $cause): ?>
          <li><?= htmlspecialchars((string)$cause, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div style="margin-top:8px;">
      <div class="label" style="font-size:12px;">How to fix</div>
      <ul style="margin:6px 0 0 18px;">
        <?php foreach (($playbook['fix'] ?? []) as $fix): ?>
          <li>☐ <?= htmlspecialchars((string)$fix, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php if (!empty($playbook['verify'])): ?>
      <div style="margin-top:8px;">
        <div class="label" style="font-size:12px;">How to verify</div>
        <ul style="margin:6px 0 0 18px;">
          <?php foreach (($playbook['verify'] ?? []) as $verify): ?>
            <li><?= htmlspecialchars((string)$verify, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <?php
  return (string)ob_get_clean();
};

$db->execute(
  "DELETE o FROM discord_outbox o
    LEFT JOIN (
      SELECT outbox_id
        FROM discord_outbox
       WHERE corp_id = :cid
       ORDER BY outbox_id DESC
       LIMIT 15
    ) keep_rows ON keep_rows.outbox_id = o.outbox_id
   WHERE o.corp_id = :cid AND keep_rows.outbox_id IS NULL",
  ['cid' => $corpId]
);

$pendingCount = (int)($db->fetchValue(
  "SELECT COUNT(*) FROM discord_outbox WHERE corp_id = :cid AND status IN ('queued','failed','sending')",
  ['cid' => $corpId]
) ?? 0);
$outboxRows = $db->select(
  "SELECT outbox_id, event_key, status, attempts, next_attempt_at, last_error, created_at, sent_at, payload_json
     FROM discord_outbox
    WHERE corp_id = :cid
    ORDER BY outbox_id DESC
    LIMIT 15",
  ['cid' => $corpId]
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require __DIR__ . '/../../../../src/Views/partials/admin/discord_outbox.php';
