<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$authCtx = $authCtx ?? ($GLOBALS['authCtx'] ?? []);
$displayName = (string)($authCtx['display_name'] ?? 'Pilot');

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>User Profile</h2>
    <p class="muted">Manage your hauling preferences and account integrations.</p>
  </div>
  <div class="content">
    <div class="row" style="align-items:center;">
      <div>
        <div class="label">Account</div>
        <div><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
  </div>
</section>
<section class="card js-hauler-profile" data-base-path="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>">
  <div class="card-header">
    <h2>Hauler Capability Profile</h2>
    <p class="muted">Tell dispatch what hulls you can fly and the cargo capacity you want to target.</p>
  </div>
  <div class="content">
    <div class="row" style="flex-wrap:wrap;">
      <label class="form-field" style="min-width:220px;">
        <span class="form-label">Can fly Freighter</span>
        <input type="checkbox" data-profile-field="can_fly_freighter" />
      </label>
      <label class="form-field" style="min-width:220px;">
        <span class="form-label">Can fly Jump Freighter</span>
        <input type="checkbox" data-profile-field="can_fly_jump_freighter" />
      </label>
      <label class="form-field" style="min-width:220px;">
        <span class="form-label">Can fly Deep Space Transport</span>
        <input type="checkbox" data-profile-field="can_fly_dst" />
      </label>
      <label class="form-field" style="min-width:220px;">
        <span class="form-label">Can fly Blockade Runner</span>
        <input type="checkbox" data-profile-field="can_fly_br" />
      </label>
    </div>
    <div class="row" style="align-items:flex-end;">
      <label class="form-field" style="min-width:240px;">
        <span class="form-label">Preferred service class</span>
        <select class="input" data-profile-field="preferred_service_class">
          <option value="">Auto-select</option>
          <option value="BR">Blockade Runner (BR)</option>
          <option value="DST">Deep Space Transport (DST)</option>
          <option value="JF">Jump Freighter (JF)</option>
          <option value="FREIGHTER">Freighter</option>
        </select>
      </label>
      <label class="form-field" style="min-width:240px;">
        <span class="form-label">Max cargo override (m³)</span>
        <input class="input" type="number" step="0.01" min="0" placeholder="Optional" data-profile-field="max_cargo_m3_override" />
      </label>
      <button class="btn" type="button" data-profile-save>Save profile</button>
    </div>
    <div class="muted" data-profile-note style="margin-top:8px;"></div>
  </div>
</section>
<section class="card" data-discord-link-card="true"
  data-link-status-url="<?= htmlspecialchars(($basePath ?: '') . '/api/discord/link-status/', ENT_QUOTES, 'UTF-8') ?>"
  data-link-code-url="<?= htmlspecialchars(($basePath ?: '') . '/api/discord/link-code/', ENT_QUOTES, 'UTF-8') ?>"
  data-unlink-url="<?= htmlspecialchars(($basePath ?: '') . '/api/discord/unlink/', ENT_QUOTES, 'UTF-8') ?>">
  <div class="card-header">
    <h2>Discord Account Linking</h2>
    <p class="muted">Generate a one-time code to connect your portal account to Discord commands.</p>
  </div>
  <div class="content">
    <div class="pill pill-danger" data-discord-link-error style="display:none;"></div>
    <div data-discord-link-status class="muted">Checking link status…</div>
    <div data-discord-link-user style="margin-top:8px;"></div>
    <div data-discord-link-code-block style="display:none; margin-top:12px;">
      <div><strong>Link code:</strong> <span data-discord-link-code></span></div>
      <div class="muted" data-discord-link-expires>Expires in 10 minutes.</div>
      <div class="muted">Run <strong>/link &lt;code&gt;</strong> in Discord to finish linking.</div>
    </div>
    <div style="margin-top:12px;">
      <button class="btn" type="button" data-discord-link-generate>Generate link code</button>
      <button class="btn ghost" type="button" data-discord-link-unlink style="display:none;">Unlink</button>
    </div>
  </div>
</section>
<script src="<?= ($basePath ?: '') ?>/assets/js/profile.js" defer></script>
<script src="<?= ($basePath ?: '') ?>/assets/js/discord-linking.js" defer></script>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
