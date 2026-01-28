(() => {
  const root = document.querySelector('.js-contract-compare');
  if (!root) return;

  const button = root.querySelector('.js-run-contract-match');
  const statusEl = root.querySelector('.js-contract-match-status');
  if (!button || !statusEl) return;

  const basePath = root.dataset.basePath || '';

  const setStatus = (message) => {
    statusEl.textContent = message;
  };

  button.addEventListener('click', async () => {
    button.disabled = true;
    setStatus('Queuing contract linking...');

    try {
      const resp = await fetch(`${basePath}/api/cron/tasks/run/`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          task_key: 'cron.contract_match',
          force: 1,
        }),
      });
      const data = await resp.json();
      if (!data.ok) {
        setStatus(data.error || 'Unable to queue contract linking.');
        button.disabled = false;
        return;
      }
      setStatus('Contract linking queued. Refresh to see updates.');
    } catch (err) {
      setStatus('Unable to queue contract linking.');
      button.disabled = false;
    }
  });
})();
