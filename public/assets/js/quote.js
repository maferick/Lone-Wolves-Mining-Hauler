(() => {
  const root = document.querySelector('.js-quote-form');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const minChars = 3;
  let buybackTiers = [];
  if (root.dataset.buybackTiers) {
    try {
      buybackTiers = JSON.parse(root.dataset.buybackTiers);
    } catch (err) {
      buybackTiers = [];
    }
  }
  const buybackEnabled = root.dataset.buybackEnabled === '1';

  const buildOptions = (listEl, items, value) => {
    listEl.innerHTML = '';
    if (!value || value.length < minChars) return;
    for (const item of items || []) {
      const displayName = item?.name;
      if (!displayName) continue;
      const option = document.createElement('option');
      option.value = displayName;
      listEl.appendChild(option);
    }
  };

  const pickupInput = document.querySelector('input[name="pickup_location"]');
  const destinationInput = document.querySelector('input[name="delivery_location"]');
  const pickupLocationIdInput = document.querySelector('input[name="pickup_location_id"]');
  const pickupLocationTypeInput = document.querySelector('input[name="pickup_location_type"]');
  const pickupSystemIdInput = document.querySelector('input[name="pickup_system_id"]');
  const deliveryLocationIdInput = document.querySelector('input[name="delivery_location_id"]');
  const deliveryLocationTypeInput = document.querySelector('input[name="delivery_location_type"]');
  const deliverySystemIdInput = document.querySelector('input[name="delivery_system_id"]');
  const volumeInput = document.querySelector('input[name="volume_m3"]');
  const collateralInput = document.querySelector('input[name="collateral"]');
  const priorityInput = document.querySelector('select[name="priority"]');
  const pickupList = document.getElementById('pickup-location-list');
  const destinationList = document.getElementById('destination-location-list');
  const submitBtn = document.getElementById('quote-submit');
  const errorEl = document.getElementById('quote-error');
  const resultEl = document.getElementById('quote-result');
  const breakdownCard = document.getElementById('quote-breakdown');
  const breakdownContent = document.getElementById('quote-breakdown-content');
  const createRequestBtn = document.getElementById('quote-create-request');
  const canCreateRequest = createRequestBtn ? !createRequestBtn.hasAttribute('disabled') : false;
  const requestLink = document.getElementById('quote-request-link');
  const requestStatus = document.getElementById('quote-request-status');
  const buybackBtn = document.getElementById('buyback-haulage-btn');
  let currentQuoteId = null;
  const locationQueryIds = { pickup: 0, destination: 0 };
  const locationTimers = { pickup: null, destination: null };
  const pickupSuggestionMap = new Map();
  const deliverySuggestionMap = new Map();

  const normalizeLabel = (value) => value
    ?.toString()
    .toLowerCase()
    .replace(/[—–]/g, '-')
    .replace(/\s+/g, ' ')
    .trim() || '';

  const setSuggestions = (type, items) => {
    const targetMap = type === 'pickup' ? pickupSuggestionMap : deliverySuggestionMap;
    targetMap.clear();
    for (const item of items || []) {
      const displayName = item?.name;
      if (!displayName) continue;
      targetMap.set(normalizeLabel(displayName), item);
    }
  };

  const storeLocations = (type, items) => {
    setSuggestions(type, items);
  };

  const applyLocationSelection = (type, value) => {
    const lookup = normalizeLabel(value);
    const targetMap = type === 'pickup' ? pickupSuggestionMap : deliverySuggestionMap;
    const item = lookup ? targetMap.get(lookup) : null;
    const inputEl = type === 'pickup' ? pickupInput : destinationInput;
    const setFields = (idInput, typeInput, systemInput) => {
      if (!idInput || !typeInput || !systemInput) return;
      if (!item) {
        idInput.value = '';
        typeInput.value = '';
        systemInput.value = '';
        return;
      }
      idInput.value = item.location_id ?? '';
      typeInput.value = item.location_type ?? '';
      systemInput.value = item.system_id ?? '';
    };

    if (type === 'pickup') {
      setFields(pickupLocationIdInput, pickupLocationTypeInput, pickupSystemIdInput);
    } else {
      setFields(deliveryLocationIdInput, deliveryLocationTypeInput, deliverySystemIdInput);
    }

    if (!inputEl) return;
    if (item) {
      inputEl.classList.remove('input--error');
      return;
    }
    if (lookup) {
      inputEl.classList.add('input--error');
    } else {
      inputEl.classList.remove('input--error');
    }
  };

  const showError = (msg) => {
    if (!errorEl) return;
    errorEl.textContent = msg;
    errorEl.style.display = 'block';
  };

  const hideError = () => {
    if (!errorEl) return;
    errorEl.style.display = 'none';
    errorEl.textContent = '';
  };

  const showResult = (msg) => {
    if (!resultEl) return;
    resultEl.textContent = msg;
    resultEl.style.display = 'block';
  };

  const hideResult = () => {
    if (!resultEl) return;
    resultEl.style.display = 'none';
    resultEl.textContent = '';
  };

  const hasValidSelection = (idInput, typeInput) => {
    const idValue = parseInt(idInput?.value || '0', 10);
    const typeValue = typeInput?.value || '';
    return idValue > 0 && typeValue !== '';
  };

  const parseIsk = (value) => {
    if (!value) return null;
    const clean = value.toString().trim().toLowerCase().replace(/[, ]+/g, '');
    const match = clean.match(/^([0-9]+(?:\.[0-9]+)?)([kmb])?$/);
    if (!match) return null;
    const amount = parseFloat(match[1]);
    const suffix = match[2];
    const mult = suffix === 'b' ? 1e9 : suffix === 'm' ? 1e6 : suffix === 'k' ? 1e3 : 1;
    return amount * mult;
  };

  const fmtIsk = (value) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value);
  const fmtSecurity = (value) => {
    const num = Number(value);
    if (!Number.isFinite(num)) return '0.0';
    return num.toFixed(1);
  };

  const renderQuoteBreakdownLikeHome = (data, basePath) => {
    if (!breakdownContent || !breakdownCard) return;
    const breakdown = data.breakdown || {};
    const route = data.route || {};
    const ship = breakdown.ship_class || {};
    const security = breakdown.security_counts || {};
    const penalties = breakdown.penalties || {};
    const ratePlan = breakdown.rate_plan || {};
    const costs = breakdown.costs || {};
    const path = route.path || [];
    const first = path[0]?.system_name || '—';
    const last = path[path.length - 1]?.system_name || '—';
    const pathNames = path
      .map((p) => `${p.system_name} (${fmtSecurity(p.security)})`)
      .join(' → ');
    const fromLocation = breakdown.inputs?.from_location || {};
    const toLocation = breakdown.inputs?.to_location || {};
    const pickupLabel = fromLocation.display_name || fromLocation.location_name || fromLocation.system_name || first;
    const deliveryLabel = toLocation.display_name || toLocation.location_name || toLocation.system_name || last;

    const totalPrice = data.total_price_isk ?? data.price_total_isk ?? 0;
    breakdownContent.innerHTML = `
      <div class="row">
        <div>
          <div class="label">Ship class</div>
          <div>${ship.service_class || '—'} (max ${ship.max_volume || '—'} m³)</div>
        </div>
        <div>
          <div class="label">Price total</div>
          <div>${fmtIsk(totalPrice)} ISK</div>
        </div>
        <div>
          <div class="label">Route</div>
          <div>${first} → ${last}</div>
          <button class="btn ghost" type="button" id="toggle-route">View route</button>
          <div class="muted" id="route-path" style="display:none; margin-top:8px;">${pathNames}</div>
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <div>
          <div class="label">Jumps</div>
          <div>${route.jumps ?? 0} (HS ${security.high ?? 0} / LS ${security.low ?? 0} / NS ${security.null ?? 0})</div>
        </div>
        <div>
          <div class="label">Rate plan</div>
          <div>${ratePlan.service_class || '—'} • ${fmtIsk(ratePlan.rate_per_jump || 0)} per jump • ${((ratePlan.collateral_rate || 0) * 100).toFixed(2)}% collateral</div>
        </div>
        <div>
          <div class="label">Minimum price</div>
          <div>${fmtIsk(ratePlan.min_price || 0)} ISK</div>
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <div>
          <div class="label">Pickup location</div>
          <div>${pickupLabel || '—'}</div>
        </div>
        <div>
          <div class="label">Delivery location</div>
          <div>${deliveryLabel || '—'}</div>
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <div>
          <div class="label">Penalties</div>
          <div>Low-sec ${fmtIsk(penalties.lowsec || 0)} • Null-sec ${fmtIsk(penalties.nullsec || 0)} • Soft DNF ${fmtIsk(penalties.soft_dnf_total || 0)}</div>
        </div>
        <div>
          <div class="label">Costs</div>
          <div>Jump ${fmtIsk(costs.jump_subtotal || 0)} • Collateral ${fmtIsk(costs.collateral_fee || 0)} • Priority ${fmtIsk(costs.priority_fee || 0)}</div>
        </div>
        <div>
          <div class="label">DNF notes</div>
          <div>${(penalties.soft_dnf || []).map(r => r.reason || 'Rule').join(', ') || 'None'}</div>
        </div>
      </div>
    `;

    const toggleBtn = document.getElementById('toggle-route');
    const routePath = document.getElementById('route-path');
    toggleBtn?.addEventListener('click', () => {
      if (!routePath) return;
      const visible = routePath.style.display !== 'none';
      routePath.style.display = visible ? 'none' : 'block';
      toggleBtn.textContent = visible ? 'View route' : 'Hide route';
    });

    breakdownCard.style.display = 'block';
  };

  const fetchLocations = async (value, type, listEl) => {
    if (!value || value.length < minChars) {
      listEl.innerHTML = '';
      return;
    }
    const queryId = ++locationQueryIds[type];
    const url = `${basePath}/api/locations/search/?q=${encodeURIComponent(value)}&type=${encodeURIComponent(type)}`;
    try {
      const resp = await fetch(url, { credentials: 'same-origin' });
      const data = await resp.json();
      if (queryId !== locationQueryIds[type]) return;
      if (!data || !data.ok) return;
      storeLocations(type, data.items || []);
      buildOptions(listEl, data.items || [], value);
    } catch (err) {
      if (queryId !== locationQueryIds[type]) return;
      listEl.innerHTML = '';
    }
  };

  pickupInput?.addEventListener('input', () => {
    if (locationTimers.pickup) {
      clearTimeout(locationTimers.pickup);
    }
    locationTimers.pickup = setTimeout(() => {
      fetchLocations(pickupInput.value, 'pickup', pickupList);
    }, 150);
  });
  pickupInput?.addEventListener('change', () => applyLocationSelection('pickup', pickupInput.value));
  pickupInput?.addEventListener('blur', () => applyLocationSelection('pickup', pickupInput.value));
  destinationInput?.addEventListener('input', () => {
    if (locationTimers.destination) {
      clearTimeout(locationTimers.destination);
    }
    locationTimers.destination = setTimeout(() => {
      fetchLocations(destinationInput.value, 'destination', destinationList);
    }, 150);
  });
  destinationInput?.addEventListener('change', () => applyLocationSelection('destination', destinationInput.value));
  destinationInput?.addEventListener('blur', () => applyLocationSelection('destination', destinationInput.value));

  const fetchQuote = async (payload, fallbackError) => {
    hideError();
    hideResult();
    if (breakdownCard) breakdownCard.style.display = 'none';
    if (submitBtn) submitBtn.textContent = 'Calculating…';
    try {
      const resp = await fetch(`${basePath}/api/quote/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await resp.json();
      if (!resp.ok || !data.ok) {
        let friendlyError = data.error || fallbackError;
        if (data.error && data.error.includes('location')) {
          friendlyError = 'We could not match one of the locations. Check spelling or pick from the list.';
        }
        showError(friendlyError);
        return null;
      }
      return data;
    } catch (err) {
      showError(fallbackError);
      return null;
    } finally {
      if (submitBtn) submitBtn.textContent = 'Get Quote';
    }
  };

  submitBtn?.addEventListener('click', async () => {
    const collateralValue = parseIsk(collateralInput?.value || '');
    const payload = {
      pickup: pickupInput?.value?.trim() || '',
      destination: destinationInput?.value?.trim() || '',
      pickup_location_id: parseInt(pickupLocationIdInput?.value || '0', 10) || null,
      pickup_location_type: pickupLocationTypeInput?.value || null,
      pickup_system_id: parseInt(pickupSystemIdInput?.value || '0', 10) || null,
      destination_location_id: parseInt(deliveryLocationIdInput?.value || '0', 10) || null,
      destination_location_type: deliveryLocationTypeInput?.value || null,
      destination_system_id: parseInt(deliverySystemIdInput?.value || '0', 10) || null,
      volume_m3: parseFloat(volumeInput?.value || '0'),
      collateral_isk: collateralValue ?? 0,
      priority: priorityInput?.value || 'normal',
    };
    if (!payload.pickup || !payload.destination || !payload.volume_m3) {
      showError('Pickup, delivery, and volume are required.');
      return;
    }
    if (!hasValidSelection(pickupLocationIdInput, pickupLocationTypeInput)) {
      pickupInput?.classList.add('input--error');
      showError('Please pick a pickup location from the list.');
      return;
    }
    if (!hasValidSelection(deliveryLocationIdInput, deliveryLocationTypeInput)) {
      destinationInput?.classList.add('input--error');
      showError('Please pick a delivery location from the list.');
      return;
    }
    if (payload.collateral_isk <= 0) {
      showError('Collateral must be a valid ISK amount.');
      return;
    }
    const data = await fetchQuote(payload, 'Quote request failed.');
    if (!data) return;
    currentQuoteId = data.quote_id;
    const totalPrice = data.total_price_isk ?? data.price_total_isk ?? 0;
    showResult(`Total price: ${fmtIsk(totalPrice)} ISK`);
    renderQuoteBreakdownLikeHome(data, basePath);
    if (requestLink) requestLink.style.display = 'none';
    if (requestStatus) requestStatus.style.display = 'none';
  });

  createRequestBtn?.addEventListener('click', async () => {
    if (!currentQuoteId || !canCreateRequest) return;
    try {
      const resp = await fetch(`${basePath}/api/requests/create/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ quote_id: currentQuoteId }),
      });
      const data = await resp.json();
      if (!resp.ok || !data.ok) {
        showError(data.error || 'Create request failed.');
        return;
      }
      showResult('Request created! Copy the contract hint into your courier contract.');
      if (requestLink && data.request_url) {
        requestLink.href = data.request_url;
        requestLink.style.display = 'inline-flex';
      }
      if (requestStatus) {
        requestStatus.textContent = data.message || 'Request created.';
        requestStatus.style.display = 'inline';
      }
    } catch (err) {
      showError('Create request failed.');
    }
  });

  const buildBuybackPayload = () => {
    const volume = parseFloat(volumeInput?.value || '0');
    const collateralValue = parseIsk(collateralInput?.value || '');
    return {
      pickup: pickupInput?.value?.trim() || '',
      destination: destinationInput?.value?.trim() || '',
      pickup_location_id: parseInt(pickupLocationIdInput?.value || '0', 10) || null,
      pickup_location_type: pickupLocationTypeInput?.value || null,
      pickup_system_id: parseInt(pickupSystemIdInput?.value || '0', 10) || null,
      destination_location_id: parseInt(deliveryLocationIdInput?.value || '0', 10) || null,
      destination_location_type: deliveryLocationTypeInput?.value || null,
      destination_system_id: parseInt(deliverySystemIdInput?.value || '0', 10) || null,
      volume_m3: volume,
      collateral_isk: collateralValue ?? 0,
      priority: priorityInput?.value || 'normal',
    };
  };

  const findBuybackPrice = (volume) => {
    if (!Array.isArray(buybackTiers)) return 0;
    const sorted = [...buybackTiers].sort((a, b) => (a.max_m3 ?? 0) - (b.max_m3 ?? 0));
    for (const tier of sorted) {
      const max = Number(tier.max_m3 ?? 0);
      if (volume <= max) {
        return Number(tier.price_isk ?? 0);
      }
    }
    return 0;
  };

  const updateBuybackButton = () => {
    if (!buybackBtn || !buybackEnabled) return;
    const volume = parseFloat(volumeInput?.value || '0');
    if (!volume || volume <= 0) {
      buybackBtn.textContent = 'Buyback haulage — volume based';
      return;
    }
    const price = findBuybackPrice(volume);
    buybackBtn.textContent = price > 0
      ? `Buyback haulage — ${fmtIsk(price)} ISK`
      : 'Buyback haulage — volume based';
  };

  updateBuybackButton();
  volumeInput?.addEventListener('input', updateBuybackButton);

  buybackBtn?.addEventListener('click', async () => {
    if (!buybackEnabled || buybackBtn?.disabled) return;
    hideError();
    hideResult();
    if (breakdownCard) breakdownCard.style.display = 'none';
    if (submitBtn) submitBtn.textContent = 'Calculating…';
    if (!hasValidSelection(pickupLocationIdInput, pickupLocationTypeInput)) {
      pickupInput?.classList.add('input--error');
      showError('Please pick a pickup location from the list.');
      if (submitBtn) submitBtn.textContent = 'Get Quote';
      return;
    }
    if (!hasValidSelection(deliveryLocationIdInput, deliveryLocationTypeInput)) {
      destinationInput?.classList.add('input--error');
      showError('Please pick a delivery location from the list.');
      if (submitBtn) submitBtn.textContent = 'Get Quote';
      return;
    }
    const payload = buildBuybackPayload();
    try {
      const resp = await fetch(`${basePath}/api/quote/buyback/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await resp.json();
      if (!resp.ok || !data.ok) {
        showError(data.error || 'Buyback quote failed.');
        return;
      }
      currentQuoteId = data.quote_id;
      const totalPrice = data.reward_isk ?? data.total_price_isk ?? data.price_total_isk ?? 0;
      showResult(`Buyback haulage price: ${fmtIsk(totalPrice)} ISK.`);
      renderQuoteBreakdownLikeHome(data, basePath);
      if (requestLink) requestLink.style.display = 'none';
      if (requestStatus) requestStatus.style.display = 'none';
    } catch (err) {
      showError('Buyback quote failed.');
    } finally {
      if (submitBtn) submitBtn.textContent = 'Get Quote';
    }
  });
})();
