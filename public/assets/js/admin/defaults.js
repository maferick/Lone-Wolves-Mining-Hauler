(() => {
  const root = document.querySelector('.js-defaults-config');
  if (!root) return;
  const parseData = (value) => {
    if (!value) return [];
    try {
      return JSON.parse(value);
    } catch (err) {
      return [];
    }
  };
  const accessData = {
    system: parseData(root.dataset.accessSystems),
    region: parseData(root.dataset.accessRegions),
    structure: parseData(root.dataset.accessStructures),
  };
  const structureSearchUrl = root.dataset.structureSearchUrl || '';
  const minChars = 2;
  const typeSelect = document.getElementById('access-rule-type');
  const valueInput = document.getElementById('access-rule-value');
  const structureFlags = document.getElementById('access-structure-flags');
  const listMap = {
    system: document.getElementById('access-systems-list'),
    region: document.getElementById('access-regions-list'),
    structure: document.getElementById('access-structures-list'),
  };

  const buildOptions = (listEl, items, value) => {
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!value || value.length < minChars) return;
    const query = value.toLowerCase().trim();
    if (!query) return;
    let count = 0;
    for (const item of items || []) {
      const itemName = item.name?.toLowerCase() ?? '';
      const systemName = item.system_name?.toLowerCase() ?? '';
      if (!itemName.includes(query) && !systemName.includes(query)) continue;
      const option = document.createElement('option');
      const display = systemName
        ? `${item.system_name} - ${item.name}`
        : item.name;
      option.value = `${display} [${item.id}]`;
      listEl.appendChild(option);
      count += 1;
      if (count >= 50) break;
    }
  };

  const buildStructureOptions = (listEl, items) => {
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!Array.isArray(items)) return;
    let count = 0;
    for (const item of items) {
      const label = item.label || item.name || '';
      const id = item.value ?? item.station_id ?? item.id;
      if (!label || !id) continue;
      const option = document.createElement('option');
      option.value = `${label} [${id}]`;
      listEl.appendChild(option);
      count += 1;
      if (count >= 10) break;
    }
  };

  let structureSearchTimer = null;
  let structureSearchSeq = 0;

  const fetchStructureOptions = (value) => {
    const listEl = listMap.structure;
    if (!listEl) return;
    if (!value || value.length < minChars) {
      listEl.innerHTML = '';
      return;
    }
    const query = value.trim();
    if (!query) {
      listEl.innerHTML = '';
      return;
    }
    if (!structureSearchUrl) {
      const fallbackItems = (accessData.structure || []).filter((item) => {
        const name = item.name?.trim() ?? '';
        return !/^station\s+\d+$/i.test(name);
      });
      buildOptions(listEl, fallbackItems, value);
      return;
    }
    if (structureSearchTimer) {
      clearTimeout(structureSearchTimer);
    }
    const requestId = ++structureSearchSeq;
    structureSearchTimer = setTimeout(async () => {
      try {
        const resp = await fetch(`${structureSearchUrl}?q=${encodeURIComponent(query)}`, {
          headers: { Accept: 'application/json' },
        });
        if (!resp.ok) return;
        const payload = await resp.json();
        if (requestId !== structureSearchSeq) return;
        const items = Array.isArray(payload.items) ? payload.items : [];
        buildStructureOptions(listEl, items);
      } catch (err) {
        const fallbackItems = (accessData.structure || []).filter((item) => {
          const name = item.name?.trim() ?? '';
          return !/^station\s+\d+$/i.test(name);
        });
        buildOptions(listEl, fallbackItems, value);
      }
    }, 200);
  };

  const updateListTarget = () => {
    const type = typeSelect?.value || 'system';
    const listEl = listMap[type] || listMap.system;
    if (valueInput && listEl) {
      valueInput.setAttribute('list', listEl.id);
      if (type === 'structure') {
        fetchStructureOptions(valueInput.value);
      } else {
        buildOptions(listEl, accessData[type], valueInput.value);
      }
    }
    if (structureFlags) {
      structureFlags.style.display = type === 'structure' ? 'block' : 'none';
    }
  };

  typeSelect?.addEventListener('change', updateListTarget);
  valueInput?.addEventListener('input', () => {
    const type = typeSelect?.value || 'system';
    if (type === 'structure') {
      fetchStructureOptions(valueInput.value);
    } else {
      buildOptions(listMap[type], accessData[type], valueInput.value);
    }
  });

  const pagerRenders = new WeakMap();
  const removeButtons = document.querySelectorAll('.js-remove-row');
  removeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('tr');
      if (!row) return;
      const container = button.closest('[data-paginated-table]');
      row.remove();
      const render = container ? pagerRenders.get(container) : null;
      if (render) render();
    });
  });

  const setupPager = (container) => {
    const table = container.querySelector('[data-pager-table]');
    const footer = container.querySelector('[data-pager-footer]');
    if (!table || !footer) return;
    const summary = container.querySelector('[data-pager-summary]');
    const pageLabel = container.querySelector('[data-pager-page]');
    const prevBtn = container.querySelector('[data-pager-prev]');
    const nextBtn = container.querySelector('[data-pager-next]');
    const sizeSelect = container.querySelector('[data-pager-size]');
    let pageSize = parseInt(container.dataset.pageSize || '10', 10);
    if (sizeSelect) sizeSelect.value = String(pageSize);
    let currentPage = 1;
    const getRows = () => Array.from(table.tBodies[0]?.rows ?? []);

    const render = () => {
      const rows = getRows();
      const total = rows.length;
      const totalPages = Math.max(1, Math.ceil(total / pageSize));
      currentPage = Math.min(currentPage, totalPages);
      const startIdx = (currentPage - 1) * pageSize;
      const endIdx = startIdx + pageSize;
      rows.forEach((row, idx) => {
        row.style.display = idx >= startIdx && idx < endIdx ? '' : 'none';
      });
      if (summary) {
        const start = total === 0 ? 0 : startIdx + 1;
        const end = Math.min(endIdx, total);
        summary.textContent = `Showing ${start}-${end} of ${total}`;
      }
      if (pageLabel) {
        pageLabel.textContent = `Page ${currentPage} of ${totalPages}`;
      }
      if (prevBtn) prevBtn.disabled = currentPage <= 1;
      if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
      footer.style.display = total > 0 ? 'flex' : 'none';
    };

    prevBtn?.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage -= 1;
        render();
      }
    });
    nextBtn?.addEventListener('click', () => {
      const rows = getRows();
      if (currentPage < Math.ceil(rows.length / pageSize)) {
        currentPage += 1;
        render();
      }
    });
    sizeSelect?.addEventListener('change', () => {
      const nextSize = parseInt(sizeSelect.value, 10);
      if (Number.isFinite(nextSize) && nextSize > 0) {
        pageSize = nextSize;
        currentPage = 1;
        render();
      }
    });

    render();
    pagerRenders.set(container, render);
  };

  document.querySelectorAll('[data-paginated-table]').forEach(setupPager);

  updateListTarget();
})();
