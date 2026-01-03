<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../../src/bootstrap.php';

api_require_key();

$payload = api_read_json();

// Accept either JSON body or query params
$corpId = (int)($payload['corp_id'] ?? ($_GET['corp_id'] ?? 0));
$charId = (int)($payload['character_id'] ?? ($_GET['character_id'] ?? 0));

if ($corpId <= 0 || $charId <= 0) {
  api_send_json([
    'ok' => false,
    'error' => 'corp_id and character_id are required',
    'example' => ['corp_id' => 98746727, 'character_id' => 123456789],
  ], 400);
}

try {
  /** @var \App\Services\EsiService $esi */
  $esi = $services['esi'];
  $result = $db->tx(fn($db) => $esi->contracts()->pull($corpId, $charId));
  $reconcile = $esi->contracts()->reconcileLinkedRequests($corpId, $charId);

  if (!empty($services['discord_webhook'])) {
    try {
      /** @var \App\Services\DiscordWebhookService $webhooks */
      $webhooks = $services['discord_webhook'];
      $discordPayload = $webhooks->buildContractsPulledPayload($result);
      $webhooks->enqueue($corpId, 'esi.contracts.pulled', $discordPayload);
    } catch (\Throwable $e) {
      // Ignore webhook enqueue failures.
    }
    try {
      $discordPayload = $webhooks->buildContractsReconciledPayload($reconcile);
      $webhooks->enqueue($corpId, 'esi.contracts.reconciled', $discordPayload);
    } catch (\Throwable $e) {
      // Ignore webhook enqueue failures.
    }
  }
  api_send_json(['ok' => true, 'result' => $result, 'reconcile' => $reconcile]);
} catch (Throwable $e) {
  api_send_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
