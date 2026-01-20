<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) { http_response_code(400); echo "No corp context"; exit; }

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Pricing Settings';
$pricingTabs = [
  ['id' => 'rate-plans', 'label' => 'Rate Plans'],
  ['id' => 'security-multipliers', 'label' => 'Security Multipliers'],
  ['id' => 'surcharges', 'label' => 'Surcharges'],
  ['id' => 'min-max-caps', 'label' => 'Min/Max Caps'],
  ['id' => 'specials-discounts', 'label' => 'Specials/Discounts'],
];

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section
  class="card admin-tabs"
  data-admin-tabs="pricing"
  data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>"
  data-corp-id="<?= (int)$corpId ?>"
>
  <div class="card-header">
    <h2>Pricing Controls</h2>
    <p class="muted">Adjust economic levers: rate plans, risk multipliers, and surcharges.</p>
    <nav class="admin-subnav admin-subnav--tabs" data-admin-tabs-nav aria-label="Pricing sections">
      <?php foreach ($pricingTabs as $tab): ?>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/pricing/#<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>" data-section="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="content">
    <section class="admin-section is-active" id="rate-plans" data-section="rate-plans">
      <div class="admin-section__title">Rate Plans</div>
      <div style="margin-top:24px;">
        <h3>Rate Plans</h3>
        <div class="muted">Set per-jump, collateral rate, and minimums per ship class.</div>
        <table class="table" id="rate-plan-table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Class</th>
              <th>Rate / Jump</th>
              <th>Collateral %</th>
              <th>Minimum</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <div class="row" style="margin-top:10px;">
          <select class="input" id="new-rate-class">
            <option value="BR">BR</option>
            <option value="DST">DST</option>
            <option value="FREIGHTER">FREIGHTER</option>
            <option value="JF">JF</option>
          </select>
          <input class="input" id="new-rate-per-jump" type="number" step="0.01" min="0" placeholder="Rate per jump" />
          <input class="input" id="new-collateral-rate" type="number" step="0.0001" min="0" placeholder="Collateral rate" />
          <input class="input" id="new-min-price" type="number" step="0.01" min="0" placeholder="Minimum price" />
          <button class="btn" type="button" id="add-rate-plan">Add</button>
        </div>
      </div>
    </section>

    <section class="admin-section" id="security-multipliers" data-section="security-multipliers">
      <div class="admin-section__title">Security Multipliers</div>
      <div style="margin-top:24px;">
        <h3>Security multipliers</h3>
        <div class="muted">Applied per jump after the base rate.</div>
        <table class="table" id="security-multiplier-table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Security class</th>
              <th>Multiplier</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <div class="row" style="margin-top:10px;">
          <button class="btn" type="button" id="save-security-multipliers">Save</button>
        </div>
        <div class="muted" id="security-multipliers-note" style="margin-top:6px;"></div>
      </div>
    </section>

    <section class="admin-section" id="surcharges" data-section="surcharges">
      <div class="admin-section__title">Surcharges</div>
      <div style="margin-top:20px;">
        <h3>Priority surcharge</h3>
        <div class="muted">Add an ISK surcharge for higher priority requests.</div>
        <div class="row" style="margin-top:10px;">
          <input class="input" id="priority-fee-normal" type="number" step="0.01" min="0" placeholder="Normal priority add-on" />
          <input class="input" id="priority-fee-high" type="number" step="0.01" min="0" placeholder="High priority add-on" />
          <button class="btn" type="button" id="save-priority-fee">Save</button>
        </div>
      </div>

      <div style="margin-top:24px;">
        <h3>Flat risk surcharges</h3>
        <div class="muted">One-time additions when a route touches risky space.</div>
        <div class="row" style="margin-top:10px;">
          <input class="input" id="flat-risk-lowsec" type="number" step="0.01" min="0" placeholder="Low-sec surcharge" />
          <input class="input" id="flat-risk-nullsec" type="number" step="0.01" min="0" placeholder="Null-sec surcharge" />
          <input class="input" id="flat-risk-special" type="number" step="0.01" min="0" placeholder="Special-space surcharge" />
          <button class="btn" type="button" id="save-flat-risk">Save</button>
        </div>
        <div class="muted" id="flat-risk-note" style="margin-top:6px;"></div>
      </div>
    </section>

    <section class="admin-section" id="min-max-caps" data-section="min-max-caps">
      <div class="admin-section__title">Min/Max Caps</div>
      <div style="margin-top:24px;">
        <h3>Volume pressure scaling</h3>
        <div class="muted">Optional multiplier when volume nears max hull capacity.</div>
        <label class="form-field" style="margin:10px 0 0;">
          <span class="form-label">Enable volume pressure</span>
          <input type="checkbox" id="volume-pressure-enabled" />
        </label>
        <table class="table" id="volume-pressure-table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Min % of capacity</th>
              <th>Surcharge %</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <div class="row" style="margin-top:10px;">
          <button class="btn ghost" type="button" id="add-volume-pressure">Add threshold</button>
          <button class="btn" type="button" id="save-volume-pressure">Save</button>
        </div>
        <div class="muted" id="volume-pressure-note" style="margin-top:6px;"></div>
      </div>
    </section>

    <section class="admin-section" id="specials-discounts" data-section="specials-discounts">
      <div class="admin-section__title">Specials/Discounts</div>
      <div class="card" style="padding:12px; margin-top:12px;">
        <div class="muted">No specials or discount rules have been configured yet.</div>
      </div>
    </section>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
  </div>
</section>

<script src="<?= ($basePath ?: '') ?>/assets/js/admin/admin-tabs.js" defer></script>
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/pricing.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
?>
