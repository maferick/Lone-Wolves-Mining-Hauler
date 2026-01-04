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
$graphStatus = $services['route']->getGraphStatus();
$graphEmpty = empty($graphStatus['graph_loaded']);
$systemOptions = [];
$constellationOptions = [];
$regionOptions = [];
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
<section class="card" data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>" data-corp-id="<?= (int)$corpId ?>">
  <div class="card-header">
    <h2>Routing & Pricing Controls</h2>
    <p class="muted">Manage routing priority, reward tolerance, DNF rules, and rate plans.</p>
  </div>
  <div class="content">
    <?php if ($graphEmpty): ?>
      <div class="alert alert-warning" style="margin-bottom:12px;">
        Stargate graph data is missing. Populate map_system and map_edge to enable routing.
      </div>
    <?php endif; ?>
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

    <div style="margin-top:20px;">
      <h3>Priority surcharge</h3>
      <div class="muted">Add an ISK surcharge for higher priority requests.</div>
      <div class="row" style="margin-top:10px;">
        <input class="input" id="priority-fee-normal" type="number" step="0.01" min="0" placeholder="Normal priority add-on" />
        <input class="input" id="priority-fee-high" type="number" step="0.01" min="0" placeholder="High priority add-on" />
        <button class="btn" type="button" id="save-priority-fee">Save</button>
      </div>
    </div>

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
      <h3>Buyback Haulage</h3>
      <div class="muted">Set the fixed price for the buyback haulage button on the quote page.</div>
      <div class="row" style="margin-top:10px; align-items:center;">
        <input class="input" id="buyback-price" type="number" step="0.01" min="0" placeholder="Fixed price (ISK)" />
        <button class="btn" type="button" id="save-buyback">Save</button>
      </div>
      <div class="muted" id="buyback-note" style="margin-top:6px;"></div>
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
        </select>
        <input class="input" id="dnf-name-a" type="text" placeholder="Target name" list="dnf-system-list" autocomplete="off" />
        <input class="input" id="dnf-severity" type="number" min="1" value="1" />
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
  const corpId = root?.dataset.corpId || '';

  const fetchJson = async (url, options = {}) => {
    const resp = await fetch(url, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
    });
    return resp.json();
  };

  const dnfLookupData = {
    system: <?= json_encode(array_map(static fn($row) => ['id' => (int)$row['system_id'], 'name' => (string)$row['system_name']], $systemOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    constellation: <?= json_encode(array_map(static fn($row) => ['id' => (int)$row['constellation_id'], 'name' => (string)$row['constellation_name']], $constellationOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    region: <?= json_encode(array_map(static fn($row) => ['id' => (int)$row['region_id'], 'name' => (string)$row['region_name']], $regionOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  };
  const dnfMinChars = 3;

  const dnfListMap = {
    system: document.getElementById('dnf-system-list'),
    constellation: document.getElementById('dnf-constellation-list'),
    region: document.getElementById('dnf-region-list'),
  };

  const parseDnfSelection = (value, type) => {
    const raw = (value || '').trim();
    if (!raw) return { id: 0, name: '' };
    const match = raw.match(/\[(\d+)\]\s*$/);
    if (match) {
      return { id: parseInt(match[1], 10), name: raw.replace(/\s*\[\d+\]\s*$/, '').trim() };
    }
    const lower = raw.toLowerCase();
    const matchItem = (dnfLookupData[type] || []).find((item) => item.name.toLowerCase() === lower);
    return { id: matchItem?.id || 0, name: raw };
  };

  const buildDnfOptions = (listEl, items, value) => {
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!value || value.length < dnfMinChars) return;
    const query = value.toLowerCase();
    let count = 0;
    for (const item of items || []) {
      if (!item.name.toLowerCase().startsWith(query)) continue;
      const option = document.createElement('option');
      option.value = `${item.name} [${item.id}]`;
      listEl.appendChild(option);
      count += 1;
      if (count >= 50) break;
    }
  };

  const updateDnfListTarget = () => {
    const type = document.getElementById('dnf-scope')?.value || 'system';
    const listEl = dnfListMap[type] || dnfListMap.system;
    const input = document.getElementById('dnf-name-a');
    if (input && listEl) {
      input.setAttribute('list', listEl.id);
      buildDnfOptions(listEl, dnfLookupData[type], input.value);
    }
  };

  document.getElementById('dnf-scope')?.addEventListener('change', updateDnfListTarget);
  document.getElementById('dnf-name-a')?.addEventListener('input', (event) => {
    const type = document.getElementById('dnf-scope')?.value || 'system';
    buildDnfOptions(dnfListMap[type], dnfLookupData[type], event.target.value);
  });

  const loadPriority = async () => {
    const data = await fetchJson(`${basePath}/api/admin/routing-profile/?corp_id=${corpId}`);
    if (data.ok) {
      document.getElementById('routing-priority').value = data.priority;
    }
  };

  const loadPriorityFees = async () => {
    const data = await fetchJson(`${basePath}/api/admin/priority-fee/?corp_id=${corpId}`);
    if (!data.ok) return;
    const fees = data.priority_fee || {};
    const normalInput = document.getElementById('priority-fee-normal');
    const highInput = document.getElementById('priority-fee-high');
    if (normalInput) normalInput.value = fees.normal ?? 0;
    if (highInput) highInput.value = fees.high ?? 0;
  };

  const loadContractAttach = async () => {
    const data = await fetchJson(`${basePath}/api/admin/contract-attach/?corp_id=${corpId}`);
    if (!data.ok) return;
    const toggle = document.getElementById('contract-attach-enabled');
    const note = document.getElementById('contract-attach-note');
    const enabled = !!data.attach_enabled;
    if (toggle) toggle.checked = enabled;
    if (note) note.textContent = enabled ? 'Contract attach enabled for requesters.' : 'Contract attach disabled. Requests cannot attach contract IDs.';
  };

  const loadQuoteLocations = async () => {
    const data = await fetchJson(`${basePath}/api/admin/quote-locations/?corp_id=${corpId}`);
    if (!data.ok) return;
    const toggle = document.getElementById('quote-locations-structures');
    const note = document.getElementById('quote-locations-note');
    const enabled = !!data.allow_structures;
    if (toggle) toggle.checked = enabled;
    if (note) {
      note.textContent = enabled
        ? 'Stations and structures are available in quote location search.'
        : 'Quotes are limited to system names only.';
    }
  };

  const loadTolerance = async () => {
    const data = await fetchJson(`${basePath}/api/admin/settings/?corp_id=${corpId}`);
    if (data.ok) {
      document.getElementById('tolerance-type').value = data.reward_tolerance.type;
      document.getElementById('tolerance-value').value = data.reward_tolerance.value;
      document.getElementById('tolerance-note').textContent = 'Tolerance applied to reward validation.';
    }
  };

  const loadBuyback = async () => {
    const data = await fetchJson(`${basePath}/api/admin/buyback-haulage/?corp_id=${corpId}`);
    if (!data.ok) return;
    const priceInput = document.getElementById('buyback-price');
    const note = document.getElementById('buyback-note');
    if (priceInput) priceInput.value = data.price_isk ?? 0;
    if (note) {
      const price = Number(data.price_isk ?? 0);
      note.textContent = price > 0
        ? `Buyback haulage button shows ${price.toLocaleString('en-US', { maximumFractionDigits: 2 })} ISK.`
        : 'Set a fixed price to enable the buyback haulage button.';
    }
  };

  const loadRatePlans = async () => {
    const data = await fetchJson(`${basePath}/api/admin/rate-plan/?corp_id=${corpId}`);
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
    const nameA = rule.name_a || 'Unknown';
    if (rule.scope_type === 'edge') {
      const nameB = rule.name_b || 'Unknown';
      return `${nameA} \u2192 ${nameB}`;
    }
    return nameA;
  };

  const formatDnfScope = (scope) => {
    switch (scope) {
      case 'system':
        return 'System';
      case 'constellation':
        return 'Constellation';
      case 'region':
        return 'Region';
      case 'edge':
        return 'Gate Edge';
      default:
        return scope || 'Unknown';
    }
  };

  const loadDnfRules = async () => {
    const data = await fetchJson(`${basePath}/api/admin/dnf/?active=1`);
    const tbody = document.querySelector('#dnf-table tbody');
    tbody.innerHTML = '';
    (data.rules || []).forEach((rule) => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${formatDnfScope(rule.scope_type)}</td>
        <td>${formatDnfTarget(rule)}</td>
        <td>${rule.severity}</td>
        <td>${rule.is_hard_block ? 'Yes' : 'No'}</td>
        <td>${rule.reason || ''}</td>
        <td><button class="btn ghost" data-action="disable" data-id="${rule.dnf_rule_id}">Disable</button></td>
      `;
      tbody.appendChild(row);
    });
  };

  document.getElementById('save-priority')?.addEventListener('click', async () => {
    const priority = document.getElementById('routing-priority').value;
    await fetchJson(`${basePath}/api/admin/routing-profile/`, {
      method: 'POST',
      body: JSON.stringify({ corp_id: corpId, priority }),
    });
    loadPriority();
  });

  document.getElementById('save-priority-fee')?.addEventListener('click', async () => {
    const normal = parseFloat(document.getElementById('priority-fee-normal')?.value || '0');
    const high = parseFloat(document.getElementById('priority-fee-high')?.value || '0');
    await fetchJson(`${basePath}/api/admin/priority-fee/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        normal: Number.isFinite(normal) ? normal : 0,
        high: Number.isFinite(high) ? high : 0,
      }),
    });
    loadPriorityFees();
  });

  document.getElementById('save-contract-attach')?.addEventListener('click', async () => {
    const enabled = document.getElementById('contract-attach-enabled')?.checked ?? true;
    await fetchJson(`${basePath}/api/admin/contract-attach/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        attach_enabled: enabled,
      }),
    });
    loadContractAttach();
  });

  document.getElementById('save-quote-locations')?.addEventListener('click', async () => {
    const enabled = document.getElementById('quote-locations-structures')?.checked ?? true;
    await fetchJson(`${basePath}/api/admin/quote-locations/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        allow_structures: enabled,
      }),
    });
    loadQuoteLocations();
  });

  document.getElementById('save-tolerance')?.addEventListener('click', async () => {
    const type = document.getElementById('tolerance-type').value;
    const value = parseFloat(document.getElementById('tolerance-value').value || '0');
    await fetchJson(`${basePath}/api/admin/settings/`, {
      method: 'POST',
      body: JSON.stringify({ corp_id: corpId, type, value }),
    });
    loadTolerance();
  });

  document.getElementById('save-buyback')?.addEventListener('click', async () => {
    const price = parseFloat(document.getElementById('buyback-price')?.value || '0');
    await fetchJson(`${basePath}/api/admin/buyback-haulage/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        price_isk: Number.isFinite(price) ? price : 0,
      }),
    });
    loadBuyback();
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
    await fetchJson(`${basePath}/api/admin/rate-plan/`, {
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
    await fetchJson(`${basePath}/api/admin/rate-plan/`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    loadRatePlans();
  });

  document.getElementById('dnf-add')?.addEventListener('click', async () => {
    const scopeType = document.getElementById('dnf-scope').value;
    const selection = parseDnfSelection(document.getElementById('dnf-name-a').value, scopeType);
    const payload = {
      scope_type: scopeType,
      name_a: selection.name,
      id_a: selection.id || undefined,
      severity: parseInt(document.getElementById('dnf-severity').value || '1', 10),
      is_hard_block: document.getElementById('dnf-hard').checked,
      reason: document.getElementById('dnf-reason').value,
    };
    if (!payload.name_a && !payload.id_a) {
      return;
    }
    await fetchJson(`${basePath}/api/admin/dnf/`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    loadDnfRules();
  });

  document.getElementById('dnf-table')?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || target.dataset.action !== 'disable') return;
    const id = target.dataset.id;
    await fetchJson(`${basePath}/api/admin/dnf/`, {
      method: 'DELETE',
      body: JSON.stringify({ dnf_rule_id: id }),
    });
    loadDnfRules();
  });

  loadPriority();
  loadPriorityFees();
  loadContractAttach();
  loadQuoteLocations();
  loadTolerance();
  loadBuyback();
  loadRatePlans();
  loadDnfRules();
  updateDnfListTarget();
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
?>
