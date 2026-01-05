<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canCreateRequest = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.create');
$canBuybackHaulage = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.buyback');
$quoteInput = $quoteInput ?? ['pickup_system' => '', 'destination_system' => ''];
$defaultPriority = $defaultPriority ?? 'normal';
$buybackHaulageTiers = $buybackHaulageTiers ?? [];
$buybackHaulageEnabled = $buybackHaulageEnabled ?? false;
$bodyClass = 'quote';

ob_start();
?>
<section class="quote-page">
  <?php if ($isLoggedIn): ?>
    <div class="card quote-card" id="quote">
      <div class="card-header">
        <h2>Get Quote</h2>
        <p class="muted">Enter pickup, destination, and volume to get an instant breakdown.</p>
      </div>
      <div class="content" data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-grid">
          <label class="form-field">
            <span class="form-label">Pickup system</span>
            <input class="input" type="text" name="pickup_system" list="pickup-location-list" value="<?= htmlspecialchars($quoteInput['pickup_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Jita" autocomplete="off" />
          </label>
          <label class="form-field">
            <span class="form-label">Destination system</span>
            <input class="input" type="text" name="destination_system" list="destination-location-list" value="<?= htmlspecialchars($quoteInput['destination_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Amarr" autocomplete="off" />
          </label>
          <label class="form-field">
            <span class="form-label">Volume (m³)</span>
            <input class="input" type="number" name="volume_m3" min="1" step="0.01" placeholder="e.g. 12500" />
          </label>
          <label class="form-field">
            <span class="form-label">Collateral (ISK)</span>
            <input class="input" type="text" name="collateral" placeholder="e.g. 300m, 2.65b, 400,000,000" />
          </label>
          <label class="form-field">
            <span class="form-label">Priority</span>
            <select class="input" name="priority">
              <?php
              $priorities = ['normal' => 'Normal', 'high' => 'High'];
              foreach ($priorities as $value => $label):
                $selected = $defaultPriority === $value ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="card-footer">
          <button class="btn" type="button" id="quote-submit">Get Quote</button>
          <?php if (!$canCreateRequest): ?>
            <span class="muted" style="margin-left:12px;">Sign in with requester access to create a haul request.</span>
          <?php endif; ?>
          <?php if ($canBuybackHaulage): ?>
            <button class="btn ghost" type="button" id="buyback-haulage-btn" <?= $buybackHaulageEnabled ? '' : 'disabled' ?>>
              Buyback haulage — volume based
            </button>
            <?php if (!$buybackHaulageEnabled): ?>
              <span class="muted" style="margin-left:12px;">Set buyback haulage tiers in Admin → Hauling to enable.</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="alert alert-warning" id="quote-error" style="display:none;"></div>
        <div class="alert alert-success" id="quote-result" style="display:none;"></div>
        <div class="card card-subtle" id="quote-breakdown" style="margin-top:16px; display:none;">
          <div class="card-header">
            <h3>Quote Breakdown</h3>
            <p class="muted">Includes route, penalties, and rate plan components.</p>
          </div>
          <div class="content" id="quote-breakdown-content"></div>
          <div class="card-footer" style="display:flex; gap:10px; align-items:center;">
            <button class="btn" type="button" id="quote-create-request" <?= $canCreateRequest ? '' : 'disabled' ?>>Create Request</button>
            <a class="btn ghost" id="quote-request-link" href="#" style="display:none;">View Contract Instructions</a>
            <span class="muted" id="quote-request-status" style="display:none;"></span>
          </div>
        </div>
        <datalist id="pickup-location-list"></datalist>
        <datalist id="destination-location-list"></datalist>
      </div>
    </div>
  <?php else: ?>
    <div class="card quote-card" id="quote">
      <div class="card-header">
        <h2>Member Quotes</h2>
        <p class="muted">Sign in with EVE Online to access instant pricing.</p>
      </div>
      <div class="content" style="display:flex; flex-direction:column; gap:12px;">
        <p class="muted">Only corporation members can request quotes.</p>
        <a class="sso-button" href="<?= ($basePath ?: '') ?>/login/?start=1">
          <img src="https://web.ccpgamescdn.com/eveonlineassets/developers/eve-sso-login-black-small.png" alt="Log in with EVE Online" />
        </a>
      </div>
    </div>
  <?php endif; ?>
</section>
<?php if ($isLoggedIn): ?>
<script>
  (() => {
    const basePath = <?= json_encode($basePath ?: '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const minChars = 3;
    const buybackTiers = <?= json_encode($buybackHaulageTiers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const buybackEnabled = <?= $buybackHaulageEnabled ? 'true' : 'false' ?>;

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

    const fetchLocations = async (term, targetList) => {
      if (!term || term.length < minChars) {
        targetList.innerHTML = '';
        return [];
      }
      const resp = await fetch(`${basePath}/api/locations/search/?q=${encodeURIComponent(term)}`);
      if (!resp.ok) return [];
      const data = await resp.json();
      return data.items || [];
    };

    const updateLocationOptions = async (inputEl, listEl) => {
      const term = inputEl?.value?.trim() || '';
      const items = await fetchLocations(term, listEl);
      buildOptions(listEl, items, term);
    };

    pickupInput?.addEventListener('input', () => updateLocationOptions(pickupInput, pickupList));
    destinationInput?.addEventListener('input', () => updateLocationOptions(destinationInput, destinationList));

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
      const payload = {
        pickup_system: pickupInput?.value?.trim() || '',
        destination_system: destinationInput?.value?.trim() || '',
        volume_m3: parseFloat(volumeInput?.value || '0'),
        collateral: collateralInput?.value?.trim() || '',
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
      return {
        pickup_system: pickupInput?.value?.trim() || '',
        destination_system: destinationInput?.value?.trim() || '',
        volume_m3: volume,
        collateral: collateralInput?.value?.trim() || '',
        priority: priorityInput?.value || 'normal',
      };
    };

    const resolveBuybackTier = () => {
      const volume = parseFloat(volumeInput?.value || '0');
      if (!volume || !buybackTiers.length) return null;
      return buybackTiers.find((tier) => volume >= tier.min_volume && volume <= tier.max_volume) || null;
    };

    const updateBuybackButton = () => {
      if (!buybackBtn) return;
      if (!buybackEnabled) return;
      const tier = resolveBuybackTier();
      if (!tier) {
        buybackBtn.textContent = 'Buyback haulage — volume based';
        buybackBtn.disabled = true;
        return;
      }
      buybackBtn.disabled = false;
      buybackBtn.textContent = `Buyback haulage — ${tier.label}`;
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
</script>
<?php endif; ?>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
