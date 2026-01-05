(() => {
  const card = document.querySelector('.card[data-initial-alliances]');
  if (!card) return;

  const basePath = card.dataset.basePath || '';
  const accessScope = document.getElementById('access-scope');
  const alliancePicker = document.getElementById('alliance-picker');
  const allianceSearch = document.getElementById('alliance-search');
  const allianceResults = document.getElementById('alliance-results');
  const allianceSelected = document.getElementById('alliance-selected');
  const alliancesJsonInput = document.getElementById('alliances-json');
  const statusNote = document.getElementById('alliance-search-status');
  const debugEnabled = new URLSearchParams(window.location.search).get('debug') === '1';

  let selected = [];
  try {
    selected = JSON.parse(card.dataset.initialAlliances || '[]');
    if (!Array.isArray(selected)) selected = [];
  } catch (err) {
    selected = [];
  }

  const updatePickerVisibility = () => {
    if (!alliancePicker) return;
    alliancePicker.style.display = accessScope?.value === 'alliances' ? 'block' : 'none';
  };

  const syncHiddenInput = () => {
    if (!alliancesJsonInput) return;
    alliancesJsonInput.value = JSON.stringify(selected);
  };

  const renderSelected = () => {
    if (!allianceSelected) return;
    allianceSelected.innerHTML = '';

    if (selected.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'muted';
      empty.textContent = 'No alliances selected.';
      allianceSelected.appendChild(empty);
      return;
    }

    selected.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'row';
      row.style.alignItems = 'center';

      const label = document.createElement('div');
      label.className = 'pill';
      label.textContent = item.name;

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn ghost';
      remove.textContent = 'Remove';
      remove.addEventListener('click', () => {
        selected = selected.filter((entry) => entry.id !== item.id);
        syncHiddenInput();
        renderSelected();
      });

      row.appendChild(label);
      row.appendChild(remove);
      allianceSelected.appendChild(row);
    });
  };

  const renderResults = (alliances) => {
    if (!allianceResults) return;
    allianceResults.innerHTML = '';

    if (alliances.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'muted';
      empty.textContent = 'No alliances found.';
      allianceResults.appendChild(empty);
      return;
    }

    alliances.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'row';
      row.style.alignItems = 'center';

      const label = document.createElement('div');
      label.className = 'pill';
      label.textContent = item.name;

      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'btn';
      add.textContent = 'Add';
      add.disabled = selected.some((entry) => entry.id === item.id);
      add.addEventListener('click', () => {
        if (selected.some((entry) => entry.id === item.id)) return;
        selected = [...selected, item];
        syncHiddenInput();
        renderSelected();
        renderResults(alliances);
      });

      row.appendChild(label);
      row.appendChild(add);
      allianceResults.appendChild(row);
    });
  };

  const searchAlliances = async (query) => {
    if (!statusNote || !allianceResults) return;
    if (query.length < 3) {
      allianceResults.innerHTML = '';
      statusNote.textContent = 'Type at least 3 characters to search.';
      return;
    }

    statusNote.textContent = 'Searching...';
    try {
      const params = new URLSearchParams({ q: query });
      if (debugEnabled) params.set('debug', '1');
      const resp = await fetch(`${basePath}/api/admin/alliances/search/?${params.toString()}`);
      const data = await resp.json();
      if (debugEnabled) {
        console.debug('Alliance search response', data);
      }
      if (!data.ok) {
        statusNote.textContent = data.error || 'Search failed.';
        return;
      }
      statusNote.textContent = data.warning || '';
      renderResults(data.alliances || []);
    } catch (err) {
      statusNote.textContent = 'Search failed.';
    }
  };

  let searchTimer = null;
  allianceSearch?.addEventListener('input', () => {
    const query = allianceSearch.value.trim();
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
      searchAlliances(query);
    }, 300);
  });

  accessScope?.addEventListener('change', updatePickerVisibility);

  updatePickerVisibility();
  syncHiddenInput();
  renderSelected();
})();
