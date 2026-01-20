<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\Auth;
use App\Db\Db;

$authCtx = Auth::context($db);
Auth::requireLogin($authCtx);
Auth::requireAdmin($authCtx);

$corpId = (int)($authCtx['corp_id'] ?? 0);
if ($corpId <= 0) { http_response_code(400); echo "No corp context"; exit; }

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • Defaults';

$msg = null;
$errors = [];

$defaults = [
  'access.rules' => [
    'systems' => [],
    'regions' => [],
    'structures' => [],
  ],
  'discord.templates' => [
    'request_post' => [
      'enabled' => true,
    ],
  ],
];

$settingRows = $db->select(
  "SELECT setting_key, setting_json FROM app_setting
    WHERE corp_id = :cid AND setting_key IN ('access.rules','discord.templates')",
  ['cid' => $corpId]
);

$settings = $defaults;
foreach ($settingRows as $row) {
  $key = (string)$row['setting_key'];
  if (!array_key_exists($key, $settings)) continue;
  if (empty($row['setting_json'])) continue;
  try {
    $decoded = Db::jsonDecode((string)$row['setting_json'], []);
  } catch (Throwable $e) {
    $decoded = [];
  }
  if (is_array($decoded) && $decoded !== []) {
    $settings[$key] = array_replace_recursive($settings[$key], $decoded);
  }
}

$systemOptions = [];
$regionOptions = [];
$structureOptions = [];
try {
  $systemOptions = $db->select("SELECT system_id, system_name FROM eve_system ORDER BY system_name");
  $regionOptions = $db->select("SELECT region_id, region_name FROM eve_region ORDER BY region_name");
  $structureOptions = $db->select(
    "SELECT st.station_id AS structure_id, st.station_name AS structure_name,
            COALESCE(ms.system_name, s.system_name) AS system_name
       FROM eve_station st
       LEFT JOIN map_system ms ON ms.system_id = st.system_id
       LEFT JOIN eve_system s ON s.system_id = st.system_id
      UNION ALL
     SELECT es.structure_id AS structure_id, es.structure_name AS structure_name,
            COALESCE(ms2.system_name, s2.system_name) AS system_name
       FROM eve_structure es
       LEFT JOIN map_system ms2 ON ms2.system_id = es.system_id
       LEFT JOIN eve_system s2 ON s2.system_id = es.system_id
      ORDER BY structure_name"
  );
} catch (Throwable $e) {
  $systemOptions = [];
  $regionOptions = [];
  $structureOptions = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $requestPostEnabled = isset($_POST['discord_request_post_enabled']);

  $systemNameToId = [];
  foreach ($systemOptions as $row) {
    $systemNameToId[strtolower((string)$row['system_name'])] = (int)$row['system_id'];
  }
  $regionNameToId = [];
  foreach ($regionOptions as $row) {
    $regionNameToId[strtolower((string)$row['region_name'])] = (int)$row['region_id'];
  }
  $structureNameToId = [];
  $structureIdToName = [];
  foreach ($structureOptions as $row) {
    $structureId = (int)($row['structure_id'] ?? 0);
    $structureName = (string)($row['structure_name'] ?? '');
    if ($structureId <= 0 || $structureName === '') {
      continue;
    }
    $structureNameToId[strtolower($structureName)] = $structureId;
    $structureIdToName[$structureId] = $structureName;
  }

  $parseAccessValue = static function (string $value, array $nameMap): array {
    $value = trim($value);
    $id = 0;
    $name = $value;
    if (preg_match('/\[(\d+)\]\s*$/', $value, $matches)) {
      $id = (int)$matches[1];
      $name = trim(preg_replace('/\s*\[\d+\]\s*$/', '', $value));
    }
    if ($id === 0 && $name !== '') {
      $lookup = strtolower($name);
      $id = $nameMap[$lookup] ?? 0;
    }
    return [$id, $name];
  };

  $accessRules = [
    'systems' => [],
    'regions' => [],
    'structures' => [],
  ];

  $postedSystems = $_POST['access_systems'] ?? [];
  foreach ($postedSystems as $entry) {
    if (!empty($entry['remove'])) continue;
    $id = (int)($entry['id'] ?? 0);
    $name = trim((string)($entry['name'] ?? ''));
    if ($id <= 0 || $name === '') continue;
    $accessRules['systems'][] = [
      'id' => $id,
      'name' => $name,
      'allowed' => !empty($entry['allowed']),
    ];
  }

  $postedRegions = $_POST['access_regions'] ?? [];
  foreach ($postedRegions as $entry) {
    if (!empty($entry['remove'])) continue;
    $id = (int)($entry['id'] ?? 0);
    $name = trim((string)($entry['name'] ?? ''));
    if ($id <= 0 || $name === '') continue;
    $accessRules['regions'][] = [
      'id' => $id,
      'name' => $name,
      'allowed' => !empty($entry['allowed']),
    ];
  }

  $postedStructures = $_POST['access_structures'] ?? [];
  foreach ($postedStructures as $entry) {
    if (!empty($entry['remove'])) continue;
    $id = (int)($entry['id'] ?? 0);
    $name = trim((string)($entry['name'] ?? ''));
    if ($id <= 0 || $name === '') continue;
    $accessRules['structures'][] = [
      'id' => $id,
      'name' => $name,
      'allowed' => !empty($entry['allowed']),
      'pickup_allowed' => !empty($entry['pickup_allowed']),
      'delivery_allowed' => !empty($entry['delivery_allowed']),
    ];
  }

  $newAccessType = (string)($_POST['new_access_type'] ?? '');
  $newAccessValue = (string)($_POST['new_access_value'] ?? '');
  $newAccessAllowed = isset($_POST['new_access_allowed']);
  $newAccessPickup = isset($_POST['new_access_pickup']);
  $newAccessDelivery = isset($_POST['new_access_delivery']);

  if ($newAccessType !== '' || trim($newAccessValue) !== '') {
    $allowedTypes = ['system', 'region', 'structure'];
    if (!in_array($newAccessType, $allowedTypes, true)) {
      $errors[] = 'Please select a valid access rule type.';
    } else {
      if ($newAccessType === 'system') {
        [$id, $name] = $parseAccessValue($newAccessValue, $systemNameToId);
        if ($id <= 0 || $name === '') {
          $errors[] = 'Please select a valid system.';
        } else {
          $accessRules['systems'][] = ['id' => $id, 'name' => $name, 'allowed' => $newAccessAllowed];
        }
      } elseif ($newAccessType === 'region') {
        [$id, $name] = $parseAccessValue($newAccessValue, $regionNameToId);
        if ($id <= 0 || $name === '') {
          $errors[] = 'Please select a valid region.';
        } else {
          $accessRules['regions'][] = ['id' => $id, 'name' => $name, 'allowed' => $newAccessAllowed];
        }
      } elseif ($newAccessType === 'structure') {
        [$id, $name] = $parseAccessValue($newAccessValue, $structureNameToId);
        if ($id > 0 && isset($structureIdToName[$id])) {
          $name = $structureIdToName[$id];
        }
        if ($id <= 0 || $name === '') {
          $errors[] = 'Please select a valid structure.';
        } else {
          $accessRules['structures'][] = [
            'id' => $id,
            'name' => $name,
            'allowed' => $newAccessAllowed,
            'pickup_allowed' => $newAccessPickup,
            'delivery_allowed' => $newAccessDelivery,
          ];
        }
      }
    }
  }

  if ($errors === []) {
    $updates = [
      'access.rules' => $accessRules,
      'discord.templates' => [
        'request_post' => [
          'enabled' => $requestPostEnabled,
        ],
      ],
    ];

    $db->tx(function(Db $db) use ($corpId, $updates, $authCtx) {
      foreach ($updates as $key => $payload) {
        $beforeRow = $db->one(
          "SELECT setting_json FROM app_setting WHERE corp_id = :cid AND setting_key = :key",
          ['cid' => $corpId, 'key' => $key]
        );
        $before = null;
        if ($beforeRow && !empty($beforeRow['setting_json'])) {
          $before = Db::jsonDecode((string)$beforeRow['setting_json'], null);
        }

        $db->execute(
          "INSERT INTO app_setting (corp_id, setting_key, setting_json, updated_by_user_id)
           VALUES (:cid, :key, :json, :uid)
           ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json), updated_by_user_id = VALUES(updated_by_user_id)",
          [
            'cid' => $corpId,
            'key' => $key,
            'json' => Db::jsonEncode($payload),
            'uid' => $authCtx['user_id'],
          ]
        );

        $db->audit(
          $corpId,
          $authCtx['user_id'],
          $authCtx['character_id'],
          'setting.update',
          'app_setting',
          $key,
          $before,
          $payload,
          $_SERVER['REMOTE_ADDR'] ?? null,
          $_SERVER['HTTP_USER_AGENT'] ?? null
        );
      }
    });

    $settings = array_replace_recursive($settings, $updates);
    $msg = 'Saved.';
  }
}

ob_start();
require __DIR__ . '/../../../src/Views/partials/admin_nav.php';
?>
<section class="card">
  <div class="card-header">
    <h2>Defaults</h2>
    <p class="muted">Configure pricing, routing, and Discord templates for your corp.</p>
  </div>

  <div class="content">
    <?php if ($msg): ?>
      <div class="pill"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="pill warning">
        <?= htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header">
          <h3>Access Rules</h3>
          <p class="muted">Control which systems, regions, and structures are allowed for quotes.</p>
        </div>
        <div class="content">
          <?php $systemRules = $settings['access.rules']['systems'] ?? []; ?>
          <?php $regionRules = $settings['access.rules']['regions'] ?? []; ?>
          <?php $structureRules = $settings['access.rules']['structures'] ?? []; ?>

          <div style="margin-bottom:16px;">
            <h4>Systems</h4>
            <?php if ($systemRules): ?>
              <div class="table-pager" data-paginated-table data-page-size="10">
                <table class="table" data-pager-table>
                <thead>
                  <tr>
                    <th>System</th>
                    <th>Allow</th>
                    <th>Remove</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($systemRules as $idx => $rule): ?>
                    <tr>
                      <td>
                        <?= htmlspecialchars((string)($rule['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        <input type="hidden" name="access_systems[<?= (int)$idx ?>][id]" value="<?= (int)($rule['id'] ?? 0) ?>" />
                        <input type="hidden" name="access_systems[<?= (int)$idx ?>][name]" value="<?= htmlspecialchars((string)($rule['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                      </td>
                      <td>
                        <input type="checkbox" name="access_systems[<?= (int)$idx ?>][allowed]" aria-label="Allow system" <?= !empty($rule['allowed']) ? 'checked' : '' ?> />
                      </td>
                      <td>
                        <button class="btn ghost js-remove-row" type="button" aria-label="Remove system rule">
                          Remove
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                </table>
                <div class="table-pager__footer" data-pager-footer>
                  <div class="table-pager__summary" data-pager-summary></div>
                  <div class="table-pager__controls">
                    <label class="table-pager__size">
                      Rows
                      <select class="input input--sm" data-pager-size>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                      </select>
                    </label>
                    <button class="btn ghost" type="button" data-pager-prev>Previous</button>
                    <span class="table-pager__page" data-pager-page></span>
                    <button class="btn ghost" type="button" data-pager-next>Next</button>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <p class="muted">No system rules added yet.</p>
            <?php endif; ?>
          </div>

          <div style="margin-bottom:16px;">
            <h4>Regions</h4>
            <?php if ($regionRules): ?>
              <div class="table-pager" data-paginated-table data-page-size="10">
                <table class="table" data-pager-table>
                <thead>
                  <tr>
                    <th>Region</th>
                    <th>Allow</th>
                    <th>Remove</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($regionRules as $idx => $rule): ?>
                    <tr>
                      <td>
                        <?= htmlspecialchars((string)($rule['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        <input type="hidden" name="access_regions[<?= (int)$idx ?>][id]" value="<?= (int)($rule['id'] ?? 0) ?>" />
                        <input type="hidden" name="access_regions[<?= (int)$idx ?>][name]" value="<?= htmlspecialchars((string)($rule['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                      </td>
                      <td>
                        <input type="checkbox" name="access_regions[<?= (int)$idx ?>][allowed]" aria-label="Allow region" <?= !empty($rule['allowed']) ? 'checked' : '' ?> />
                      </td>
                      <td>
                        <button class="btn ghost js-remove-row" type="button" aria-label="Remove region rule">
                          Remove
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                </table>
                <div class="table-pager__footer" data-pager-footer>
                  <div class="table-pager__summary" data-pager-summary></div>
                  <div class="table-pager__controls">
                    <label class="table-pager__size">
                      Rows
                      <select class="input input--sm" data-pager-size>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                      </select>
                    </label>
                    <button class="btn ghost" type="button" data-pager-prev>Previous</button>
                    <span class="table-pager__page" data-pager-page></span>
                    <button class="btn ghost" type="button" data-pager-next>Next</button>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <p class="muted">No region rules added yet.</p>
            <?php endif; ?>
          </div>

          <div style="margin-bottom:16px;">
            <h4>Structures</h4>
            <?php if ($structureRules): ?>
              <div class="table-pager" data-paginated-table data-page-size="10">
                <table class="table" data-pager-table>
                <thead>
                  <tr>
                    <th>Structure</th>
                    <th>Allow</th>
                    <th>Pickup</th>
                    <th>Delivery</th>
                    <th>Remove</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($structureRules as $idx => $rule): ?>
                    <tr>
                      <td>
                        <?= htmlspecialchars((string)($rule['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        <input type="hidden" name="access_structures[<?= (int)$idx ?>][id]" value="<?= (int)($rule['id'] ?? 0) ?>" />
                        <input type="hidden" name="access_structures[<?= (int)$idx ?>][name]" value="<?= htmlspecialchars((string)($rule['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                      </td>
                      <td>
                        <input type="checkbox" name="access_structures[<?= (int)$idx ?>][allowed]" aria-label="Allow structure" <?= !empty($rule['allowed']) ? 'checked' : '' ?> />
                      </td>
                      <td>
                        <input type="checkbox" name="access_structures[<?= (int)$idx ?>][pickup_allowed]" aria-label="Pickup allowed" <?= !empty($rule['pickup_allowed']) ? 'checked' : '' ?> />
                      </td>
                      <td>
                        <input type="checkbox" name="access_structures[<?= (int)$idx ?>][delivery_allowed]" aria-label="Delivery allowed" <?= !empty($rule['delivery_allowed']) ? 'checked' : '' ?> />
                      </td>
                      <td>
                        <button class="btn ghost js-remove-row" type="button" aria-label="Remove structure rule">
                          Remove
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                </table>
                <div class="table-pager__footer" data-pager-footer>
                  <div class="table-pager__summary" data-pager-summary></div>
                  <div class="table-pager__controls">
                    <label class="table-pager__size">
                      Rows
                      <select class="input input--sm" data-pager-size>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                      </select>
                    </label>
                    <button class="btn ghost" type="button" data-pager-prev>Previous</button>
                    <span class="table-pager__page" data-pager-page></span>
                    <button class="btn ghost" type="button" data-pager-next>Next</button>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <p class="muted">No structure rules added yet.</p>
            <?php endif; ?>
          </div>

          <div class="card" style="margin-bottom:12px;">
            <div class="card-header">
              <h4>Add Access Rule</h4>
              <p class="muted">Type at least two letters to see matching systems, regions, or structures.</p>
            </div>
            <div class="content">
              <div class="row">
                <div>
                  <div class="label">Type</div>
                  <select class="input" name="new_access_type" id="access-rule-type">
                    <option value="">Select type</option>
                    <option value="system">System</option>
                    <option value="region">Region</option>
                    <option value="structure">Structure</option>
                  </select>
                </div>
                <div>
                  <div class="label">Name</div>
                  <input class="input" type="text" name="new_access_value" id="access-rule-value" list="access-systems-list" placeholder="Start typing…" />
                </div>
                <div>
                  <div class="label">Allow</div>
                  <label class="checkbox">
                    <input type="checkbox" name="new_access_allowed" />
                    Yes
                  </label>
                </div>
              </div>
              <div class="row" id="access-structure-flags" style="margin-top:10px; display:none;">
                <div>
                  <div class="label">Structure access</div>
                  <label class="checkbox">
                    <input type="checkbox" name="new_access_pickup" />
                    Pickup allowed
                  </label>
                  <label class="checkbox" style="margin-top:8px;">
                    <input type="checkbox" name="new_access_delivery" />
                    Delivery allowed
                  </label>
                  <div class="muted" style="margin-top:6px;">Structures default to no pickup/delivery access.</div>
                </div>
              </div>
              <datalist id="access-systems-list"></datalist>
              <datalist id="access-regions-list"></datalist>
              <datalist id="access-structures-list"></datalist>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3>Discord Templates</h3>
          <p class="muted">Enable or disable template blocks for automated posts.</p>
        </div>
        <div class="content">
          <label class="checkbox">
            <input type="checkbox" name="discord_request_post_enabled" <?= !empty($settings['discord.templates']['request_post']['enabled']) ? 'checked' : '' ?> />
            Enable request post template
          </label>
        </div>
      </div>

      <div style="margin-top:14px; display:flex; gap:10px;">
        <button class="btn" type="submit">Save</button>
        <a class="btn ghost" href="<?= ($basePath ?: '') ?>/admin/">Back</a>
      </div>
    </form>
    <div
      class="js-defaults-config"
      data-access-systems="<?= htmlspecialchars(json_encode(array_map(static fn($row) => ['id' => (int)$row['system_id'], 'name' => (string)$row['system_name']], $systemOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
      data-access-regions="<?= htmlspecialchars(json_encode(array_map(static fn($row) => ['id' => (int)$row['region_id'], 'name' => (string)$row['region_name']], $regionOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
      data-access-structures="<?= htmlspecialchars(json_encode(array_map(static fn($row) => [
        'id' => (int)$row['structure_id'],
        'name' => (string)$row['structure_name'],
        'system_name' => (string)($row['system_name'] ?? ''),
      ], $structureOptions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>"
      data-structure-search-url="<?= htmlspecialchars(($basePath ?: '') . '/api/admin/stations/search', ENT_QUOTES, 'UTF-8') ?>"
    ></div>
    <script src="<?= ($basePath ?: '') ?>/assets/js/admin/defaults.js" defer></script>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/../../../src/Views/layout.php';
