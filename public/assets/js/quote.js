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
      const option = document.createElement('option');
      option.value = item.name;
      if (item.label) option.label = item.label;
      listEl.appendChild(option);
    }
  };

  const pickupInput = document.querySelector('input[name="pickup_system"]');
  const destinationInput = document.querySelector('input[name="destination_system"]');
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

  const formatCurrency = (value) => {
    if (value === null || value === undefined || Number.isNaN(value)) return '—';
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' ISK';
  };

  const formatNumber = (value, digits = 2) => {
    if (value === null || value === undefined || Number.isNaN(value)) return '—';
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: digits });
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

  const buildBreakdown = (breakdown, route) => {
    if (!breakdownContent || !breakdownCard) return;
    const items = [];
    if (route && route.summary) {
      items.push(`<div><strong>Route:</strong> ${route.summary}</div>`);
    }
    if (route && typeof route.jumps === 'number') {
      items.push(`<div><strong>Jumps:</strong> ${formatNumber(route.jumps, 0)}</div>`);
    }
    if (breakdown && breakdown.ship_class) {
      items.push(`<div><strong>Ship class:</strong> ${breakdown.ship_class.name || breakdown.ship_class.service_class || '—'}</div>`);
    }
    if (breakdown && breakdown.distance) {
      items.push(`<div><strong>Distance:</strong> ${formatNumber(breakdown.distance.jumps || 0, 0)} jumps</div>`);
    }
    if (breakdown && breakdown.total) {
      items.push(`<div><strong>Total:</strong> ${formatCurrency(breakdown.total.price_isk || breakdown.total.price_total)}</div>`);
    }
    const penalties = breakdown?.penalties || [];
    if (penalties.length) {
      const rows = penalties.map((penalty) => `<li>${penalty.label || 'Penalty'}: ${formatCurrency(penalty.amount_isk)}</li>`).join('');
      items.push(`<div><strong>Penalties</strong><ul>${rows}</ul></div>`);
    }
    breakdownContent.innerHTML = items.join('');
    breakdownCard.style.display = items.length ? 'block' : 'none';
  };

  const fetchLocations = async (value, type, listEl) => {
    if (!value || value.length < minChars) {
      listEl.innerHTML = '';
      return;
    }
    const queryId = ++locationQueryIds[type];
    const url = `${basePath}/api/locations/search/?prefix=${encodeURIComponent(value)}&type=${encodeURIComponent(type)}`;
    try {
      const resp = await fetch(url, { credentials: 'same-origin' });
      const data = await resp.json();
      if (queryId !== locationQueryIds[type]) return;
      if (!data || !data.ok) return;
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
  destinationInput?.addEventListener('input', () => {
    if (locationTimers.destination) {
      clearTimeout(locationTimers.destination);
    }
    locationTimers.destination = setTimeout(() => {
      fetchLocations(destinationInput.value, 'destination', destinationList);
    }, 150);
  });

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
          friendlyError = 'We could not match one of the locations. Check spelling or use system names.';
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
      pickup_system: pickupInput?.value?.trim() || '',
      destination_system: destinationInput?.value?.trim() || '',
      volume_m3: parseFloat(volumeInput?.value || '0'),
      collateral_isk: collateralValue ?? 0,
      priority: priorityInput?.value || 'normal',
    };
    if (!payload.pickup_system || !payload.destination_system || !payload.volume_m3) {
      showError('Pickup, destination, and volume are required.');
      return;
    }
    const data = await fetchQuote(payload, 'Quote request failed.');
    if (!data) return;
    currentQuoteId = data.quote_id;
    showResult(`Estimated haul price: ${formatCurrency(data.total_price_isk)}`);
    buildBreakdown(data.breakdown, data.route);
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
      pickup_system: pickupInput?.value?.trim() || '',
      destination_system: destinationInput?.value?.trim() || '',
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
      showResult(`Estimated buyback haul price: ${formatCurrency(data.total_price_isk)}`);
      buildBreakdown(data.breakdown, data.route);
      if (requestLink) requestLink.style.display = 'none';
      if (requestStatus) requestStatus.style.display = 'none';
    } catch (err) {
      showError('Buyback quote failed.');
    } finally {
      if (submitBtn) submitBtn.textContent = 'Get Quote';
    }
  });
})();
