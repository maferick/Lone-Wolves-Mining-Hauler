(() => {
  const root = document.querySelector('.js-contract-attach');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const quoteId = parseInt(root.dataset.quoteId || '0', 10);
  const attachBtn = document.getElementById('attach-contract');
  const contractInput = document.getElementById('contract-id');
  const statusEl = document.getElementById('attach-status');

  if (!attachBtn || !contractInput || !statusEl || !quoteId) return;

  attachBtn.addEventListener('click', async () => {
    const contractId = parseInt(contractInput.value || '0', 10);
    if (!contractId) {
      statusEl.textContent = 'Contract ID required.';
      return;
    }
    attachBtn.disabled = true;
    statusEl.textContent = 'Validating contract via ESI...';

    try {
      const resp = await fetch(`${basePath}/api/contracts/attach/`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          quote_id: quoteId,
          contract_id: contractId,
        }),
      });
      const data = await resp.json();
      if (!data.ok) {
        statusEl.textContent = data.error || 'Contract attach failed.';
        return;
      }
      statusEl.textContent = 'Contract validated and queued. Discord webhook queued.';
    } catch (err) {
      statusEl.textContent = 'Contract attach failed.';
    } finally {
      attachBtn.disabled = false;
    }
  });
})();
