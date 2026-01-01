<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$isLoggedIn = !empty($authCtx['user_id']);
$canAdmin = $isLoggedIn && \App\Auth\Auth::can($authCtx, 'corp.manage');
$displayName = (string)($authCtx['display_name'] ?? 'Guest');
$corpName = (string)($config['corp']['name'] ?? $config['app']['name'] ?? 'Corp Hauling');
$queueStats = $queueStats ?? ['outstanding' => 0, 'in_progress' => 0, 'completed' => 0];
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
$quoteInput = $quoteInput ?? ['pickup_system' => '', 'destination_system' => '', 'volume' => '', 'collateral' => ''];
$quoteErrors = $quoteErrors ?? [];
$quoteResult = $quoteResult ?? null;
$pickupLocationOptions = $pickupLocationOptions ?? [];
$destinationLocationOptions = $destinationLocationOptions ?? [];

ob_start();
?>
<section class="grid">
  <div class="card">
    <div class="card-header">
      <h2>Queue status</h2>
      <p class="muted">Live snapshot of the hauling queue.</p>
      <?php if ($isLoggedIn): ?>
        <div class="pill subtle" style="margin-top:10px; display:inline-flex;">Signed in as <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
    <div class="kpi-row">
      <div class="kpi">
        <div class="kpi-label">Outstanding</div>
        <div class="kpi-value"><?= number_format((int)$queueStats['outstanding']) ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">In Progress</div>
        <div class="kpi-value"><?= number_format((int)$queueStats['in_progress']) ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Completed (Last day)</div>
        <div class="kpi-value"><?= number_format((int)$queueStats['completed']) ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>Courier contract status</h2>
      <p class="muted">ESI-fed courier contracts currently in flow.</p>
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

  <div class="card">
    <div class="card-header">
      <h2>Service Update - Dedicated Alliance Service</h2>
      <p class="muted">Priority updates for alliance members.</p>
    </div>
    <ul class="list">
      <li><span class="badge">1</span> Alliance JF Service</li>
      <li><span class="badge">2</span> Mailbox Service</li>
      <li><span class="badge">3</span> Double Wraps/Assembled Containers allowed (note in contract)</li>
      <li><span class="badge">4</span> Discord Consultation</li>
      <li><span class="badge">5</span> JF Round Trip / Bulk discounts</li>
    </ul>
  </div>

  <div class="card" id="quote">
    <div class="card-header">
      <h2>Get Quote</h2>
      <p class="muted">Enter your lane details to receive a preliminary quote.</p>
    </div>
    <form method="post" class="content" action="<?= htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8') ?>">
      <div class="form-grid">
        <label class="form-field">
          <span class="form-label">Pickup system or structure</span>
          <input class="input" type="text" name="pickup_system" list="pickup-location-list" value="<?= htmlspecialchars($quoteInput['pickup_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Jita" autocomplete="off" />
        </label>
        <label class="form-field">
          <span class="form-label">Destination system or structure</span>
          <input class="input" type="text" name="destination_system" list="destination-location-list" value="<?= htmlspecialchars($quoteInput['destination_system'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Amarr" autocomplete="off" />
        </label>
        <label class="form-field">
          <span class="form-label">Volume</span>
          <select class="input" name="volume">
            <option value="">Select volume</option>
            <?php
            $volumes = [
              '12500' => '12,500 m³',
              '62500' => '62,500 m³',
              '360000' => '360,000 m³',
              '950000' => '950,000 m³',
            ];
            foreach ($volumes as $value => $label):
              $value = (string)$value;
              $selected = $quoteInput['volume'] === $value ? 'selected' : '';
            ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selected ?>><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="form-field">
          <span class="form-label">Collateral</span>
          <input class="input" type="text" name="collateral" value="<?= htmlspecialchars($quoteInput['collateral'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 300m, 2.65b, 400,000,000" />
        </label>
      </div>
      <div class="card-footer">
        <button class="btn" type="submit">Quote</button>
      </div>
      <?php if ($quoteErrors): ?>
        <div class="alert alert-warning">
          <ul>
            <?php foreach ($quoteErrors as $error): ?>
              <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($quoteResult): ?>
        <div class="alert alert-success">
          <strong>Quote:</strong> <?= htmlspecialchars($quoteResult['quote'], ENT_QUOTES, 'UTF-8') ?> (<?= number_format($quoteResult['volume']) ?> m³, collateral <?= number_format((float)$quoteResult['collateral'], 2) ?> ISK)
        </div>
      <?php endif; ?>
      <datalist id="pickup-location-list"></datalist>
      <datalist id="destination-location-list"></datalist>
    </form>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>How to make a contract</h2>
      <p class="muted">Short, corp-specific checklist.</p>
    </div>
    <ul class="list">
      <li><span class="badge">1</span> Get quote</li>
      <li><span class="badge">2</span> Use Janice SELL VALUE as collateral</li>
      <li><span class="badge">3</span> Create private courier contract to <?= htmlspecialchars($corpName, ENT_QUOTES, 'UTF-8') ?></li>
      <li><span class="badge">4</span> Mention assembled containers / wraps in description</li>
      <li><span class="badge">5</span> Join corp Discord channel</li>
    </ul>
  </div>
</section>
<script>
  (() => {
    const pickupLocations = <?= json_encode($pickupLocationOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const destinationLocations = <?= json_encode($destinationLocationOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const minChars = 3;

    const buildOptions = (listEl, items, value) => {
      listEl.innerHTML = '';
      if (!value || value.length < minChars) return;
      const query = value.toLowerCase();
      let count = 0;
      for (const item of items || []) {
        if (!item.name.toLowerCase().startsWith(query)) continue;
        const option = document.createElement('option');
        option.value = item.name;
        if (item.label) option.label = item.label;
        listEl.appendChild(option);
        count += 1;
        if (count >= 50) break;
      }
    };

    const pickupInput = document.querySelector('input[name="pickup_system"]');
    const destinationInput = document.querySelector('input[name="destination_system"]');
    const pickupList = document.getElementById('pickup-location-list');
    const destinationList = document.getElementById('destination-location-list');

    pickupInput?.addEventListener('input', () => {
      buildOptions(pickupList, pickupLocations, pickupInput.value);
    });
    destinationInput?.addEventListener('input', () => {
      buildOptions(destinationList, destinationLocations, destinationInput.value);
    });
  })();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
