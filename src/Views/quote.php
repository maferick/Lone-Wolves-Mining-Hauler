<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canCreateRequest = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.create');
$canBuybackHaulage = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.buyback');
$quoteInput = $quoteInput ?? ['pickup_system' => '', 'destination_system' => ''];
$defaultPriority = $defaultPriority ?? 'normal';
$buybackHaulageTiers = $buybackHaulageTiers ?? [];
$buybackHaulageEnabled = $buybackHaulageEnabled ?? false;
$bodyClass = 'quote';

ob_start();
?>
<section class="quote-page">
  <?php if ($isLoggedIn): ?>
    <div class="card quote-card" id="quote">
      <div class="card-header">
        <h2>Get Quote</h2>
        <p class="muted">Enter pickup, destination, and volume to get an instant breakdown.</p>
      </div>
      <div
        class="content js-quote-form"
        data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>"
        data-buyback-tiers="<?= htmlspecialchars(json_encode($buybackHaulageTiers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
        data-buyback-enabled="<?= $buybackHaulageEnabled ? '1' : '0' ?>"
      >
        <div class="form-grid">
          <label class="form-field">
            <span class="form-label">Pickup system</span>
            <input class="input" type="text" name="pickup_system" list="pickup-location-list" value="<?= htmlspecialchars($quoteInput['pickup_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Jita" autocomplete="off" />
          </label>
          <label class="form-field">
            <span class="form-label">Destination system</span>
            <input class="input" type="text" name="destination_system" list="destination-location-list" value="<?= htmlspecialchars($quoteInput['destination_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Amarr" autocomplete="off" />
          </label>
          <label class="form-field">
            <span class="form-label">Volume (m³)</span>
            <input class="input" type="number" name="volume_m3" min="1" step="0.01" placeholder="e.g. 12500" />
          </label>
          <label class="form-field">
            <span class="form-label">Collateral (ISK)</span>
            <input class="input" type="text" name="collateral" placeholder="e.g. 300m, 2.65b, 400,000,000" />
          </label>
          <label class="form-field">
            <span class="form-label">Priority</span>
            <select class="input" name="priority">
              <?php
              $priorities = ['normal' => 'Normal', 'high' => 'High'];
              foreach ($priorities as $value => $label):
                $selected = $defaultPriority === $value ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="card-footer">
          <button class="btn" type="button" id="quote-submit">Get Quote</button>
          <?php if (!$canCreateRequest): ?>
            <span class="muted" style="margin-left:12px;">Sign in with requester access to create a haul request.</span>
          <?php endif; ?>
          <?php if ($canBuybackHaulage): ?>
            <button class="btn ghost" type="button" id="buyback-haulage-btn" <?= $buybackHaulageEnabled ? '' : 'disabled' ?>>
              Buyback haulage — volume based
            </button>
            <?php if (!$buybackHaulageEnabled): ?>
              <span class="muted" style="margin-left:12px;">Set buyback haulage tiers in Admin → Hauling to enable.</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="alert alert-warning" id="quote-error" style="display:none;"></div>
        <div class="alert alert-success" id="quote-result" style="display:none;"></div>
        <div class="card card-subtle" id="quote-breakdown" style="margin-top:16px; display:none;">
          <div class="card-header">
            <h3>Quote Breakdown</h3>
            <p class="muted">Includes route, penalties, and rate plan components.</p>
          </div>
          <div class="content" id="quote-breakdown-content"></div>
          <div class="card-footer" style="display:flex; gap:10px; align-items:center;">
            <button class="btn" type="button" id="quote-create-request" <?= $canCreateRequest ? '' : 'disabled' ?>>Create Request</button>
            <a class="btn ghost" id="quote-request-link" href="#" style="display:none;">View Contract Instructions</a>
            <span class="muted" id="quote-request-status" style="display:none;"></span>
          </div>
        </div>
        <datalist id="pickup-location-list"></datalist>
        <datalist id="destination-location-list"></datalist>
      </div>
    </div>
  <?php else: ?>
    <div class="card quote-card" id="quote">
      <div class="card-header">
        <h2>Member Quotes</h2>
        <p class="muted">Sign in with EVE Online to access instant pricing.</p>
      </div>
      <div class="content" style="display:flex; flex-direction:column; gap:12px;">
        <p class="muted">Only corporation members can request quotes.</p>
        <a class="sso-button" href="<?= ($basePath ?: '') ?>/login/?start=1">
          <img src="https://web.ccpgamescdn.com/eveonlineassets/developers/eve-sso-login-black-small.png" alt="Log in with EVE Online" />
        </a>
      </div>
    </div>
  <?php endif; ?>
</section>
<?php if ($isLoggedIn): ?>
  <script src="<?= ($basePath ?: '') ?>/assets/js/quote.js" defer></script>
<?php endif; ?>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
