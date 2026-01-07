(() => {
  const root = document.querySelector('.js-my-contracts');
  if (!root) return;
  const basePath = root.dataset.basePath || '';

  const sendJson = async (url, payload) => {
    const resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });
    return resp.json();
  };

  document.querySelectorAll('.js-delete-request').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      if (!requestId) return;
      if (!confirm('Delete this contract request? This cannot be undone.')) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/delete/`, { request_id: requestId });
      if (!data.ok) {
        alert(data.error || 'Delete failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });
})();
