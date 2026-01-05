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
$queueStats = $queueStats ?? ['outstanding' => 0];
$quoteInput = $quoteInput ?? ['pickup_location' => '', 'delivery_location' => ''];
$defaultPriority = $defaultPriority ?? 'normal';
$canCreateRequest = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.request.create');
$canBuybackHaulage = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'haul.buyback');
$buybackHaulageTiers = $buybackHaulageTiers ?? [];
$buybackHaulageEnabled = $buybackHaulageEnabled ?? false;
$pickupLocationOptions = $pickupLocationOptions ?? [];
$destinationLocationOptions = $destinationLocationOptions ?? [];
$bodyClass = 'home';
$logoPath = '/assets/logo.png';
$logoDiskPath = __DIR__ . '/../../public/assets/logo.png';
if (!file_exists($logoDiskPath)) {
  $logoPath = '/assets/logo.jpg';
  $logoDiskPath = __DIR__ . '/../../public/assets/logo.jpg';
}
$logoWebpPath = '/assets/logo.webp';
$logoWebpDiskPath = __DIR__ . '/../../public/assets/logo.webp';
$hasLogoWebp = file_exists($logoWebpDiskPath);
$logoWidth = 330;
$logoHeight = 220;

ob_start();
?>
<section class="grid">
  <div class="stack">
    <div class="card hero-card">
      <div class="hero-banner">
        <picture>
          <?php if ($hasLogoWebp): ?>
            <source srcset="<?= ($basePath ?: '') . $logoWebpPath ?>" type="image/webp" />
          <?php endif; ?>
          <img
            class="hero-logo"
            src="<?= ($basePath ?: '') . $logoPath ?>"
            alt="Lone Wolves Logistics logo"
            width="<?= $logoWidth ?>"
            height="<?= $logoHeight ?>"
            loading="eager"
            fetchpriority="high"
            decoding="async"
          />
        </picture>
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
            <div class="kpi-value"><?= number_format((int)($queueStats['outstanding'] ?? 0)) ?></div>
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
        <p class="muted">Enter pickup, delivery, and volume to get an instant breakdown.</p>
      </div>
      <div
        class="content js-quote-form"
        data-base-path="<?= htmlspecialchars($basePath ?: '', ENT_QUOTES, 'UTF-8') ?>"
        data-buyback-tiers="<?= htmlspecialchars(json_encode($buybackHaulageTiers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
        data-buyback-enabled="<?= $buybackHaulageEnabled ? '1' : '0' ?>"
      >
        <div class="form-grid">
          <label class="form-field">
            <span class="form-label">Pickup location</span>
            <input class="input" type="text" name="pickup_location" list="pickup-location-list" value="<?= htmlspecialchars($quoteInput['pickup_location'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Jita IV - Moon 4 - Caldari Navy Assembly Plant" autocomplete="off" />
            <input type="hidden" name="pickup_location_id" value="" />
            <input type="hidden" name="pickup_location_type" value="" />
            <input type="hidden" name="pickup_system_id" value="" />
          </label>
          <label class="form-field">
            <span class="form-label">Delivery location</span>
            <input class="input" type="text" name="delivery_location" list="destination-location-list" value="<?= htmlspecialchars($quoteInput['delivery_location'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Amarr VIII (Oris) - Emperor Family Academy" autocomplete="off" />
            <input type="hidden" name="delivery_location_id" value="" />
            <input type="hidden" name="delivery_location_type" value="" />
            <input type="hidden" name="delivery_system_id" value="" />
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
  <script src="<?= ($basePath ?: '') ?>/assets/js/home-quote.js" defer></script>
<?php endif; ?>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
