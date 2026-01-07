<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$requests = $requests ?? [];
$requestsAvailable = $requestsAvailable ?? false;
$queuePositions = $queuePositions ?? [];
$queueTotal = $queueTotal ?? 0;
$corpName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');

$buildRouteLabel = static function (array $req): string {
  $fromName = $req['from_name'] ?? $req['from_location_name'] ?? null;
  $toName = $req['to_name'] ?? $req['to_location_name'] ?? null;
  if (!$fromName && !empty($req['from_location_id'])) {
    $fromName = 'Location #' . (string)$req['from_location_id'];
  }
  if (!$toName && !empty($req['to_location_id'])) {
    $toName = 'Location #' . (string)$req['to_location_id'];
  }
  return trim((string)$fromName) . ' → ' . trim((string)$toName);
};

$buildContractLabel = static function (array $req): string {
  $status = (string)($req['status'] ?? '');
  $contractId = (int)($req['esi_contract_id'] ?? $req['contract_id'] ?? 0);
  $contractStatus = trim((string)($req['esi_status'] ?? $req['contract_status_esi'] ?? $req['contract_status'] ?? ''));
  $label = $contractId > 0 ? '#' . (string)$contractId : '—';
  if ($status === 'contract_mismatch') {
    $mismatch = [];
    if (!empty($req['mismatch_reason_json'])) {
      $decoded = json_decode((string)$req['mismatch_reason_json'], true);
      if (is_array($decoded)) {
        $mismatch = $decoded['mismatches'] ?? $decoded;
      }
    }
    $keys = is_array($mismatch) ? array_keys($mismatch) : [];
    $reason = $keys ? implode(', ', $keys) : 'mismatch';
    return 'Mismatch ' . $label . ' (' . $reason . ')';
  }
  if ($contractId > 0) {
    return 'Linked ' . $label . ($contractStatus !== '' ? ' (' . $contractStatus . ')' : '');
  }
  return '—';
};

$buildStatusLabel = static function (array $req): string {
  $state = strtoupper(trim((string)($req['contract_lifecycle'] ?? $req['contract_state'] ?? '')));
  $acceptorName = trim((string)($req['esi_acceptor_name'] ?? ''));
  return match ($state) {
    'PICKED_UP' => $acceptorName !== '' ? 'Picked up by ' . $acceptorName : 'Picked up / En route',
    'DELIVERED' => 'Delivered',
    'FAILED' => 'Failed',
    'EXPIRED' => 'Expired',
    default => (string)($req['status'] ?? ''),
  };
};

$isPreviousHaul = static function (array $req): bool {
  $state = strtoupper(trim((string)($req['contract_lifecycle'] ?? $req['contract_state'] ?? '')));
  if (in_array($state, ['FAILED', 'EXPIRED', 'DELIVERED'], true)) {
    return true;
  }
  $status = strtolower(trim((string)($req['status'] ?? '')));
  return in_array($status, ['completed', 'cancelled', 'delivered', 'expired', 'rejected'], true);
};

$buildQueuePosition = static function (array $req) use ($queuePositions, $queueTotal, $isPreviousHaul): string {
  if ($isPreviousHaul($req)) {
    return '—';
  }
  $requestId = (int)($req['request_id'] ?? 0);
  if ($requestId <= 0 || !isset($queuePositions[$requestId])) {
    return '—';
  }
  $position = (int)$queuePositions[$requestId];
  if ($queueTotal > 0) {
    return $position . ' of ' . $queueTotal;
  }
  return (string)$position;
};

ob_start();
$activeRequests = array_values(array_filter(
  $requests,
  static fn(array $req): bool => !$isPreviousHaul($req)
));
$previousRequests = array_values(array_filter(
  $requests,
  static fn(array $req): bool => $isPreviousHaul($req)
));
?>
<section class="card js-my-contracts" data-base-path="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>">
  <div class="card-header">
    <h2>My Contracts</h2>
    <p class="muted">Track the status and queue position of your haul requests with <?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?>.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <p class="muted">Sign in to view your haul requests.</p>
    <?php elseif (!$requestsAvailable || (!$activeRequests && !$previousRequests)): ?>
      <p class="muted">No haul requests yet. Once you submit a request, it will appear here.</p>
    <?php else: ?>
      <?php
        $renderTable = static function (array $items) use ($basePath, $buildRouteLabel, $buildStatusLabel, $buildContractLabel, $buildQueuePosition, $isPreviousHaul): void {
      ?>
        <table class="table">
          <thead>
            <tr>
              <th>Request</th>
              <th>Route</th>
              <th>Status</th>
              <th>Contract</th>
              <th>Queue position</th>
              <th>Volume</th>
              <th>Reward</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $req): ?>
              <?php
                $requestId = (int)($req['request_id'] ?? 0);
                $requestKey = (string)($req['request_key'] ?? '');
                $requestLinkParam = $requestKey !== '' ? 'request_key=' . urlencode($requestKey) : '';
                $requestLink = $requestLinkParam !== '' ? ($basePath ?: '') . '/request?' . $requestLinkParam : null;
              ?>
              <tr>
                <td>#<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($buildRouteLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($buildStatusLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($buildContractLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($buildQueuePosition($req), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= number_format((float)($req['volume_m3'] ?? 0), 0) ?> m³</td>
                <td><?= number_format((float)($req['reward_isk'] ?? 0), 2) ?> ISK</td>
                <td><?= htmlspecialchars((string)($req['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php if ($requestLink): ?>
                    <a class="btn ghost" href="<?= htmlspecialchars($requestLink, ENT_QUOTES, 'UTF-8') ?>">View</a>
                  <?php else: ?>
                    <span class="muted">No link</span>
                  <?php endif; ?>
                  <?php if (!$isPreviousHaul($req) && empty($req['esi_contract_id'])): ?>
                    <button class="btn danger js-delete-request" type="button" data-request-id="<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?>">Delete</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php
        };
      ?>
      <?php if ($activeRequests): ?>
        <?php $renderTable($activeRequests); ?>
      <?php else: ?>
        <p class="muted">No active haul requests right now.</p>
      <?php endif; ?>
      <?php if ($previousRequests): ?>
        <h3>Previous Hauls</h3>
        <?php $renderTable($previousRequests); ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">Back to dashboard</a>
  </div>
</section>
<section class="card" data-discord-link-card="true"
  data-link-status-url="<?= htmlspecialchars(($basePath ?: '') . '/api/discord/link-status/', ENT_QUOTES, 'UTF-8') ?>"
  data-link-code-url="<?= htmlspecialchars(($basePath ?: '') . '/api/discord/link-code/', ENT_QUOTES, 'UTF-8') ?>"
  data-unlink-url="<?= htmlspecialchars(($basePath ?: '') . '/api/discord/unlink/', ENT_QUOTES, 'UTF-8') ?>">
  <div class="card-header">
    <h2>Discord Account Linking</h2>
    <p class="muted">Generate a one-time code to connect your portal account to Discord commands.</p>
  </div>
  <div class="content">
    <div class="pill pill-danger" data-discord-link-error style="display:none;"></div>
    <div data-discord-link-status class="muted">Checking link status…</div>
    <div data-discord-link-user style="margin-top:8px;"></div>
    <div data-discord-link-code-block style="display:none; margin-top:12px;">
      <div><strong>Link code:</strong> <span data-discord-link-code></span></div>
      <div class="muted" data-discord-link-expires>Expires in 10 minutes.</div>
      <div class="muted">Run <strong>/link &lt;code&gt;</strong> in Discord to finish linking.</div>
    </div>
    <div style="margin-top:12px;">
      <button class="btn" type="button" data-discord-link-generate>Generate link code</button>
      <button class="btn ghost" type="button" data-discord-link-unlink style="display:none;">Unlink</button>
    </div>
  </div>
</section>
<script src="<?= ($basePath ?: '') ?>/assets/js/my-contracts.js" defer></script>
<script src="<?= ($basePath ?: '') ?>/assets/js/discord-linking.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
