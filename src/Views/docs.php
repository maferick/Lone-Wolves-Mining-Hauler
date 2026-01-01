<?php
declare(strict_types=1);

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');

ob_start();
?>
<section class="card">
  <div class="card-header">
    <h2>Docs</h2>
    <p class="muted">Repo conventions and include pattern.</p>
  </div>

  <div class="content">
    <h3>Include Pattern</h3>
    <pre><code>config → dbfunctions → auth → services → route handler</code></pre>

    <h3>DB Layer Contract</h3>
    <ul class="list">
      <li><span class="badge">A</span> All SQL goes through <code>src/Db/dbfunctions.php</code></li>
      <li><span class="badge">B</span> Prefer views: <code>v_haul_request_display</code>, <code>v_contract_display</code></li>
      <li><span class="badge">C</span> Use <code>eve_entity</code> to show names (never raw IDs by default)</li>
    </ul>

    <h3>Next Step</h3>
    <p class="muted">We can generate the ESI module: scopes, token refresh, contract pull jobs, and a first “quote” endpoint.</p>
  </div>
</section>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
