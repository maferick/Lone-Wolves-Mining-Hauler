<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canManage = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.manage');
$apiKey = $apiKey ?? '';

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
        <div>
          <div class="label">Profile</div>
          <div><?= htmlspecialchars((string)$request['route_policy'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>

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
        <div class="row">
          <input class="input" type="text" id="contract-id" placeholder="Enter contract_id" />
          <button class="btn" type="button" id="attach-contract">Attach Contract</button>
        </div>
        <div class="muted" id="attach-status" style="margin-top:8px;"></div>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">Back to dashboard</a>
  </div>
</section>

<?php if (empty($error)): ?>
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
        const resp = await fetch(`${basePath}/api/contracts/attach`, {
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
