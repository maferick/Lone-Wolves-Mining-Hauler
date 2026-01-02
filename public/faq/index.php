<?php
declare(strict_types=1);

// Standalone FAQ endpoint (works even if routing rules are bypassed)
require_once __DIR__ . '/../../src/bootstrap.php';

$appName = $config['app']['name'] ?? 'Corp Hauling';
$title = $appName . ' • FAQ';
$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$dashboardUrl = htmlspecialchars(($basePath ?: '') . '/', ENT_QUOTES, 'UTF-8');
$body = <<<HTML
  <section class="card">
    <div class="card-header">
      <h2>FAQ</h2>
      <p class="muted">How quotes turn into contracts.</p>
    </div>
    <div class="content">
      <div class="stack">
        <h3>What happens after I submit a quote request?</h3>
        <p>Once your request is saved, it enters our review queue. A dispatcher validates the route, volume, and timing details, then calculates a quote that reflects the current hauling schedule and ship class availability.</p>
        <ul>
          <li><strong>Request captured:</strong> Your origin, destination, volume, and time window are logged and tied to a request ID.</li>
          <li><strong>Rate analysis:</strong> We confirm the service class, fuel costs, and any special handling needs.</li>
          <li><strong>Quote issued:</strong> A formal quote is created with pricing, assumptions, and a brief summary.</li>
        </ul>
      </div>
      <div class="stack">
        <h3>How do I move from a quote to a contract?</h3>
        <p>When you approve the quote, we turn it into a contract so your job can be scheduled and tracked. The contract is the operational record that drives execution.</p>
        <ol>
          <li><strong>Confirm the quote:</strong> Double-check the cargo details and timing window, then approve the quote.</li>
          <li><strong>Contract draft:</strong> We generate a contract using the approved quote and attach the finalized terms.</li>
          <li><strong>Dispatcher review:</strong> A dispatcher validates the contract hint text, notes, and any pickup/delivery constraints.</li>
          <li><strong>Contract attached:</strong> The signed or accepted contract is linked to the request and becomes the source of truth for fulfillment.</li>
        </ol>
      </div>
      <div class="stack">
        <h3>What information should be included to avoid delays?</h3>
        <p>Clear details speed up approval and scheduling. We recommend including:</p>
        <ul>
          <li>Exact pickup and drop-off location names, including station or structure identifiers.</li>
          <li>Total volume and any special cargo handling needs (fragile, time-sensitive, or split loads).</li>
          <li>Preferred completion window and any blackout times.</li>
          <li>Point of contact for handoff and confirmation steps.</li>
        </ul>
      </div>
      <a class="btn ghost" href="{$dashboardUrl}">Back to dashboard</a>
    </div>
  </section>
  HTML;

require __DIR__ . '/../../src/Views/layout.php';
