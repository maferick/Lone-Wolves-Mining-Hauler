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
  const parseList = (value) => (value || '')
    .split(/\r?\n|,/)
    .map((entry) => entry.trim())
    .filter((entry) => entry.length > 0);
  const formatList = (items) => (Array.isArray(items) ? items.join('\n') : '');

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

  const loadContractAttach = async () => {
    const data = await fetchJson(`${basePath}/api/admin/contract-attach/?corp_id=${corpId}`);
    if (!data.ok) return;
    const toggle = document.getElementById('contract-attach-enabled');
    const note = document.getElementById('contract-attach-note');
    const enabled = !!data.attach_enabled;
    if (toggle) toggle.checked = enabled;
    if (note) note.textContent = enabled ? 'Contract attach enabled for requesters.' : 'Contract attach disabled. Requests cannot attach contract IDs.';
  };

  const loadContractValidation = async () => {
    const data = await fetchJson(`${basePath}/api/admin/contract-link-validation/?corp_id=${corpId}`);
    if (!data.ok) return;
    const checks = data.checks || {};
    const typeToggle = document.getElementById('contract-validate-type');
    const startToggle = document.getElementById('contract-validate-start');
    const endToggle = document.getElementById('contract-validate-end');
    const volumeToggle = document.getElementById('contract-validate-volume');
    const note = document.getElementById('contract-validation-note');
    if (typeToggle) typeToggle.checked = !!checks.type;
    if (startToggle) startToggle.checked = !!checks.start_system;
    if (endToggle) endToggle.checked = !!checks.end_system;
    if (volumeToggle) volumeToggle.checked = !!checks.volume;
    if (note) {
      const enabled = [
        checks.type,
        checks.start_system,
        checks.end_system,
        checks.volume,
      ].filter(Boolean).length;
      note.textContent = enabled > 0
        ? 'Selected checks will be enforced when contracts are attached.'
        : 'No optional checks selected. Only reward and collateral will be verified.';
    }
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

  const loadOptimization = async () => {
    const data = await fetchJson(`${basePath}/api/admin/route-optimization/?corp_id=${corpId}`);
    if (!data.ok) return;
    const settings = data.settings || {};
    const enabled = !!settings.enabled;
    const enabledToggle = document.getElementById('optimization-enabled');
    const detourInput = document.getElementById('optimization-detour-jumps');
    const maxInput = document.getElementById('optimization-max-suggestions');
    const minInput = document.getElementById('optimization-min-free');
    const note = document.getElementById('optimization-note');
    if (enabledToggle) enabledToggle.checked = enabled;
    if (detourInput) detourInput.value = settings.detour_budget_jumps ?? 5;
    if (maxInput) maxInput.value = settings.max_suggestions ?? 5;
    if (minInput) minInput.value = settings.min_free_capacity_percent ?? 10;
    if (note) {
      note.textContent = enabled
        ? 'Haulers will receive suggestions when they have spare capacity and detour budget.'
        : 'Optimization suggestions are currently disabled.';
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

  const loadSecurityClasses = async () => {
    const data = await fetchJson(`${basePath}/api/admin/security-classes/?corp_id=${corpId}`);
    if (!data.ok) return;
    const thresholds = data.thresholds || {};
    const special = data.special || {};
    const highInput = document.getElementById('security-highsec-min');
    const lowInput = document.getElementById('security-lowsec-min');
    if (highInput) highInput.value = thresholds.highsec_min ?? 0.5;
    if (lowInput) lowInput.value = thresholds.lowsec_min ?? 0.1;
    const pochven = special.pochven || {};
    const zarzakh = special.zarzakh || {};
    const thera = special.thera || {};
    const pochvenRegions = document.getElementById('special-pochven-regions');
    const zarzakhSystems = document.getElementById('special-zarzakh-systems');
    const theraSystems = document.getElementById('special-thera-systems');
    if (pochvenRegions) pochvenRegions.value = formatList(pochven.region_names);
    if (zarzakhSystems) zarzakhSystems.value = formatList(zarzakh.system_names);
    if (theraSystems) theraSystems.value = formatList(thera.system_names);
    const pochvenEnabled = document.getElementById('special-pochven-enabled');
    const zarzakhEnabled = document.getElementById('special-zarzakh-enabled');
    const theraEnabled = document.getElementById('special-thera-enabled');
    if (pochvenEnabled) pochvenEnabled.checked = !!pochven.enabled;
    if (zarzakhEnabled) zarzakhEnabled.checked = !!zarzakh.enabled;
    if (theraEnabled) theraEnabled.checked = !!thera.enabled;
    const note = document.getElementById('security-classes-note');
    if (note) {
      note.textContent = 'Security thresholds and special space lists are applied to routing classification.';
    }
  };

  const loadSecurityRoutingRules = async () => {
    const data = await fetchJson(`${basePath}/api/admin/security-routing/?corp_id=${corpId}`);
    if (!data.ok) return;
    const rules = data.rules || {};
    const tbody = document.querySelector('#security-routing-table tbody');
    if (!tbody) return;
    const classLabels = {
      high: 'High-sec',
      low: 'Low-sec',
      null: 'Null-sec',
      pochven: 'Pochven',
      zarzakh: 'Zarzakh',
      thera: 'Thera',
    };
    tbody.innerHTML = '';
    Object.keys(classLabels).forEach((key) => {
      const rule = rules[key] || {};
      const row = document.createElement('tr');
      row.dataset.classKey = key;
      row.innerHTML = `
        <td>${classLabels[key]}</td>
        <td><input type="checkbox" data-field="enabled" ${rule.enabled ? 'checked' : ''} /></td>
        <td><input type="checkbox" data-field="allow_pickup" ${rule.allow_pickup ? 'checked' : ''} /></td>
        <td><input type="checkbox" data-field="allow_delivery" ${rule.allow_delivery ? 'checked' : ''} /></td>
        <td><input type="checkbox" data-field="requires_acknowledgement" ${rule.requires_acknowledgement ? 'checked' : ''} /></td>
      `;
      tbody.appendChild(row);
    });
    const note = document.getElementById('security-routing-note');
    if (note) {
      note.textContent = 'Disable pickup/delivery to allow transit-only usage.';
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
      const applies = [
        rule.apply_pickup ? 'Pickup' : null,
        rule.apply_delivery ? 'Delivery' : null,
        rule.apply_transit ? 'Transit' : null,
      ].filter(Boolean).join(', ') || 'None';
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${formatDnfScope(rule.scope_type)}</td>
        <td>${formatDnfTarget(rule)}</td>
        <td>${applies}</td>
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

  document.getElementById('save-contract-validation')?.addEventListener('click', async () => {
    const checks = {
      type: document.getElementById('contract-validate-type')?.checked ?? false,
      start_system: document.getElementById('contract-validate-start')?.checked ?? false,
      end_system: document.getElementById('contract-validate-end')?.checked ?? false,
      volume: document.getElementById('contract-validate-volume')?.checked ?? false,
    };
    await fetchJson(`${basePath}/api/admin/contract-link-validation/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        checks,
      }),
    });
    loadContractValidation();
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

  document.getElementById('save-optimization')?.addEventListener('click', async () => {
    const enabled = document.getElementById('optimization-enabled')?.checked ?? false;
    const detourBudget = parseInt(document.getElementById('optimization-detour-jumps')?.value || '5', 10);
    const maxSuggestions = parseInt(document.getElementById('optimization-max-suggestions')?.value || '5', 10);
    const minFree = parseFloat(document.getElementById('optimization-min-free')?.value || '10');
    await fetchJson(`${basePath}/api/admin/route-optimization/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        enabled,
        detour_budget_jumps: Number.isFinite(detourBudget) ? detourBudget : 5,
        max_suggestions: Number.isFinite(maxSuggestions) ? maxSuggestions : 5,
        min_free_capacity_percent: Number.isFinite(minFree) ? minFree : 10,
      }),
    });
    loadOptimization();
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

  document.getElementById('save-security-classes')?.addEventListener('click', async () => {
    const payload = {
      corp_id: corpId,
      thresholds: {
        highsec_min: parseFloat(document.getElementById('security-highsec-min')?.value || '0.5'),
        lowsec_min: parseFloat(document.getElementById('security-lowsec-min')?.value || '0.1'),
      },
      special: {
        pochven: {
          enabled: document.getElementById('special-pochven-enabled')?.checked ?? true,
          region_names: parseList(document.getElementById('special-pochven-regions')?.value),
          system_names: [],
        },
        zarzakh: {
          enabled: document.getElementById('special-zarzakh-enabled')?.checked ?? true,
          region_names: [],
          system_names: parseList(document.getElementById('special-zarzakh-systems')?.value),
        },
        thera: {
          enabled: document.getElementById('special-thera-enabled')?.checked ?? false,
          region_names: [],
          system_names: parseList(document.getElementById('special-thera-systems')?.value),
        },
      },
    };
    await fetchJson(`${basePath}/api/admin/security-classes/`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    loadSecurityClasses();
  });

  document.getElementById('save-security-routing')?.addEventListener('click', async () => {
    const rows = document.querySelectorAll('#security-routing-table tbody tr');
    const rules = {};
    rows.forEach((row) => {
      const key = row.dataset.classKey;
      if (!key) return;
      const inputs = row.querySelectorAll('input[data-field]');
      const entry = {};
      inputs.forEach((input) => {
        entry[input.dataset.field] = input.checked;
      });
      rules[key] = entry;
    });
    await fetchJson(`${basePath}/api/admin/security-routing/`, {
      method: 'POST',
      body: JSON.stringify({ corp_id: corpId, rules }),
    });
    loadSecurityRoutingRules();
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

  document.getElementById('dnf-add')?.addEventListener('click', async () => {
    const scopeType = document.getElementById('dnf-scope').value;
    const selection = parseDnfSelection(document.getElementById('dnf-name-a').value, scopeType);
    const payload = {
      scope_type: scopeType,
      name_a: selection.name,
      id_a: selection.id || undefined,
      severity: parseInt(document.getElementById('dnf-severity').value || '1', 10),
      apply_pickup: document.getElementById('dnf-apply-pickup')?.checked ?? true,
      apply_delivery: document.getElementById('dnf-apply-delivery')?.checked ?? true,
      apply_transit: document.getElementById('dnf-apply-transit')?.checked ?? true,
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
  loadContractAttach();
  loadContractValidation();
  loadQuoteLocations();
  loadOperationsDispatch();
  loadOptimization();
  loadTolerance();
  loadSecurityClasses();
  loadSecurityRoutingRules();
  loadBuyback();
  loadDnfRules();
  updateDnfListTarget();
})();
