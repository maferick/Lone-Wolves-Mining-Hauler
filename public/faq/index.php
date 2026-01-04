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
      <h2>Frequently Asked Questions</h2>
      <p class="muted">Everything you need to submit a clean courier contract.</p>
    </div>
    <div class="content">
      <div class="stack">
        <h3>How does the hauling process work?</h3>
        <ol>
          <li><strong>Get Quote</strong> – Enter pickup, destination, volume, and options to calculate the price.</li>
          <li><strong>Create Request</strong> – Confirm the quote to create a hauling request.</li>
          <li><strong>Contract Instructions</strong> – Open View Contract Instructions to see the exact values you must use.</li>
          <li><strong>Create In-Game Contract</strong> – Create a private courier contract using those values.</li>
          <li><strong>Execution &amp; Tracking</strong> – A hauler accepts the contract in-game; progress updates automatically.</li>
        </ol>
      </div>
      <div class="stack">
        <h3>What is validated on my contract?</h3>
        <p class="muted">Only these fields are validated against the request:</p>
        <ul>
          <li>Pickup system</li>
          <li>Destination system</li>
          <li>Collateral</li>
          <li>Reward</li>
          <li>Volume limit</li>
        </ul>
        <p>If any of these do not match exactly, the contract may be rejected or delayed.</p>
      </div>
      <div class="stack">
        <h3>What do I need to do in-game?</h3>
        <ul>
          <li>Create a <strong>Private Courier Contract</strong>.</li>
          <li>Set <strong>Start Location</strong> and <strong>End Location</strong> exactly as shown.</li>
          <li>Enter <strong>Collateral</strong> and <strong>Reward</strong> exactly as listed.</li>
          <li>Ensure total item volume is within the <strong>Volume Limit</strong>.</li>
          <li>Copy the <strong>Contract Description Template</strong> into the description field.</li>
        </ul>
        <p class="muted">Everything else is informational.</p>
      </div>
      <div class="stack">
        <h3>What is the Contract Description Template?</h3>
        <p>A reference string (for example: <strong>Quote a6da9f5422cda4afc5574f1f0d2064c8</strong>) used to link your in-game contract to the request. Always copy it exactly into the contract description.</p>
      </div>
      <div class="stack">
        <h3>How do I track my haul?</h3>
        <p>Use <strong>My Contracts</strong> on the site:</p>
        <ul>
          <li>Status updates come from in-game contract state (ESI).</li>
          <li><strong>Picked up / En route</strong> means the contract has been accepted in-game.</li>
          <li>Delivery, failure, or expiry are updated automatically.</li>
        </ul>
      </div>
      <div class="alert alert-warning">
        <strong>Important</strong>
        <ul>
          <li>The Contract Instructions page is the single source of truth.</li>
          <li>Exact matches prevent delays.</li>
          <li>In-game status always overrides manual tracking.</li>
        </ul>
      </div>
      <a class="btn ghost" href="{$dashboardUrl}">Back to dashboard</a>
    </div>
  </section>
  HTML;

require __DIR__ . '/../../src/Views/layout.php';
