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
      <p class="muted">A clear guide from quote to delivery.</p>
    </div>
    <div class="content">
      <div class="stack">
        <h3>How does the hauling request and contract process work?</h3>
        <p>Our hauling process is designed to be clear, predictable, and easy to follow. Please complete each step carefully.</p>
        <ol>
          <li><strong>Get Quote:</strong> Fill in the quote form to calculate the hauling price and parameters.</li>
          <li><strong>Create Request:</strong> Review the quoted price and submit the request by selecting Create Request.</li>
          <li><strong>View Contract Instructions:</strong> Open View Contract Instructions to see the final contract details. This page contains the authoritative values that must be used when creating the in-game courier contract.</li>
          <li><strong>Create the In-Game Contract:</strong> Using the information from the Contract Instructions page, create a private courier contract in-game.</li>
          <li><strong>Contract Validation:</strong> Submitted contracts are validated only on pickup system, destination system, collateral, reward, and volume limit. Contracts that do not match these values may be rejected.</li>
          <li><strong>Hauling Execution:</strong> Once approved, the contract is accepted by a hauler and delivered to the destination.</li>
          <li><strong>Track Progress:</strong> You can follow the status of your requests and contracts via My Contracts.</li>
        </ol>
      </div>
      <div class="stack">
        <h3>Contract Instructions – Field Explanation</h3>
        <p>The Contract Instructions page shows the final values required to create your courier contract. Each field is explained below, along with what you must do in-game.</p>
        <div class="stack">
          <h4>Request</h4>
          <p>Internal request number and current status.</p>
          <p><strong>In-game action:</strong> None. Reference only.</p>
        </div>
        <div class="stack">
          <h4>Route</h4>
          <p>Shows the pickup and destination systems (for example: Jita → Eldjaerin).</p>
          <p><strong>In-game action:</strong></p>
          <ul>
            <li>Set the Start Location to the pickup system.</li>
            <li>Set the End Location to the destination system.</li>
            <li>Both systems must match exactly.</li>
          </ul>
        </div>
        <div class="stack">
          <h4>Contract Link State</h4>
          <p>Indicates whether a contract has been linked to this request.</p>
          <p><strong>In-game action:</strong> None.</p>
        </div>
        <div class="stack">
          <h4>Contract ID</h4>
          <p>Displays the in-game contract ID once a contract is linked.</p>
          <p><strong>In-game action:</strong> None.</p>
        </div>
        <div class="stack">
          <h4>Contract Status</h4>
          <p>Shows the current processing status of the request or contract.</p>
          <p><strong>In-game action:</strong> None.</p>
        </div>
        <div class="stack">
          <h4>Issuer</h4>
          <p>The organization providing the hauling service.</p>
          <p><strong>In-game action:</strong> None.</p>
        </div>
        <div class="stack">
          <h4>Private To</h4>
          <p>Specifies who the contract must be issued to.</p>
          <p><strong>In-game action:</strong></p>
          <ul>
            <li>Create a Private Courier Contract.</li>
            <li>Set the recipient exactly as shown.</li>
          </ul>
        </div>
        <div class="stack">
          <h4>Collateral</h4>
          <p>The ISK value paid out if the cargo is lost.</p>
          <p><strong>In-game action:</strong></p>
          <ul>
            <li>Enter the Collateral amount exactly as shown.</li>
            <li>This value is validated.</li>
          </ul>
        </div>
        <div class="stack">
          <h4>Reward</h4>
          <p>The ISK paid to the hauler on successful delivery.</p>
          <p><strong>In-game action:</strong></p>
          <ul>
            <li>Enter the Reward amount exactly as shown.</li>
            <li>This value is validated.</li>
          </ul>
        </div>
        <div class="stack">
          <h4>Volume Limit</h4>
          <p>The maximum allowed cargo volume for the contract.</p>
          <p><strong>In-game action:</strong></p>
          <ul>
            <li>Ensure the total item volume does not exceed this limit.</li>
            <li>Set the contract volume accordingly.</li>
            <li>This value is validated.</li>
          </ul>
        </div>
        <div class="stack">
          <h4>Contract Description Template</h4>
          <p>A reference string used to identify the contract (for example: Quote 0b30dbe99ede1189f4b499ca837d4756).</p>
          <p><strong>In-game action:</strong> Copy this text into the Contract Description field when creating the contract.</p>
        </div>
        <div class="stack">
          <h4>Important Notes</h4>
          <ul>
            <li>The Contract Instructions page is the single source of truth.</li>
            <li>Only the listed fields are validated during review.</li>
            <li>Exact matches are required to avoid rejection or delays.</li>
          </ul>
        </div>
      </div>
      <a class="btn ghost" href="{$dashboardUrl}">Back to dashboard</a>
    </div>
  </section>
  HTML;

require __DIR__ . '/../../src/Views/layout.php';
