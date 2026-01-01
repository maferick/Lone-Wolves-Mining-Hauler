<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requirePerm($authCtx, 'corp.manage');

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) { http_response_code(400); echo "No corp context"; exit; }

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Hauling Settings';
$apiKey = (string)($config['security']['api_key'] ?? '');

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card" data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>" data-api-key="<?= htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') ?>" data-corp-id="<?= (int)$corpId ?>">
  <div class="card-header">
    <h2>Routing & Pricing Controls</h2>
    <p class="muted">Manage routing profiles, reward tolerance, DNF rules, and rate plans.</p>
  </div>
  <div class="content">
    <div class="row" style="align-items:flex-start;">
      <div style="flex:1;">
        <div class="label">Default routing profile</div>
        <div class="row">
          <select class="input" id="routing-profile">
            <option value="shortest">Shortest</option>
            <option value="balanced">Balanced</option>
            <option value="safest">Safest</option>
          </select>
          <button class="btn" type="button" id="save-profile">Save</button>
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

    <div style="margin-top:20px;">
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

    <div style="margin-top:24px;">
      <h3>DNF Rules</h3>
      <div class="muted">Hard blocks prevent route usage; soft rules add penalty.</div>
      <table class="table" id="dnf-table" style="margin-top:10px;">
        <thead>
          <tr>
            <th>ID</th>
            <th>Scope</th>
            <th>Target</th>
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
          <option value="edge">Gate Edge</option>
        </select>
        <input class="input" id="dnf-id-a" type="number" min="1" placeholder="ID A" />
        <input class="input" id="dnf-id-b" type="number" min="1" placeholder="ID B (edge)" />
        <input class="input" id="dnf-severity" type="number" min="1" value="1" />
        <label class="form-field" style="margin:0;">
          <span class="form-label">Hard</span>
          <input type="checkbox" id="dnf-hard" />
        </label>
        <input class="input" id="dnf-reason" type="text" placeholder="Reason" />
        <button class="btn" type="button" id="dnf-add">Add</button>
      </div>
    </div>

    <div style="margin-top:24px;">
      <h3>Discord Webhooks</h3>
      <div class="muted">Manage Discord webhook endpoints for queue postings.</div>
      <div style="margin-top:10px;">
        <a class="btn" href="<?= ($basePath ?: '') ?>/admin/webhooks/">Open Webhooks</a>
      </div>
    </div>
  </div>
  <div class="card-footer">
    <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
  </div>
</section>

<script>
(() => {
  const root = document.querySelector('section.card[data-base-path]');
  const basePath = root?.dataset.basePath || '';
  const apiKey = root?.dataset.apiKey || '';
  const corpId = root?.dataset.corpId || '';

  const fetchJson = async (url, options = {}) => {
    const resp = await fetch(url, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...(apiKey ? { 'X-API-Key': apiKey } : {}),
        ...(options.headers || {}),
      },
    });
    return resp.json();
  };

  const loadProfile = async () => {
    const data = await fetchJson(`${basePath}/api/admin/routing-profile?corp_id=${corpId}`);
    if (data.ok) {
      document.getElementById('routing-profile').value = data.profile;
    }
  };

  const loadTolerance = async () => {
    const data = await fetchJson(`${basePath}/api/admin/settings?corp_id=${corpId}`);
    if (data.ok) {
      document.getElementById('tolerance-type').value = data.reward_tolerance.type;
      document.getElementById('tolerance-value').value = data.reward_tolerance.value;
      document.getElementById('tolerance-note').textContent = 'Tolerance applied to reward validation.';
    }
  };

  const loadRatePlans = async () => {
    const data = await fetchJson(`${basePath}/api/admin/rate-plan?corp_id=${corpId}`);
    const tbody = document.querySelector('#rate-plan-table tbody');
    tbody.innerHTML = '';
    (data.rate_plans || []).forEach((plan) => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${plan.service_class}</td>
        <td><input class="input" data-field="rate_per_jump" type="number" step="0.01" value="${plan.rate_per_jump}" /></td>
        <td><input class="input" data-field="collateral_rate" type="number" step="0.0001" value="${plan.collateral_rate}" /></td>
        <td><input class="input" data-field="min_price" type="number" step="0.01" value="${plan.min_price}" /></td>
        <td><button class="btn ghost" data-action="save" data-id="${plan.rate_plan_id}">Save</button></td>
      `;
      tbody.appendChild(row);
    });
  };

  const formatDnfTarget = (rule) => {
    const nameA = rule.name_a ? `${rule.name_a} (${rule.id_a})` : `${rule.id_a}`;
    if (rule.scope_type === 'edge') {
      const nameB = rule.name_b ? `${rule.name_b} (${rule.id_b})` : `${rule.id_b}`;
      return `${nameA} → ${nameB}`;
    }
    return nameA;
  };

  const loadDnfRules = async () => {
    const data = await fetchJson(`${basePath}/api/admin/dnf?active=1`);
    const tbody = document.querySelector('#dnf-table tbody');
    tbody.innerHTML = '';
    (data.rules || []).forEach((rule) => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${rule.dnf_rule_id}</td>
        <td>${rule.scope_type}</td>
        <td>${formatDnfTarget(rule)}</td>
        <td>${rule.severity}</td>
        <td>${rule.is_hard_block ? 'Yes' : 'No'}</td>
        <td>${rule.reason || ''}</td>
        <td><button class="btn ghost" data-action="disable" data-id="${rule.dnf_rule_id}">Disable</button></td>
      `;
      tbody.appendChild(row);
    });
  };

  document.getElementById('save-profile')?.addEventListener('click', async () => {
    const profile = document.getElementById('routing-profile').value;
    await fetchJson(`${basePath}/api/admin/routing-profile`, {
      method: 'POST',
      body: JSON.stringify({ corp_id: corpId, profile }),
    });
    loadProfile();
  });

  document.getElementById('save-tolerance')?.addEventListener('click', async () => {
    const type = document.getElementById('tolerance-type').value;
    const value = parseFloat(document.getElementById('tolerance-value').value || '0');
    await fetchJson(`${basePath}/api/admin/settings`, {
      method: 'POST',
      body: JSON.stringify({ corp_id: corpId, type, value }),
    });
    loadTolerance();
  });

  document.getElementById('rate-plan-table')?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || target.dataset.action !== 'save') return;
    const row = target.closest('tr');
    if (!row) return;
    const inputs = row.querySelectorAll('input[data-field]');
    const payload = { rate_plan_id: target.dataset.id };
    inputs.forEach((input) => {
      payload[input.dataset.field] = parseFloat(input.value || '0');
    });
    await fetchJson(`${basePath}/api/admin/rate-plan`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
    loadRatePlans();
  });

  document.getElementById('add-rate-plan')?.addEventListener('click', async () => {
    const payload = {
      corp_id: corpId,
      service_class: document.getElementById('new-rate-class').value,
      rate_per_jump: parseFloat(document.getElementById('new-rate-per-jump').value || '0'),
      collateral_rate: parseFloat(document.getElementById('new-collateral-rate').value || '0'),
      min_price: parseFloat(document.getElementById('new-min-price').value || '0'),
    };
    await fetchJson(`${basePath}/api/admin/rate-plan`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    loadRatePlans();
  });

  document.getElementById('dnf-add')?.addEventListener('click', async () => {
    const payload = {
      scope_type: document.getElementById('dnf-scope').value,
      id_a: parseInt(document.getElementById('dnf-id-a').value || '0', 10),
      id_b: parseInt(document.getElementById('dnf-id-b').value || '0', 10),
      severity: parseInt(document.getElementById('dnf-severity').value || '1', 10),
      is_hard_block: document.getElementById('dnf-hard').checked,
      reason: document.getElementById('dnf-reason').value,
    };
    await fetchJson(`${basePath}/api/admin/dnf`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    loadDnfRules();
  });

  document.getElementById('dnf-table')?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || target.dataset.action !== 'disable') return;
    const id = target.dataset.id;
    await fetchJson(`${basePath}/api/admin/dnf`, {
      method: 'DELETE',
      body: JSON.stringify({ dnf_rule_id: id }),
    });
    loadDnfRules();
  });

  loadProfile();
  loadTolerance();
  loadRatePlans();
  loadDnfRules();
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
?>
