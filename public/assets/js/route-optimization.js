(() => {
  const root = document.querySelector('.js-route-optimization');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const url = root.dataset.optimizationUrl || `${basePath}/api/hauler/route-optimization/`;
  const statusEl = root.querySelector('[data-optimization-status]');
  const summaryEl = root.querySelector('[data-optimization-summary]');
  const utilizationEl = root.querySelector('[data-optimization-utilization]');
  const freeEl = root.querySelector('[data-optimization-free]');
  const table = root.querySelector('[data-optimization-table]');
  const tbody = table?.querySelector('tbody');

  const formatNumber = (value, decimals = 0) => {
    const num = Number(value || 0);
    return num.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  };

  const setStatus = (text) => {
    if (statusEl) statusEl.textContent = text;
  };

  const updateSummary = (summary) => {
    if (!summaryEl || !summary) return;
    const utilization = summary.utilization_percent ?? 0;
    const available = summary.available_m3 ?? 0;
    if (utilizationEl) {
      utilizationEl.textContent = `${formatNumber(utilization, 1)}%`;
    }
    if (freeEl) {
      freeEl.textContent = `${formatNumber(available, 0)} m³`;
    }
    summaryEl.style.display = 'block';
  };

  const updateTable = (suggestions) => {
    if (!table || !tbody) return;
    tbody.innerHTML = '';
    if (!suggestions.length) {
      table.style.display = 'none';
      return;
    }
    suggestions.forEach((item) => {
      const row = document.createElement('tr');
      const requestCode = item.request_code || item.request_id;
      const link = item.request_key ? `${basePath}/request?request_key=${encodeURIComponent(item.request_key)}` : '';
      row.innerHTML = `
        <td>${requestCode}</td>
        <td>${item.pickup_label || '—'}</td>
        <td>${item.delivery_label || '—'}</td>
        <td>${formatNumber(item.volume_m3, 0)} m³</td>
        <td>${formatNumber(item.extra_jumps, 0)}</td>
        <td>${link ? `<a class="btn ghost" href="${link}">View Request</a>` : '<span class="muted">No link</span>'}</td>
      `;
      tbody.appendChild(row);
    });
    table.style.display = '';
  };

  const loadOptimization = async () => {
    try {
      const resp = await fetch(url);
      const data = await resp.json();
      if (!data.ok) {
        setStatus(data.error || 'Unable to load optimization data.');
        return;
      }
      if (data.summary) {
        updateSummary(data.summary);
      }
      const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
      updateTable(suggestions);

      if (suggestions.length > 0) {
        setStatus('Suggested nearby loads that match your spare capacity.');
        return;
      }

      const reasonMap = {
        no_active_haul: 'No active haul assigned yet. Suggestions appear once you have a live run.',
        graph_not_loaded: 'Routing graph not loaded. Optimization suggestions are unavailable.',
        below_min_free_capacity: 'Spare capacity below the minimum threshold for optimization.',
        disabled: 'Route optimization is disabled by admin settings.',
        no_capacity_profile: 'Set your hauler capability profile to receive suggestions.',
      };
      setStatus(reasonMap[data.reason] || 'No nearby loads detected for this route.');
    } catch (err) {
      setStatus('Unable to load optimization data.');
    }
  };

  loadOptimization();
})();
