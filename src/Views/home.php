<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canAdmin = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'corp.manage');
$displayName = (string)($authCtx['display_name'] ?? 'Guest');
$corpName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');
$contractStats = $contractStats ?? [
  'total' => 0,
  'outstanding' => 0,
  'in_progress' => 0,
  'completed' => 0,
  'en_route_volume' => 0,
  'pending_volume' => 0,
  'last_fetched_at' => null,
];
$contractStatsAvailable = $contractStatsAvailable ?? false;
$quoteInput = $quoteInput ?? ['pickup_system' => '', 'destination_system' => ''];
$defaultPriority = $defaultPriority ?? 'normal';
$canCreateRequest = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.create');
$canBuybackHaulage = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.buyback');
$buybackHaulagePrice = max(0.0, (float)($buybackHaulagePrice ?? 0.0));
$pickupLocationOptions = $pickupLocationOptions ?? [];
$destinationLocationOptions = $destinationLocationOptions ?? [];
$bodyClass = 'home';
$logoPath = '/assets/logo.png';
$logoDiskPath = __DIR__ . '/../../public/assets/logo.png';
if (!file_exists($logoDiskPath)) {
  $logoPath = '/assets/logo.jpg';
}

ob_start();
?>
<section class="grid">
  <div class="stack">
    <div class="card hero-card">
      <div class="hero-banner">
        <img class="hero-logo" src="<?= ($basePath ?: '') . $logoPath ?>" alt="Lone Wolves Logistics logo" />
      </div>
      <div class="card-header">
        <h1>Lone Wolves Logistics</h1>
        <p class="hero-tagline">The Pack Delivers.</p>
      </div>
      <div class="content hero-body">
        <p>In New Eden, logistics is not about movement—it is about certainty.</p>
        <p>
          Lone Wolves Logistics operates as the hauling backbone of Lone Wolves Mining, engineered to ensure that assets,
          materials, and strategic supplies arrive exactly where they are needed, without disruption. We exist to keep
          operations flowing, production uninterrupted, and pilots focused on what generates value.
        </p>
        <p>
          Our hauling doctrine is built on discipline, risk awareness, and execution. Every contract is treated as
          mission-critical. Routes are selected deliberately, ships are chosen with intent, and deliveries are completed
          without noise or excuses.
        </p>
        <p class="hero-pledge">
          We do not chase volume.<br />
          We do not cut corners.<br />
          We do not leave cargo behind.
        </p>
        <p>
          Internally, we serve the pack—supporting mining, industry, buyback, and deployment logistics. Externally, when
          opened, we will extend the same operational standard to partners who value reliability over theatrics.
        </p>
        <p>If it flies under Lone Wolves Logistics, the outcome is not uncertain.</p>
        <p class="hero-tagline">The Pack Delivers.</p>
      </div>
    </div>
    <div class="card">
      <div class="card-header">
        <h2>Queue status</h2>
        <p class="muted">ESI-fed courier contracts currently in flow.</p>
        <?php if ($isLoggedIn): ?>
          <div class="pill subtle" style="margin-top:10px; display:inline-flex;">Signed in as <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
      <?php if (!$contractStatsAvailable || $contractStats['total'] <= 0): ?>
        <div class="content">
          <p class="muted">No courier contracts pulled yet. Sync via the ESI panel to populate en-route totals.</p>
        </div>
      <?php else: ?>
        <div class="kpi-row">
          <div class="kpi">
            <div class="kpi-label">Outstanding</div>
            <div class="kpi-value"><?= number_format((int)$contractStats['outstanding']) ?></div>
          </div>
          <div class="kpi">
            <div class="kpi-label">En Route</div>
            <div class="kpi-value"><?= number_format((int)$contractStats['in_progress']) ?></div>
          </div>
          <div class="kpi">
            <div class="kpi-label">Completed</div>
            <div class="kpi-value"><?= number_format((int)$contractStats['completed']) ?></div>
          </div>
        </div>
        <div class="content" style="padding-top:6px;">
          <div class="row">
            <div>
              <div class="label">En route volume</div>
              <div><?= number_format((float)$contractStats['en_route_volume'], 0) ?> m³</div>
            </div>
            <div>
              <div class="label">Pending volume</div>
              <div><?= number_format((float)$contractStats['pending_volume'], 0) ?> m³</div>
            </div>
          </div>
          <?php if (!empty($contractStats['last_fetched_at'])): ?>
            <div class="muted" style="margin-top:10px; font-size:12px;">
              Last ESI sync: <?= htmlspecialchars((string)$contractStats['last_fetched_at'], ENT_QUOTES, 'UTF-8') ?> UTC
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isLoggedIn): ?>
    <div class="card" id="quote">
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
            <button class="btn ghost" type="button" id="buyback-haulage-btn" <?= $buybackHaulagePrice > 0 ? '' : 'disabled' ?>>
              Buyback haulage — <?= htmlspecialchars(number_format($buybackHaulagePrice, 2), ENT_QUOTES, 'UTF-8') ?> ISK
            </button>
            <?php if ($buybackHaulagePrice <= 0): ?>
              <span class="muted" style="margin-left:12px;">Set a buyback haulage price in Admin → Hauling to enable.</span>
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
    <div class="card" id="quote">
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

    const fetchLocations = async (value, type, listEl) => {
      if (!value || value.length < minChars) {
        listEl.innerHTML = '';
        return;
      }
      const queryId = ++locationQueryIds[type];
      const url = `${basePath}/api/locations/search/?prefix=${encodeURIComponent(value)}&type=${encodeURIComponent(type)}`;
      try {
        const resp = await fetch(url);
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

    const parseIsk = (value) => {
      if (!value) return null;
      const clean = value.toString().trim().toLowerCase().replace(/[, ]+/g, '');
      const match = clean.match(/^([0-9]+(?:\\.[0-9]+)?)([kmb])?$/);
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

    const renderBreakdown = (data) => {
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
    };

    const showError = (message) => {
      errorEl.textContent = message;
      errorEl.style.display = 'block';
      resultEl.style.display = 'none';
      breakdownCard.style.display = 'none';
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

      if (!pickup || !destination) {
        showError('Pickup and destination systems are required.');
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
          body: JSON.stringify({
            pickup,
            destination,
            volume_m3: volume,
            collateral_isk: collateral,
            priority,
          }),
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
      requestStatus.textContent = 'Creating request...';
      requestStatus.style.display = 'inline';
      try {
        const resp = await fetch(`${basePath}/api/requests/create/`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ quote_id: currentQuoteId }),
        });
        const data = await resp.json();
        if (!data.ok) {
          requestStatus.textContent = data.error || 'Request creation failed.';
          return;
        }
        requestStatus.textContent = `Request #${data.request_id} created.`;
        requestLink.href = data.request_url || '#';
        requestLink.style.display = 'inline-flex';
      } catch (err) {
        requestStatus.textContent = 'Request creation failed.';
      } finally {
        createRequestBtn.disabled = false;
      }
    });

    buybackBtn?.addEventListener('click', async () => {
      errorEl.style.display = 'none';
      resultEl.style.display = 'none';
      requestStatus.style.display = 'none';
      requestLink.style.display = 'none';

      const pickup = pickupInput?.value.trim();
      const destination = destinationInput?.value.trim();
      const volume = parseFloat(volumeInput?.value || '0');
      const collateral = parseIsk(collateralInput?.value || '');
      const priority = priorityInput?.value || 'normal';

      if (!pickup || !destination) {
        showError('Pickup and destination systems are required.');
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
      buybackBtn.textContent = 'Creating...';
      try {
        const resp = await fetch(`${basePath}/api/requests/buyback/`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            pickup,
            destination,
            volume_m3: volume,
            collateral_isk: collateral,
            priority,
          }),
        });
        const data = await resp.json();
        if (!data.ok) {
          showError(data.error || 'Buyback request failed.');
          return;
        }
        currentQuoteId = null;
        const totalPrice = data.reward_isk ?? data.total_price_isk ?? data.price_total_isk ?? 0;
        resultEl.textContent = `Buyback request #${data.request_id} created for ${fmtIsk(totalPrice)} ISK.`;
        resultEl.style.display = 'block';
        breakdownCard.style.display = 'block';
        renderBreakdown(data);
        requestLink.href = data.request_url || '#';
        requestLink.style.display = data.request_url ? 'inline-flex' : 'none';
        requestStatus.textContent = `Buyback request #${data.request_id} created.`;
        requestStatus.style.display = 'inline';
        if (createRequestBtn) {
          createRequestBtn.disabled = true;
          createRequestBtn.textContent = 'Request Created';
        }
      } catch (err) {
        showError('Buyback request failed.');
      } finally {
        buybackBtn.disabled = false;
        buybackBtn.textContent = originalText;
      }
    });
  })();
</script>
<?php endif; ?>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
