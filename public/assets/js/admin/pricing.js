(() => {
  const root = document.querySelector('section.card[data-base-path][data-corp-id]');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const corpId = root.dataset.corpId || '';

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

  const showRatePlanError = (message) => {
    const table = document.getElementById('rate-plan-table');
    if (!table) return;
    let notice = document.getElementById('rate-plan-error');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'rate-plan-error';
      notice.className = 'muted';
      notice.style.marginTop = '8px';
      table.insertAdjacentElement('afterend', notice);
    }
    notice.textContent = message;
  };

  const clearRatePlanError = () => {
    const notice = document.getElementById('rate-plan-error');
    if (notice) notice.textContent = '';
  };

  const allowedServiceClasses = new Set(['BR', 'DST', 'FREIGHTER', 'JF']);

  const loadPriorityFees = async () => {
    const data = await fetchJson(`${basePath}/api/admin/priority-fee/?corp_id=${corpId}`);
    if (!data.ok) return;
    const fees = data.priority_fee || {};
    const normalInput = document.getElementById('priority-fee-normal');
    const highInput = document.getElementById('priority-fee-high');
    if (normalInput) normalInput.value = fees.normal ?? 0;
    if (highInput) highInput.value = fees.high ?? 0;
  };

  const loadRatePlans = async () => {
    const data = await fetchJson(`${basePath}/api/admin/rate-plan/?corp_id=${corpId}`);
    const tbody = document.querySelector('#rate-plan-table tbody');
    tbody.innerHTML = '';
    clearRatePlanError();
    (data.rate_plans || []).forEach((plan) => {
      const row = document.createElement('tr');
      row.dataset.serviceClass = plan.service_class || '';
      row.innerHTML = `
        <td>${plan.service_class}</td>
        <td><input class="input" data-field="rate_per_jump" type="number" step="0.01" value="${plan.rate_per_jump}" /></td>
        <td><input class="input" data-field="collateral_rate" type="number" step="0.0001" value="${plan.collateral_rate}" /></td>
        <td><input class="input" data-field="min_price" type="number" step="0.01" value="${plan.min_price}" /></td>
        <td><button class="btn ghost" data-action="save" data-id="${plan.rate_plan_id}" data-service-class="${plan.service_class}">Save</button></td>
      `;
      tbody.appendChild(row);
    });
  };

  const loadSecurityMultipliers = async () => {
    const data = await fetchJson(`${basePath}/api/admin/security-multipliers/?corp_id=${corpId}`);
    if (!data.ok) return;
    const tbody = document.querySelector('#security-multiplier-table tbody');
    const multipliers = data.multipliers || {};
    const labels = {
      high: 'High-sec',
      low: 'Low-sec',
      null: 'Null-sec',
      pochven: 'Pochven',
      zarzakh: 'Zarzakh',
      thera: 'Thera',
    };
    tbody.innerHTML = '';
    Object.keys(labels).forEach((key) => {
      const row = document.createElement('tr');
      row.dataset.classKey = key;
      row.innerHTML = `
        <td>${labels[key]}</td>
        <td><input class="input" data-field="multiplier" type="number" step="0.01" min="0" value="${multipliers[key] ?? 1}" /></td>
      `;
      tbody.appendChild(row);
    });
    const note = document.getElementById('security-multipliers-note');
    if (note) note.textContent = 'Multipliers apply per jump by security class.';
  };

  const loadFlatRiskFees = async () => {
    const data = await fetchJson(`${basePath}/api/admin/flat-risk/?corp_id=${corpId}`);
    if (!data.ok) return;
    const fees = data.fees || {};
    document.getElementById('flat-risk-lowsec').value = fees.lowsec ?? 0;
    document.getElementById('flat-risk-nullsec').value = fees.nullsec ?? 0;
    document.getElementById('flat-risk-special').value = fees.special ?? 0;
    const note = document.getElementById('flat-risk-note');
    if (note) note.textContent = 'Flat fees are added once when a route touches the space type.';
  };

  const loadMaxCollateral = async () => {
    const data = await fetchJson(`${basePath}/api/admin/max-collateral/?corp_id=${corpId}`);
    if (!data.ok) return;
    const input = document.getElementById('max-collateral-isk');
    if (input) input.value = data.max_collateral_isk ?? '';
    const note = document.getElementById('max-collateral-note');
    if (note) note.textContent = 'Quotes above this collateral will be rejected.';
  };

  const loadVolumePressure = async () => {
    const data = await fetchJson(`${basePath}/api/admin/volume-pressure/?corp_id=${corpId}`);
    if (!data.ok) return;
    const enabled = !!data.enabled;
    const thresholds = Array.isArray(data.thresholds) ? data.thresholds : [];
    const enabledInput = document.getElementById('volume-pressure-enabled');
    const tbody = document.querySelector('#volume-pressure-table tbody');
    if (enabledInput) enabledInput.checked = enabled;
    tbody.innerHTML = '';
    thresholds.forEach((threshold) => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td><input class="input" data-field="threshold_pct" type="number" step="1" min="0" max="100" value="${threshold.threshold_pct ?? ''}" /></td>
        <td><input class="input" data-field="surcharge_pct" type="number" step="0.1" min="0" value="${threshold.surcharge_pct ?? ''}" /></td>
        <td><button class="btn ghost" data-action="remove">Remove</button></td>
      `;
      tbody.appendChild(row);
    });
    const note = document.getElementById('volume-pressure-note');
    if (note) note.textContent = enabled ? 'Scaling applies to the hauling subtotal.' : 'Enable to apply capacity pressure scaling.';
  };

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

  document.getElementById('rate-plan-table')?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || target.dataset.action !== 'save') return;
    const row = target.closest('tr');
    if (!row) return;
    clearRatePlanError();
    const inputs = row.querySelectorAll('input[data-field]');
    const fallbackClass = row.querySelector('td')?.textContent?.trim() ?? '';
    const rawServiceClass = target.dataset.serviceClass || row.dataset.serviceClass || fallbackClass;
    const serviceClass = rawServiceClass.trim().toUpperCase();
    if (!allowedServiceClasses.has(serviceClass)) {
      showRatePlanError('Could not determine service class for this row. Refresh and try again.');
      return;
    }
    const payload = { rate_plan_id: target.dataset.id, service_class: serviceClass };
    inputs.forEach((input) => {
      payload[input.dataset.field] = parseFloat(input.value || '0');
    });
    await fetchJson(`${basePath}/api/admin/rate-plan/`, {
      method: 'POST',
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

  document.getElementById('save-security-multipliers')?.addEventListener('click', async () => {
    const multipliers = {};
    const rows = document.querySelectorAll('#security-multiplier-table tbody tr');
    rows.forEach((row) => {
      const key = row.dataset.classKey;
      if (!key) return;
      const input = row.querySelector('input[data-field="multiplier"]');
      const value = parseFloat(input?.value || '1');
      multipliers[key] = Number.isFinite(value) ? value : 1;
    });
    await fetchJson(`${basePath}/api/admin/security-multipliers/`, {
      method: 'POST',
      body: JSON.stringify({ corp_id: corpId, multipliers }),
    });
    loadSecurityMultipliers();
  });

  document.getElementById('save-flat-risk')?.addEventListener('click', async () => {
    const payload = {
      corp_id: corpId,
      lowsec: parseFloat(document.getElementById('flat-risk-lowsec')?.value || '0'),
      nullsec: parseFloat(document.getElementById('flat-risk-nullsec')?.value || '0'),
      special: parseFloat(document.getElementById('flat-risk-special')?.value || '0'),
    };
    await fetchJson(`${basePath}/api/admin/flat-risk/`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    loadFlatRiskFees();
  });

  document.getElementById('save-max-collateral')?.addEventListener('click', async () => {
    const value = parseFloat(document.getElementById('max-collateral-isk')?.value || '0');
    await fetchJson(`${basePath}/api/admin/max-collateral/`, {
      method: 'POST',
      body: JSON.stringify({
        corp_id: corpId,
        max_collateral_isk: Number.isFinite(value) ? value : 0,
      }),
    });
    loadMaxCollateral();
  });

  document.getElementById('add-volume-pressure')?.addEventListener('click', () => {
    const tbody = document.querySelector('#volume-pressure-table tbody');
    if (!tbody) return;
    const row = document.createElement('tr');
    row.innerHTML = `
      <td><input class="input" data-field="threshold_pct" type="number" step="1" min="0" max="100" value="" /></td>
      <td><input class="input" data-field="surcharge_pct" type="number" step="0.1" min="0" value="" /></td>
      <td><button class="btn ghost" data-action="remove">Remove</button></td>
    `;
    tbody.appendChild(row);
  });

  document.getElementById('volume-pressure-table')?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || target.dataset.action !== 'remove') return;
    const row = target.closest('tr');
    row?.remove();
  });

  document.getElementById('save-volume-pressure')?.addEventListener('click', async () => {
    const enabled = document.getElementById('volume-pressure-enabled')?.checked ?? false;
    const rows = document.querySelectorAll('#volume-pressure-table tbody tr');
    const thresholds = [];
    rows.forEach((row) => {
      const thresholdInput = row.querySelector('input[data-field="threshold_pct"]');
      const surchargeInput = row.querySelector('input[data-field="surcharge_pct"]');
      const thresholdPct = parseFloat(thresholdInput?.value || '0');
      const surchargePct = parseFloat(surchargeInput?.value || '0');
      if (Number.isFinite(thresholdPct) && Number.isFinite(surchargePct)) {
        thresholds.push({
          threshold_pct: thresholdPct,
          surcharge_pct: surchargePct,
        });
      }
    });
    await fetchJson(`${basePath}/api/admin/volume-pressure/`, {
      method: 'POST',
      body: JSON.stringify({ corp_id: corpId, enabled, thresholds }),
    });
    loadVolumePressure();
  });

  loadPriorityFees();
  loadRatePlans();
  loadSecurityMultipliers();
  loadFlatRiskFees();
  loadMaxCollateral();
  loadVolumePressure();
})();
