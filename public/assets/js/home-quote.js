(() => {
  const root = document.querySelector('.js-quote-form');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const DEBUG = false;
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
  let currentRequestMode = 'standard';
  const locationQueryIds = { pickup: 0, destination: 0 };
  const locationTimers = { pickup: null, destination: null };
  const pickupSuggestionMap = new Map();
  const deliverySuggestionMap = new Map();
  const selectedLabels = { pickup: '', destination: '' };
  const selectedKeys = { pickup: '', destination: '' };
  const debugLog = (...args) => {
    if (!DEBUG) return;
    console.debug('[home-quote]', ...args);
  };

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

  const setSelectionState = (type, item) => {
    if (!item) {
      selectedLabels[type] = '';
      selectedKeys[type] = '';
      return;
    }
    selectedLabels[type] = item.name || '';
    selectedKeys[type] = normalizeLabel(item.name || '');
  };

  const clearHiddenFields = (type) => {
    if (type === 'pickup') {
      if (pickupLocationIdInput) pickupLocationIdInput.value = '';
      if (pickupLocationTypeInput) pickupLocationTypeInput.value = '';
      if (pickupSystemIdInput) pickupSystemIdInput.value = '';
      pickupInput?.classList.remove('input--error');
    } else {
      if (deliveryLocationIdInput) deliveryLocationIdInput.value = '';
      if (deliveryLocationTypeInput) deliveryLocationTypeInput.value = '';
      if (deliverySystemIdInput) deliverySystemIdInput.value = '';
      destinationInput?.classList.remove('input--error');
    }
    setSelectionState(type, null);
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
      const locationType = item.location_type ?? '';
      const systemId = item.system_id ?? '';
      const locationId = locationType === 'system' ? systemId : (item.location_id ?? '');
      idInput.value = locationId;
      typeInput.value = locationType;
      systemInput.value = systemId;
    };

    if (type === 'pickup') {
      setFields(pickupLocationIdInput, pickupLocationTypeInput, pickupSystemIdInput);
    } else {
      setFields(deliveryLocationIdInput, deliveryLocationTypeInput, deliverySystemIdInput);
    }

    setSelectionState(type, item);
    if (item) {
      debugLog('selection applied', type, {
        location_id: item.location_id ?? null,
        location_type: item.location_type ?? null,
        system_id: item.system_id ?? null,
        name: item.name ?? '',
      });
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
      if (!Array.isArray(data.items) || data.items.length === 0) return;
      storeLocations(type, data.items || []);
      buildOptions(listEl, data.items || [], value);
      debugLog('rendered suggestions', type, data.items.length);
    } catch (err) {
      if (queryId !== locationQueryIds[type]) return;
      listEl.innerHTML = '';
    }
  };

  const shouldBlockFetch = (type, value) => {
    if (!value) return false;
    if (value.includes('—')) return true;
    if (value === selectedLabels[type]) return true;
    return normalizeLabel(value) === selectedKeys[type];
  };

  const handleLocationInput = (type, inputEl, listEl) => {
    if (!inputEl) return;
    if (locationTimers[type]) {
      clearTimeout(locationTimers[type]);
      locationTimers[type] = null;
    }
    const value = inputEl.value || '';
    const lookup = normalizeLabel(value);
    const targetMap = type === 'pickup' ? pickupSuggestionMap : deliverySuggestionMap;
    const item = lookup ? targetMap.get(lookup) : null;
    if (item) {
      applyLocationSelection(type, value);
      return;
    }

    clearHiddenFields(type);
    if (shouldBlockFetch(type, value)) return;
    if (value.length < minChars) {
      listEl.innerHTML = '';
      return;
    }

    locationTimers[type] = setTimeout(() => {
      fetchLocations(value, type, listEl);
    }, 150);
  };

  pickupInput?.addEventListener('input', () => {
    handleLocationInput('pickup', pickupInput, pickupList);
  });
  pickupInput?.addEventListener('change', () => applyLocationSelection('pickup', pickupInput.value));
  pickupInput?.addEventListener('blur', () => applyLocationSelection('pickup', pickupInput.value));
  destinationInput?.addEventListener('input', () => {
    handleLocationInput('destination', destinationInput, destinationList);
  });
  destinationInput?.addEventListener('change', () => applyLocationSelection('destination', destinationInput.value));
  destinationInput?.addEventListener('blur', () => applyLocationSelection('destination', destinationInput.value));

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

  const updateBuybackLabel = () => {
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

  volumeInput?.addEventListener('input', updateBuybackLabel);
  updateBuybackLabel();

  const renderBreakdown = (data) => {
    const breakdown = data.breakdown || {};
    const route = data.route || {};
    const ship = breakdown.ship_class || {};
    const security = breakdown.security_counts || {};
    const securityMultipliers = breakdown.security_multipliers || {};
    const flatRisk = breakdown.flat_risk_fees || {};
    const volumePressure = breakdown.volume_pressure || {};
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
    const securityParts = [
      `HS ${security.high ?? 0}`,
      `LS ${security.low ?? 0}`,
      `NS ${security.null ?? 0}`,
      `Pochven ${security.pochven ?? 0}`,
      `Zarzakh ${security.zarzakh ?? 0}`,
      `Thera ${security.thera ?? 0}`,
    ];
    const multiplierParts = [
      `HS x${(securityMultipliers.high ?? 1).toFixed(2)}`,
      `LS x${(securityMultipliers.low ?? 1).toFixed(2)}`,
      `NS x${(securityMultipliers.null ?? 1).toFixed(2)}`,
      `Pochven x${(securityMultipliers.pochven ?? 1).toFixed(2)}`,
      `Zarzakh x${(securityMultipliers.zarzakh ?? 1).toFixed(2)}`,
      `Thera x${(securityMultipliers.thera ?? 1).toFixed(2)}`,
    ];
    const volumeLabel = volumePressure?.applied
      ? `+${volumePressure.applied.surcharge_pct}% (>${Math.round((volumePressure.applied.min_ratio || 0) * 100)}% cap)`
      : 'None';

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
          <div class="label">Jumps</div>
          <div>${route.jumps ?? 0} (${securityParts.join(' / ')})</div>
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
          <div class="label">Security multipliers</div>
          <div>${multiplierParts.join(' / ')}</div>
        </div>
        <div>
          <div class="label">Flat risk surcharges</div>
          <div>Low-sec ${fmtIsk(flatRisk.lowsec || 0)} • Null-sec ${fmtIsk(flatRisk.nullsec || 0)} • Special ${fmtIsk(flatRisk.special || 0)}</div>
        </div>
        <div>
          <div class="label">Volume pressure</div>
          <div>${volumeLabel}</div>
        </div>
      </div>
      <div class="row" style="margin-top:14px;">
        <div>
          <div class="label">Costs</div>
          <div>Jump ${fmtIsk(costs.jump_security || 0)} • Soft DNF ${fmtIsk(costs.soft_dnf_penalty || 0)} • Flat risk ${fmtIsk(costs.flat_risk_fees || 0)}</div>
        </div>
        <div>
          <div class="label">Totals</div>
          <div>Haul ${fmtIsk(costs.haul_total || 0)} • Collateral ${fmtIsk(costs.collateral_fee || 0)} • Priority ${fmtIsk(costs.priority_fee || 0)}</div>
        </div>
        <div>
          <div class="label">DNF notes</div>
          <div>${(penalties.soft_dnf || []).map(r => r.reason || 'Rule').join(', ') || 'None'}</div>
        </div>
      </div>
      ${costs.min_price_applied ? `
        <div class="row" style="margin-top:14px;">
          <div class="alert alert-warning" style="margin:0;">Minimum price applied for this ship class.</div>
        </div>
      ` : ''}
    `;

    const toggleBtn = document.getElementById('toggle-route');
    const routePath = document.getElementById('route-path');
    toggleBtn?.addEventListener('click', () => {
      if (!routePath) return;
      const visible = routePath.style.display !== 'none';
      routePath.style.display = visible ? 'none' : 'block';
      toggleBtn.textContent = visible ? 'View route' : 'Hide route';
    });
  };

  const showError = (message) => {
    errorEl.textContent = message;
    errorEl.style.display = 'block';
    resultEl.style.display = 'none';
    breakdownCard.style.display = 'none';
  };

  const hasValidSelection = (idInput, typeInput) => {
    const idValue = parseInt(idInput?.value || '0', 10);
    const typeValue = typeInput?.value || '';
    return idValue > 0 && typeValue !== '';
  };

  submitBtn?.addEventListener('click', async () => {
    errorEl.style.display = 'none';
    resultEl.style.display = 'none';
    requestStatus.style.display = 'none';
    requestLink.style.display = 'none';
    if (createRequestBtn) {
      createRequestBtn.disabled = !canCreateRequest;
      createRequestBtn.textContent = 'Create Request';
    }

    const pickup = pickupInput?.value.trim();
    const destination = destinationInput?.value.trim();
    const volume = parseFloat(volumeInput?.value || '0');
    const collateral = parseIsk(collateralInput?.value || '');
    const priority = priorityInput?.value || 'normal';
    const payload = {
      pickup,
      destination,
      pickup_location_id: parseInt(pickupLocationIdInput?.value || '0', 10) || null,
      pickup_location_type: pickupLocationTypeInput?.value || null,
      pickup_system_id: parseInt(pickupSystemIdInput?.value || '0', 10) || null,
      destination_location_id: parseInt(deliveryLocationIdInput?.value || '0', 10) || null,
      destination_location_type: deliveryLocationTypeInput?.value || null,
      destination_system_id: parseInt(deliverySystemIdInput?.value || '0', 10) || null,
      volume_m3: volume,
      collateral_isk: collateral,
      priority,
    };
    debugLog('quote submit payload', payload);

    if (!pickup || !destination) {
      showError('Pickup and delivery locations are required.');
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
    if (!volume || volume <= 0) {
      showError('Volume must be greater than zero.');
      return;
    }
    if (collateral === null || collateral <= 0) {
      showError('Collateral must be a valid ISK amount.');
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Quoting...';
    try {
      const resp = await fetch(`${basePath}/api/quote/`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      });
      const data = await resp.json();
      if (!data.ok) {
        let friendlyError = data.error || 'Quote failed.';
        if (data.error === 'no_viable_route') {
          friendlyError = 'No viable route found.';
        } else if (typeof data.error === 'string' && data.error.startsWith('oversized_volume:')) {
          const [, maxValue] = data.error.split(':');
          const maxVolume = parseFloat(maxValue || '0');
          friendlyError = Number.isFinite(maxVolume) && maxVolume > 0
            ? `Volume exceeds max ship capacity (max ${maxVolume.toLocaleString()} m³).`
            : 'Volume exceeds max ship capacity.';
        }
        showError(friendlyError);
        return;
      }
      currentQuoteId = data.quote_id;
      currentRequestMode = 'standard';
      const totalPrice = data.total_price_isk ?? data.price_total_isk ?? 0;
      resultEl.textContent = `Total price: ${fmtIsk(totalPrice)} ISK`;
      resultEl.style.display = 'block';
      breakdownCard.style.display = 'block';
      renderBreakdown(data);
    } catch (err) {
      showError('Quote request failed.');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Get Quote';
    }
  });

  createRequestBtn?.addEventListener('click', async () => {
    if (!currentQuoteId) return;
    createRequestBtn.disabled = true;
    const requestLabel = currentRequestMode === 'buyback' ? 'buyback request' : 'request';
    requestStatus.textContent = `Creating ${requestLabel}...`;
    requestStatus.style.display = 'inline';
    try {
      const endpoint = currentRequestMode === 'buyback'
        ? `${basePath}/api/requests/buyback/`
        : `${basePath}/api/requests/create/`;
      const resp = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ quote_id: currentQuoteId }),
      });
      const data = await resp.json();
      if (!data.ok) {
        requestStatus.textContent = data.error || `${requestLabel} creation failed.`;
        return;
      }
      const requestPrefix = currentRequestMode === 'buyback' ? 'Buyback request' : 'Request';
      requestStatus.textContent = `${requestPrefix} #${data.request_id} created.`;
      requestLink.href = data.request_url || '#';
      requestLink.style.display = 'inline-flex';
    } catch (err) {
      requestStatus.textContent = `${requestLabel} creation failed.`;
    } finally {
      createRequestBtn.disabled = false;
    }
  });

  buybackBtn?.addEventListener('click', async () => {
    errorEl.style.display = 'none';
    resultEl.style.display = 'none';
    requestStatus.style.display = 'none';
    requestLink.style.display = 'none';
    if (createRequestBtn) {
      createRequestBtn.disabled = !canCreateRequest;
      createRequestBtn.textContent = 'Create Buyback Request';
    }

    const pickup = pickupInput?.value.trim();
    const destination = destinationInput?.value.trim();
    const volume = parseFloat(volumeInput?.value || '0');
    const collateral = parseIsk(collateralInput?.value || '');
    const priority = priorityInput?.value || 'normal';
    const payload = {
      pickup,
      destination,
      pickup_location_id: parseInt(pickupLocationIdInput?.value || '0', 10) || null,
      pickup_location_type: pickupLocationTypeInput?.value || null,
      pickup_system_id: parseInt(pickupSystemIdInput?.value || '0', 10) || null,
      destination_location_id: parseInt(deliveryLocationIdInput?.value || '0', 10) || null,
      destination_location_type: deliveryLocationTypeInput?.value || null,
      destination_system_id: parseInt(deliverySystemIdInput?.value || '0', 10) || null,
      volume_m3: volume,
      collateral_isk: collateral,
      priority,
    };
    debugLog('buyback submit payload', payload);

    if (!pickup || !destination) {
      showError('Pickup and delivery locations are required.');
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
    if (!volume || volume <= 0) {
      showError('Volume must be greater than zero.');
      return;
    }
    if (collateral === null || collateral <= 0) {
      showError('Collateral must be a valid ISK amount.');
      return;
    }

    buybackBtn.disabled = true;
    const originalText = buybackBtn.textContent;
    buybackBtn.textContent = 'Quoting...';
    try {
      const resp = await fetch(`${basePath}/api/quote/buyback/`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      });
      const data = await resp.json();
      if (!data.ok) {
        showError(data.error || 'Buyback quote failed.');
        return;
      }
      currentQuoteId = data.quote_id;
      currentRequestMode = 'buyback';
      const totalPrice = data.reward_isk ?? data.total_price_isk ?? data.price_total_isk ?? 0;
      resultEl.textContent = `Buyback haulage price: ${fmtIsk(totalPrice)} ISK.`;
      resultEl.style.display = 'block';
      breakdownCard.style.display = 'block';
      renderBreakdown(data);
    } catch (err) {
      showError('Buyback quote failed.');
    } finally {
      buybackBtn.disabled = false;
      buybackBtn.textContent = originalText;
      updateBuybackLabel();
    }
  });
})();
