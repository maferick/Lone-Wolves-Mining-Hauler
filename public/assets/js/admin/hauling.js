(() => {
  const root = document.querySelector('section.card[data-base-path][data-corp-id]');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const corpId = root.dataset.corpId || '';
  const parseData = (value) => {
    if (!value) return [];
    try {
      return JSON.parse(value);
    } catch (err) {
      return [];
    }
  };

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
    system: parseData(root.dataset.dnfSystem),
    constellation: parseData(root.dataset.dnfConstellation),
    region: parseData(root.dataset.dnfRegion),
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

  const loadOperationsDispatch = async () => {
    const data = await fetchJson(`${basePath}/api/admin/operations-sections/?corp_id=${corpId}`);
    if (!data.ok) return;
    const toggle = document.getElementById('operations-dispatch-enabled');
    const note = document.getElementById('operations-dispatch-note');
    const enabled = !!data.show_dispatch;
    if (toggle) toggle.checked = enabled;
    if (note) {
      note.textContent = enabled
        ? 'Dispatch sections are visible to operations users.'
        : 'Dispatch sections are hidden from the operations page.';
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
    const tableBody = document.querySelector('#buyback-tier-table tbody');
    const note = document.getElementById('buyback-note');
    const tiers = Array.isArray(data.tiers) ? data.tiers : [];
    if (tableBody) {
      tableBody.innerHTML = '';
      tiers.forEach((tier, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
          <td><input class="input" data-field="max_m3" type="number" min="1" step="0.01" value="${tier.max_m3 ?? ''}" /></td>
          <td><input class="input" data-field="price_isk" type="number" min="0" step="0.01" value="${tier.price_isk ?? 0}" /></td>
        `;
        row.dataset.index = index;
        tableBody.appendChild(row);
      });
    }
    if (note) {
      const enabled = tiers.some((tier) => Number(tier.price_isk ?? 0) > 0);
      note.textContent = enabled
        ? 'Buyback haulage price is set by volume tier.'
        : 'Set at least one tier price to enable buyback haulage.';
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

  document.getElementById('save-operations-dispatch')?.addEventListener('click', async () => {
    const enabled = document.getElementById('operations-dispatch-enabled')?.checked ?? true;
    await fetchJson(`${basePath}/api/admin/operations-sections/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        show_dispatch: enabled,
      }),
    });
    loadOperationsDispatch();
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
    const tiers = [];
    const rows = document.querySelectorAll('#buyback-tier-table tbody tr');
    rows.forEach((row) => {
      const maxInput = row.querySelector('input[data-field="max_m3"]');
      const priceInput = row.querySelector('input[data-field="price_isk"]');
      const max = parseFloat(maxInput?.value || '0');
      const price = parseFloat(priceInput?.value || '0');
      tiers.push({
        max_m3: Number.isFinite(max) ? max : 0,
        price_isk: Number.isFinite(price) ? price : 0,
      });
    });
    await fetchJson(`${basePath}/api/admin/buyback-haulage/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        tiers,
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
  loadOperationsDispatch();
  loadTolerance();
  loadBuyback();
  loadRatePlans();
  loadDnfRules();
  updateDnfListTarget();
})();
