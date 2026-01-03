<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canManage = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.manage');
$apiKey = $apiKey ?? '';
$mismatchDetails = [];
if (!empty($request['mismatch_reason_json'])) {
  $decodedMismatch = json_decode((string)$request['mismatch_reason_json'], true);
  if (is_array($decodedMismatch)) {
    $mismatchDetails = $decodedMismatch['mismatches'] ?? $decodedMismatch;
  }
}

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Contract Instructions</h2>
    <p class="muted">Use these values to create the courier contract in-game.</p>
  </div>
  <div class="content">
    <?php if (!empty($error)): ?>
      <div class="alert alert-warning"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
      <div class="row">
        <div>
          <div class="label">Request</div>
          <div>#<?= htmlspecialchars((string)$request['request_id'], ENT_QUOTES, 'UTF-8') ?> • Status: <?= htmlspecialchars((string)$request['status'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div>
          <div class="label">Route</div>
          <div><?= htmlspecialchars((string)$routeSummary, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
      <div class="row" style="margin-top:12px;">
        <div>
          <div class="label">Contract link state</div>
          <div><?= htmlspecialchars((string)($request['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div>
          <div class="label">Contract ID</div>
          <div><?= !empty($request['esi_contract_id']) ? '#' . htmlspecialchars((string)$request['esi_contract_id'], ENT_QUOTES, 'UTF-8') : (!empty($request['contract_id']) ? '#' . htmlspecialchars((string)$request['contract_id'], ENT_QUOTES, 'UTF-8') : '—') ?></div>
        </div>
        <div>
          <div class="label">Contract status</div>
          <div><?= !empty($request['esi_status']) ? htmlspecialchars((string)$request['esi_status'], ENT_QUOTES, 'UTF-8') : (!empty($request['contract_status_esi']) ? htmlspecialchars((string)$request['contract_status_esi'], ENT_QUOTES, 'UTF-8') : (!empty($request['contract_status']) ? htmlspecialchars((string)$request['contract_status'], ENT_QUOTES, 'UTF-8') : '—')) ?></div>
        </div>
      </div>
      <div class="row" style="margin-top:12px;">
        <div>
          <div class="label">Contract lifecycle</div>
          <div><?= !empty($request['contract_lifecycle']) ? htmlspecialchars((string)$request['contract_lifecycle'], ENT_QUOTES, 'UTF-8') : (!empty($request['contract_state']) ? htmlspecialchars((string)$request['contract_state'], ENT_QUOTES, 'UTF-8') : '—') ?></div>
        </div>
        <div>
          <div class="label">ESI status</div>
          <div><?= !empty($request['esi_status']) ? htmlspecialchars((string)$request['esi_status'], ENT_QUOTES, 'UTF-8') : (!empty($request['contract_status_esi']) ? htmlspecialchars((string)$request['contract_status_esi'], ENT_QUOTES, 'UTF-8') : '—') ?></div>
        </div>
        <div>
          <div class="label">In-game acceptor</div>
          <div><?= !empty($request['esi_acceptor_name']) ? htmlspecialchars((string)$request['esi_acceptor_name'], ENT_QUOTES, 'UTF-8') : (!empty($request['contract_acceptor_name']) ? htmlspecialchars((string)$request['contract_acceptor_name'], ENT_QUOTES, 'UTF-8') : 'Unaccepted') ?></div>
        </div>
        <div>
          <div class="label">Ops assigned</div>
          <div><?= !empty($request['ops_assignee_name']) ? htmlspecialchars((string)$request['ops_assignee_name'], ENT_QUOTES, 'UTF-8') : 'Unassigned' ?></div>
        </div>
      </div>
      <?php if (!empty($mismatchDetails)): ?>
        <div style="margin-top:12px;">
          <div class="label">Mismatch reason</div>
          <ul class="muted" style="margin:6px 0 0 18px;">
            <?php foreach ($mismatchDetails as $key => $detail): ?>
              <li><?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string)json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="row" style="margin-top:16px;">
        <div>
          <div class="label">Issuer</div>
          <div><?= htmlspecialchars((string)$issuerName, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div>
          <div class="label">Private to</div>
          <div><?= htmlspecialchars((string)$issuerName, ENT_QUOTES, 'UTF-8') ?> (corp)</div>
        </div>
        <div>
          <div class="label">Ship class limit</div>
          <div><?= htmlspecialchars((string)$shipClassLabel, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>

      <div class="row" style="margin-top:16px;">
        <div>
          <div class="label">Collateral</div>
          <div><?= number_format((float)$request['collateral_isk'], 2) ?> ISK</div>
        </div>
        <div>
          <div class="label">Reward</div>
          <div><?= number_format((float)$request['reward_isk'], 2) ?> ISK</div>
        </div>
        <div>
          <div class="label">Volume limit</div>
          <div><?= number_format((float)$shipClassMax, 0) ?> m³</div>
        </div>
      </div>

      <div style="margin-top:16px;">
        <div class="label">Contract description template</div>
        <textarea class="input" rows="4" readonly><?= htmlspecialchars($contractDescription, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div style="margin-top:16px;">
        <div class="label">Attach contract after creation</div>
        <?php if (!empty($contractAttachEnabled)): ?>
          <div class="row">
            <input class="input" type="text" id="contract-id" placeholder="Enter contract_id" />
            <button class="btn" type="button" id="attach-contract">Attach Contract</button>
          </div>
          <div class="muted" id="attach-status" style="margin-top:8px;"></div>
        <?php else: ?>
          <div class="muted" style="margin-top:6px;">Contract attachment is disabled by admin settings.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">Back to dashboard</a>
  </div>
</section>

<?php if (empty($error) && !empty($contractAttachEnabled)): ?>
<script>
  (() => {
    const basePath = <?= json_encode($basePath ?: '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const apiKey = <?= json_encode($apiKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const attachBtn = document.getElementById('attach-contract');
    const contractInput = document.getElementById('contract-id');
    const statusEl = document.getElementById('attach-status');
    const quoteId = <?= (int)$request['quote_id'] ?>;

    attachBtn?.addEventListener('click', async () => {
      const contractId = parseInt(contractInput?.value || '0', 10);
      if (!contractId) {
        statusEl.textContent = 'Contract ID required.';
        return;
      }
      attachBtn.disabled = true;
      statusEl.textContent = 'Validating contract via ESI...';

      try {
        const resp = await fetch(`${basePath}/api/contracts/attach/`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            ...(apiKey ? { 'X-API-Key': apiKey } : {}),
          },
          body: JSON.stringify({
            quote_id: quoteId,
            contract_id: contractId,
          }),
        });
        const data = await resp.json();
        if (!data.ok) {
          statusEl.textContent = data.error || 'Contract attach failed.';
          return;
        }
        statusEl.textContent = 'Contract validated and queued. Discord webhook queued.';
      } catch (err) {
        statusEl.textContent = 'Contract attach failed.';
      } finally {
        attachBtn.disabled = false;
      }
    });
  })();
</script>
<?php endif; ?>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
?>
