(() => {
  const root = document.querySelector('.js-esi-contract-link');
  if (!root) return;

  const basePath = root.dataset.basePath || '';
  const attachEnabled = root.dataset.attachEnabled === '1';
  const canManage = root.dataset.canManage === '1';

  if (!attachEnabled || !canManage) return;

  const buttons = root.querySelectorAll('.js-link-contract');
  if (!buttons.length) return;

  const getStatusEl = (contractId) =>
    root.querySelector(`.js-link-status[data-contract-id="${contractId}"]`);

  buttons.forEach((button) => {
    button.addEventListener('click', async () => {
      const contractId = parseInt(button.dataset.contractId || '0', 10);
      const select = root.querySelector(
        `.js-contract-select[data-contract-id="${contractId}"]`
      );
      const statusEl = getStatusEl(button.dataset.contractId || '');
      const quoteId = parseInt((select && select.value) || '0', 10);

      if (!select || !statusEl) return;

      if (!quoteId) {
        statusEl.textContent = 'Select a request first.';
        return;
      }

      button.disabled = true;
      select.disabled = true;
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
          button.disabled = false;
          select.disabled = false;
          return;
        }
        statusEl.textContent = 'Contract linked and queued.';
      } catch (err) {
        statusEl.textContent = 'Contract attach failed.';
        button.disabled = false;
        select.disabled = false;
      }
    });
  });
})();
