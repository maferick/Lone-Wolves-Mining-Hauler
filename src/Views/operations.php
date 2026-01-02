<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canViewOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.read');
$canManageOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.manage');
$canAssignOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.assign');
$canExecuteOps = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.execute');
$queueStats = $queueStats ?? ['outstanding' => 0, 'in_progress' => 0, 'delivered' => 0];
$requests = $requests ?? [];
$haulers = $haulers ?? [];
$requestsAvailable = $requestsAvailable ?? false;
$corpName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');
$userId = (int)($authCtx['user_id'] ?? 0);
$apiKey = $apiKey ?? '';

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
  $contractId = (int)($req['contract_id'] ?? 0);
  $contractStatus = trim((string)($req['contract_status'] ?? ''));
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

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Hauling Operations</h2>
    <p class="muted">View, manage, and assign hauling requests across <?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?>.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <div class="alert alert-warning">
        Sign in to view and manage hauling operations.
      </div>
    <?php else: ?>
      <div class="row" style="margin-bottom:14px;">
        <div>
          <div class="label">Access</div>
          <div>
            <span class="pill">View <?= $canViewOps ? 'Enabled' : 'Limited' ?></span>
            <span class="pill subtle">Manage <?= $canManageOps ? 'Enabled' : 'Limited' ?></span>
            <span class="pill subtle">Assign <?= $canAssignOps ? 'Enabled' : 'Limited' ?></span>
            <span class="pill subtle">Execute <?= $canExecuteOps ? 'Enabled' : 'Limited' ?></span>
          </div>
        </div>
        <div>
          <div class="label">Queue summary</div>
          <div class="kpi-row" style="padding:6px 0 0;">
            <div class="kpi">
              <div class="kpi-label">Outstanding</div>
              <div class="kpi-value"><?= number_format((int)$queueStats['outstanding']) ?></div>
            </div>
            <div class="kpi">
              <div class="kpi-label">In Progress</div>
              <div class="kpi-value"><?= number_format((int)$queueStats['in_progress']) ?></div>
            </div>
            <div class="kpi">
              <div class="kpi-label">Delivered</div>
              <div class="kpi-value"><?= number_format((int)$queueStats['delivered']) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>View queue</h2>
            <p class="muted">Monitor incoming requests and see status changes in real time.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/operations#queue', ENT_QUOTES, 'UTF-8') ?>">Open queue</a>
          </div>
        </div>
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>Manage requests</h2>
            <p class="muted">Quote, update, and audit haul requests before posting.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/operations#manage', ENT_QUOTES, 'UTF-8') ?>">Review requests</a>
          </div>
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>Assign haulers</h2>
            <p class="muted">Dispatch internal haulers and track assignments.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/operations#assign', ENT_QUOTES, 'UTF-8') ?>">Assign jobs</a>
          </div>
        </div>
        <div class="card" style="background: rgba(255,255,255,.02);">
          <div class="card-header">
            <h2>Update status</h2>
            <p class="muted">Mark pickup, in-transit, and delivery milestones.</p>
          </div>
          <div class="card-footer">
            <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/operations#status', ENT_QUOTES, 'UTF-8') ?>">Update activity</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="card" id="queue">
  <div class="card-header">
    <h2>Active requests</h2>
    <p class="muted">Latest hauling requests with assignment status.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <p class="muted">Sign in to view queue data.</p>
    <?php elseif (!$canViewOps): ?>
      <p class="muted">You do not have permission to view hauling requests.</p>
    <?php elseif (!$requestsAvailable): ?>
      <p class="muted">No hauling requests found yet. Once requests are submitted, they will appear here.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Request</th>
            <th>Route</th>
            <th>Status</th>
            <th>Contract</th>
            <th>Volume</th>
            <th>Reward</th>
            <th>Requester</th>
            <th>Assigned</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): ?>
            <tr>
              <td>#<?= htmlspecialchars((string)$req['request_id'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($buildRouteLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$req['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($buildContractLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= number_format((float)($req['volume_m3'] ?? 0), 0) ?> m³</td>
              <td><?= number_format((float)($req['reward_isk'] ?? 0), 2) ?> ISK</td>
              <td><?= htmlspecialchars((string)($req['requester_display_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($req['hauler_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">Back to dashboard</a>
  </div>
</section>

<section class="card" id="manage">
  <div class="card-header">
    <h2>Manage requests</h2>
    <p class="muted">Review quote details, attach contracts, and remove requests when needed.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <p class="muted">Sign in to manage requests.</p>
    <?php elseif (!$canManageOps): ?>
      <p class="muted">You do not have permission to manage requests.</p>
    <?php elseif (!$requestsAvailable): ?>
      <p class="muted">No hauling requests available to manage.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Request</th>
            <th>Route</th>
            <th>Status</th>
            <th>Contract</th>
            <th>Reward</th>
            <th>Requester</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): ?>
            <?php
              $requestId = (int)($req['request_id'] ?? 0);
              $requestKey = (string)($req['request_key'] ?? '');
              $requestLinkKey = $requestKey !== '' ? $requestKey : (string)$requestId;
            ?>
            <tr>
              <td>#<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($buildRouteLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$req['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($buildContractLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= number_format((float)($req['reward_isk'] ?? 0), 2) ?> ISK</td>
              <td><?= htmlspecialchars((string)($req['requester_display_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="actions">
                <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/request?request_key=' . urlencode($requestLinkKey), ENT_QUOTES, 'UTF-8') ?>">Review</a>
                <button class="btn danger js-delete-request" type="button" data-request-id="<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="card" id="assign">
  <div class="card-header">
    <h2>Assign haulers</h2>
    <p class="muted">Dispatch internal haulers and track assignments.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <p class="muted">Sign in to assign haulers.</p>
    <?php elseif (!$canAssignOps): ?>
      <p class="muted">You do not have permission to assign haulers.</p>
    <?php elseif (!$requestsAvailable): ?>
      <p class="muted">No hauling requests available for assignment.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Request</th>
            <th>Route</th>
            <th>Status</th>
            <th>Assigned</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): ?>
            <?php
              $requestId = (int)($req['request_id'] ?? 0);
              $haulerUserId = (int)($req['hauler_user_id'] ?? 0);
              $assignedLabel = (string)($req['hauler_name'] ?? 'Unassigned');
              $isAssignedToSelf = $haulerUserId > 0 && $haulerUserId === $userId;
            ?>
            <tr>
              <td>#<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($buildRouteLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$req['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($assignedLabel, ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <?php if ($isAssignedToSelf): ?>
                  <span class="pill subtle">Assigned to you</span>
                <?php else: ?>
                  <button class="btn ghost js-assign-request" type="button" data-request-id="<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?>">Assign to me</button>
                <?php endif; ?>
                <button class="btn ghost js-assign-other" type="button" data-request-id="<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?>">Assign…</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<?php if ($canAssignOps && $requestsAvailable): ?>
<div class="modal-backdrop" id="assign-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label" id="assign-title">Assign hauler</div>
        <p class="muted" style="margin:4px 0 0;">Search and select the hauler to assign.</p>
      </div>
      <button class="btn ghost" type="button" id="assign-cancel">Close</button>
    </div>
    <div class="modal-body">
      <input class="input" type="text" id="assign-search" placeholder="Search by name..." />
      <div class="modal-list" id="assign-list">
        <?php if (empty($haulers)): ?>
          <div class="muted">No active members available.</div>
        <?php else: ?>
          <?php foreach ($haulers as $hauler): ?>
            <?php
              $haulerId = (int)($hauler['user_id'] ?? 0);
              $displayName = (string)($hauler['display_name'] ?? 'Unknown');
              $characterName = trim((string)($hauler['character_name'] ?? ''));
              $label = $characterName !== '' ? $displayName . ' (' . $characterName . ')' : $displayName;
            ?>
            <button class="btn ghost js-assign-select" type="button"
              data-hauler-id="<?= htmlspecialchars((string)$haulerId, ENT_QUOTES, 'UTF-8') ?>"
              data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </button>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<section class="card" id="status">
  <div class="card-header">
    <h2>Update status</h2>
    <p class="muted">Mark pickup, in-transit, and delivery milestones.</p>
  </div>
  <div class="content">
    <?php if (!$isLoggedIn): ?>
      <p class="muted">Sign in to update haul status.</p>
    <?php elseif (!$canExecuteOps): ?>
      <p class="muted">You do not have permission to update request statuses.</p>
    <?php elseif (!$requestsAvailable): ?>
      <p class="muted">No hauling requests available for status updates.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Request</th>
            <th>Route</th>
            <th>Status</th>
            <th>Update</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $req): ?>
            <?php $requestId = (int)($req['request_id'] ?? 0); ?>
            <tr>
              <td>#<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($buildRouteLabel($req), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$req['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="row" style="gap:8px; align-items:center;">
                  <select class="input js-status-select" data-request-id="<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?>">
                    <option value="in_progress">Picked up</option>
                    <option value="in_transit">In transit</option>
                    <option value="delivered">Delivered</option>
                  </select>
                  <button class="btn ghost js-update-status" type="button" data-request-id="<?= htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') ?>">Update</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<?php if ($isLoggedIn): ?>
<script>
  const basePath = <?= json_encode($basePath ?: '') ?>;
  const apiKey = <?= json_encode($apiKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const sendJson = async (url, payload) => {
    const resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(apiKey ? { 'X-API-Key': apiKey } : {}),
      },
      body: JSON.stringify(payload),
    });
    return resp.json();
  };

  document.querySelectorAll('.js-delete-request').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      if (!requestId) return;
      if (!confirm('Delete this contract request? This cannot be undone.')) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/delete/`, { request_id: requestId });
      if (!data.ok) {
        alert(data.error || 'Delete failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });

  document.querySelectorAll('.js-assign-request').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      if (!requestId) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/assign/`, { request_id: requestId });
      if (!data.ok) {
        alert(data.error || 'Assign failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });

  const assignModal = document.getElementById('assign-modal');
  const assignSearch = document.getElementById('assign-search');
  const assignList = document.getElementById('assign-list');
  const assignTitle = document.getElementById('assign-title');
  const assignCancel = document.getElementById('assign-cancel');
  let activeRequestId = 0;

  const closeAssignModal = () => {
    if (assignModal) {
      assignModal.setAttribute('hidden', 'hidden');
    }
    if (assignSearch) {
      assignSearch.value = '';
    }
    if (assignList) {
      assignList.querySelectorAll('.js-assign-select').forEach((btn) => {
        btn.removeAttribute('hidden');
      });
    }
    activeRequestId = 0;
  };

  const openAssignModal = (requestId) => {
    if (!assignModal || !assignList) return;
    activeRequestId = requestId;
    if (assignTitle) {
      assignTitle.textContent = `Assign request #${requestId}`;
    }
    assignModal.removeAttribute('hidden');
    assignSearch?.focus();
  };

  document.querySelectorAll('.js-assign-other').forEach((btn) => {
    btn.addEventListener('click', () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      if (!requestId) return;
      openAssignModal(requestId);
    });
  });

  assignCancel?.addEventListener('click', closeAssignModal);
  assignModal?.addEventListener('click', (event) => {
    if (event.target === assignModal) {
      closeAssignModal();
    }
  });

  assignSearch?.addEventListener('input', () => {
    const term = assignSearch.value.trim().toLowerCase();
    assignList?.querySelectorAll('.js-assign-select').forEach((btn) => {
      const label = (btn.dataset.label || '').toLowerCase();
      btn.toggleAttribute('hidden', term !== '' && !label.includes(term));
    });
  });

  assignList?.querySelectorAll('.js-assign-select').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const haulerId = parseInt(btn.dataset.haulerId || '0', 10);
      if (!activeRequestId || !haulerId) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/assign/`, {
        request_id: activeRequestId,
        hauler_user_id: haulerId,
      });
      if (!data.ok) {
        alert(data.error || 'Assign failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });

  document.querySelectorAll('.js-update-status').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      const select = document.querySelector(`.js-status-select[data-request-id="${requestId}"]`);
      const status = select?.value;
      if (!requestId || !status) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/update-status/`, { request_id: requestId, status });
      if (!data.ok) {
        alert(data.error || 'Status update failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });
</script>
<?php endif; ?>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
