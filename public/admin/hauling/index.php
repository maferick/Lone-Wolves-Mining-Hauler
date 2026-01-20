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
$title = $appName . ' • Hauling Settings';
$graphStatus = $services['route']->getGraphStatus();
$graphEmpty = empty($graphStatus['graph_loaded']);
$systemOptions = [];
$constellationOptions = [];
$regionOptions = [];
$haulingTabs = [
  ['id' => 'rules', 'label' => 'Rules'],
  ['id' => 'optimization', 'label' => 'Optimization'],
  ['id' => 'access', 'label' => 'Access'],
  ['id' => 'validation', 'label' => 'Validation'],
  ['id' => 'slas-timers', 'label' => 'SLAs/Timers'],
  ['id' => 'risk-restrictions', 'label' => 'Risk/Restrictions'],
];
try {
  $systemOptions = $db->select("SELECT system_id, system_name FROM eve_system ORDER BY system_name");
  $constellationOptions = $db->select("SELECT constellation_id, constellation_name FROM eve_constellation ORDER BY constellation_name");
  $regionOptions = $db->select("SELECT region_id, region_name FROM eve_region ORDER BY region_name");
} catch (Throwable $e) {
  $systemOptions = [];
  $constellationOptions = [];
  $regionOptions = [];
}

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section
  class="card admin-tabs"
  data-admin-tabs="hauling"
  data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>"
  data-corp-id="<?= (int)$corpId ?>"
  data-dnf-system="<?= htmlspecialchars(json_encode(array_map(static fn($row) => ['id' => (int)$row['system_id'], 'name' => (string)$row['system_name']], $systemOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
  data-dnf-constellation="<?= htmlspecialchars(json_encode(array_map(static fn($row) => ['id' => (int)$row['constellation_id'], 'name' => (string)$row['constellation_name']], $constellationOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
  data-dnf-region="<?= htmlspecialchars(json_encode(array_map(static fn($row) => ['id' => (int)$row['region_id'], 'name' => (string)$row['region_name']], $regionOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
>
  <div class="card-header">
    <h2>Routing & Risk Controls</h2>
    <p class="muted">Manage routing policy, access safeguards, and risk controls.</p>
    <nav class="admin-subnav admin-subnav--tabs" data-admin-tabs-nav aria-label="Hauling sections">
      <?php foreach ($haulingTabs as $tab): ?>
        <a class="nav-link" href="<?= ($basePath ?: '') ?>/admin/hauling/#<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>" data-section="<?= htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="content">
    <section class="admin-section is-active" id="rules" data-section="rules">
      <div class="admin-section__title">Rules</div>
      <div class="row" style="align-items:flex-start;">
        <div style="flex:1;">
          <div class="label">Default priority</div>
          <div class="row">
            <select class="input" id="routing-priority">
              <option value="normal">Normal</option>
              <option value="high">High</option>
            </select>
            <button class="btn" type="button" id="save-priority">Save</button>
          </div>
        </div>
        <div style="flex:1;">
          <div class="label">Reward tolerance</div>
          <div class="row">
            <select class="input" id="tolerance-type">
              <option value="percent">Percent</option>
              <option value="flat">Flat ISK</option>
            </select>
            <input class="input" id="tolerance-value" type="number" step="0.01" min="0" placeholder="0.02 or 5000000" />
            <button class="btn" type="button" id="save-tolerance">Save</button>
          </div>
          <div class="muted" id="tolerance-note" style="margin-top:6px;"></div>
        </div>
      </div>
    </section>

    <section class="admin-section" id="optimization" data-section="optimization">
      <div class="admin-section__title">Optimization</div>
      <div style="margin-top:20px;">
        <h3>Route Optimization Suggestions</h3>
        <div class="muted">Configure detour budget and suggestion thresholds for haulers.</div>
        <div class="row" style="margin-top:10px; align-items:flex-end; flex-wrap:wrap;">
          <label class="form-field" style="min-width:200px;">
            <span class="form-label">Enable optimization</span>
            <input type="checkbox" id="optimization-enabled" />
          </label>
          <label class="form-field" style="min-width:200px;">
            <span class="form-label">Detour budget (jumps)</span>
            <input class="input" id="optimization-detour-jumps" type="number" min="0" step="1" />
          </label>
          <label class="form-field" style="min-width:200px;">
            <span class="form-label">Max suggestions</span>
            <input class="input" id="optimization-max-suggestions" type="number" min="1" step="1" />
          </label>
          <label class="form-field" style="min-width:240px;">
            <span class="form-label">Min free capacity (%)</span>
            <input class="input" id="optimization-min-free" type="number" min="0" step="0.1" />
          </label>
          <button class="btn" type="button" id="save-optimization">Save</button>
        </div>
        <div class="muted" id="optimization-note" style="margin-top:6px;"></div>
      </div>
    </section>

    <section class="admin-section" id="access" data-section="access">
      <div class="admin-section__title">Access</div>
      <div style="margin-top:20px;">
        <h3>Contract Attachment</h3>
        <div class="muted">Control whether contract IDs can be attached to hauling requests.</div>
        <div class="row" style="margin-top:10px; align-items:center;">
          <label class="form-field" style="margin:0;">
            <span class="form-label">Enable contract attach</span>
            <input type="checkbox" id="contract-attach-enabled" />
          </label>
          <button class="btn" type="button" id="save-contract-attach">Save</button>
        </div>
        <div class="muted" id="contract-attach-note" style="margin-top:6px;"></div>
      </div>

      <div style="margin-top:20px;">
        <h3>Quote Location Inputs</h3>
        <div class="muted">Allow quote requests to search stations and structures in addition to systems.</div>
        <div class="row" style="margin-top:10px; align-items:center;">
          <label class="form-field" style="margin:0;">
            <span class="form-label">Enable stations &amp; structures</span>
            <input type="checkbox" id="quote-locations-structures" />
          </label>
          <button class="btn" type="button" id="save-quote-locations">Save</button>
        </div>
        <div class="muted" id="quote-locations-note" style="margin-top:6px;"></div>
      </div>

      <div style="margin-top:20px;">
        <h3>Operations Dispatch Sections</h3>
        <div class="muted">Control whether Assign haulers and Update status show on the operations page.</div>
        <div class="row" style="margin-top:10px; align-items:center;">
          <label class="form-field" style="margin:0;">
            <span class="form-label">Enable dispatch sections</span>
            <input type="checkbox" id="operations-dispatch-enabled" />
          </label>
          <button class="btn" type="button" id="save-operations-dispatch">Save</button>
        </div>
        <div class="muted" id="operations-dispatch-note" style="margin-top:6px;"></div>
      </div>
    </section>

    <section class="admin-section" id="validation" data-section="validation">
      <div class="admin-section__title">Validation</div>
      <div style="margin-top:20px;">
        <h3>Buyback Haulage</h3>
        <div class="muted">Set volume-based tiers for the buyback haulage button (4 steps, up to 950,000 m³).</div>
        <table class="table" id="buyback-tier-table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Max Volume (m³)</th>
              <th>Price (ISK)</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <div class="row" style="margin-top:10px; align-items:center;">
          <button class="btn" type="button" id="save-buyback">Save</button>
        </div>
        <div class="muted" id="buyback-note" style="margin-top:6px;"></div>
      </div>
    </section>

    <section class="admin-section" id="slas-timers" data-section="slas-timers">
      <div class="admin-section__title">SLAs/Timers</div>
      <div class="card" style="padding:12px; margin-top:12px;">
        <div class="muted">No SLA or timer controls are configured yet.</div>
      </div>
    </section>

    <section class="admin-section" id="risk-restrictions" data-section="risk-restrictions">
      <div class="admin-section__title">Risk/Restrictions</div>
      <?php if ($graphEmpty): ?>
        <div class="alert alert-warning" style="margin-bottom:12px;">
          Stargate graph data is missing. Populate map_system and map_edge to enable routing.
        </div>
      <?php endif; ?>
      <div style="margin-top:24px;">
        <h3>Security Class Definitions</h3>
        <div class="muted">Configure how systems are categorized by security class.</div>
        <div class="row" style="margin-top:10px;">
          <input class="input" id="security-highsec-min" type="number" step="0.1" min="0" max="1" placeholder="High-sec min (0.5)" />
          <input class="input" id="security-lowsec-min" type="number" step="0.1" min="0" max="1" placeholder="Low-sec min (0.1)" />
          <button class="btn" type="button" id="save-security-classes">Save</button>
        </div>
        <div class="row" style="margin-top:10px; align-items:flex-start;">
          <div style="flex:1;">
            <label class="form-field">
              <span class="form-label">Pochven regions</span>
              <textarea class="input" id="special-pochven-regions" rows="2" placeholder="Pochven"></textarea>
            </label>
            <label class="form-field">
              <span class="form-label">Enable Pochven</span>
              <input type="checkbox" id="special-pochven-enabled" />
            </label>
          </div>
          <div style="flex:1;">
            <label class="form-field">
              <span class="form-label">Zarzakh systems</span>
              <textarea class="input" id="special-zarzakh-systems" rows="2" placeholder="Zarzakh"></textarea>
            </label>
            <label class="form-field">
              <span class="form-label">Enable Zarzakh</span>
              <input type="checkbox" id="special-zarzakh-enabled" />
            </label>
          </div>
          <div style="flex:1;">
            <label class="form-field">
              <span class="form-label">Thera systems (optional)</span>
              <textarea class="input" id="special-thera-systems" rows="2" placeholder="Thera"></textarea>
            </label>
            <label class="form-field">
              <span class="form-label">Enable Thera</span>
              <input type="checkbox" id="special-thera-enabled" />
            </label>
          </div>
        </div>
        <div class="muted" id="security-classes-note" style="margin-top:6px;"></div>
      </div>

      <div style="margin-top:24px;">
        <h3>Security Routing Rules</h3>
        <div class="muted">Control pickup, delivery, and transit access by security class.</div>
        <table class="table" id="security-routing-table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Class</th>
              <th>Enabled</th>
              <th>Allow pickup</th>
              <th>Allow delivery</th>
              <th>Requires acknowledgement</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <div class="row" style="margin-top:10px;">
          <button class="btn" type="button" id="save-security-routing">Save</button>
        </div>
        <div class="muted" id="security-routing-note" style="margin-top:6px;"></div>
      </div>

      <div style="margin-top:24px;">
        <h3>Route Blocks</h3>
        <div class="muted">Hard blocks prevent route usage; soft rules add penalty.</div>
        <table class="table" id="dnf-table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Scope</th>
              <th>Target</th>
              <th>Applies</th>
              <th>Severity</th>
              <th>Hard</th>
              <th>Reason</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <div class="row" style="margin-top:10px;">
          <select class="input" id="dnf-scope">
            <option value="system">System</option>
            <option value="constellation">Constellation</option>
            <option value="region">Region</option>
          </select>
          <input class="input" id="dnf-name-a" type="text" placeholder="Target name" list="dnf-system-list" autocomplete="off" />
          <input class="input" id="dnf-severity" type="number" min="1" value="1" />
          <label class="form-field" style="margin:0;">
            <span class="form-label">Pickup</span>
            <input type="checkbox" id="dnf-apply-pickup" checked />
          </label>
          <label class="form-field" style="margin:0;">
            <span class="form-label">Delivery</span>
            <input type="checkbox" id="dnf-apply-delivery" checked />
          </label>
          <label class="form-field" style="margin:0;">
            <span class="form-label">Transit</span>
            <input type="checkbox" id="dnf-apply-transit" checked />
          </label>
          <label class="form-field" style="margin:0;">
            <span class="form-label">Hard</span>
            <input type="checkbox" id="dnf-hard" />
          </label>
          <input class="input" id="dnf-reason" type="text" placeholder="Reason" />
          <button class="btn" type="button" id="dnf-add">Add</button>
        </div>
        <datalist id="dnf-system-list"></datalist>
        <datalist id="dnf-constellation-list"></datalist>
        <datalist id="dnf-region-list"></datalist>
      </div>

      <div style="margin-top:24px;">
        <h3>Webhooks</h3>
        <div class="muted">Manage Discord and Slack webhook endpoints for queue postings.</div>
        <div style="margin-top:10px;">
          <a class="btn" href="<?= ($basePath ?: '') ?>/admin/webhooks/">Open Webhooks</a>
        </div>
      </div>
    </section>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
  </div>
</section>
<script src="<?= ($basePath ?: '') ?>/assets/js/admin/admin-tabs.js" defer></script>

<script src="<?= ($basePath ?: '') ?>/assets/js/admin/hauling.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
?>
