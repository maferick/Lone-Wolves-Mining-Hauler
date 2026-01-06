(() => {
  const card = document.querySelector('[data-discord-link-card="true"]');
  if (!card) {
    return;
  }

  const statusUrl = card.getAttribute('data-link-status-url');
  const codeUrl = card.getAttribute('data-link-code-url');
  const unlinkUrl = card.getAttribute('data-unlink-url');
  const statusEl = card.querySelector('[data-discord-link-status]');
  const userEl = card.querySelector('[data-discord-link-user]');
  const errorEl = card.querySelector('[data-discord-link-error]');
  const codeBlock = card.querySelector('[data-discord-link-code-block]');
  const codeEl = card.querySelector('[data-discord-link-code]');
  const expiresEl = card.querySelector('[data-discord-link-expires]');
  const generateBtn = card.querySelector('[data-discord-link-generate]');
  const unlinkBtn = card.querySelector('[data-discord-link-unlink]');

  const setError = (message) => {
    if (!errorEl) return;
    if (message) {
      errorEl.textContent = message;
      errorEl.style.display = 'inline-flex';
    } else {
      errorEl.textContent = '';
      errorEl.style.display = 'none';
    }
  };

  const formatDiscordUser = (data) => {
    const username = data.discord_username || '';
    const id = data.discord_user_id || '';
    if (username && id) {
      return `${username} (${id})`;
    }
    if (username) {
      return username;
    }
    if (id) {
      return id;
    }
    return 'Unknown';
  };

  const updateStatus = (data) => {
    if (!statusEl) return;
    if (data && data.linked) {
      statusEl.textContent = 'Discord account linked.';
      if (userEl) {
        userEl.textContent = `Linked Discord user: ${formatDiscordUser(data)}`;
      }
      if (unlinkBtn) {
        unlinkBtn.style.display = 'inline-flex';
      }
    } else {
      statusEl.textContent = 'No Discord account linked yet.';
      if (userEl) {
        userEl.textContent = '';
      }
      if (unlinkBtn) {
        unlinkBtn.style.display = 'none';
      }
    }
  };

  const requestJson = async (url, options = {}) => {
    const resp = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
      },
      ...options,
    });
    const payload = await resp.json().catch(() => ({}));
    if (!resp.ok || payload.ok === false) {
      const message = payload.message || payload.error || 'Request failed.';
      throw new Error(message);
    }
    return payload;
  };

  const loadStatus = async () => {
    if (!statusUrl) return;
    try {
      const data = await requestJson(statusUrl);
      updateStatus(data);
    } catch (err) {
      setError(err.message || 'Unable to load Discord link status.');
    }
  };

  const handleGenerate = async () => {
    if (!codeUrl || !generateBtn) return;
    generateBtn.disabled = true;
    setError('');
    try {
      const data = await requestJson(codeUrl, { method: 'POST' });
      if (codeBlock && codeEl && expiresEl) {
        codeEl.textContent = data.code || '';
        if (data.expires_at) {
          const expiresAt = new Date(data.expires_at.replace(' ', 'T') + 'Z');
          const expiresText = Number.isNaN(expiresAt.getTime())
            ? 'Expires in 10 minutes.'
            : `Expires at ${expiresAt.toUTCString()}.`;
          expiresEl.textContent = expiresText;
        } else {
          expiresEl.textContent = 'Expires in 10 minutes.';
        }
        codeBlock.style.display = 'block';
      }
      await loadStatus();
    } catch (err) {
      setError(err.message || 'Unable to generate a link code.');
    } finally {
      generateBtn.disabled = false;
    }
  };

  const handleUnlink = async () => {
    if (!unlinkUrl || !unlinkBtn) return;
    unlinkBtn.disabled = true;
    setError('');
    try {
      await requestJson(unlinkUrl, { method: 'POST' });
      if (codeBlock) {
        codeBlock.style.display = 'none';
      }
      await loadStatus();
    } catch (err) {
      setError(err.message || 'Unable to unlink your Discord account.');
    } finally {
      unlinkBtn.disabled = false;
    }
  };

  if (generateBtn) {
    generateBtn.addEventListener('click', handleGenerate);
  }
  if (unlinkBtn) {
    unlinkBtn.addEventListener('click', handleUnlink);
  }

  loadStatus();
})();
