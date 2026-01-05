(() => {
  const root = document.querySelector('.js-ops-config');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const corpId = parseInt(root.dataset.corpId || '0', 10);

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

  const dispatchToggle = document.getElementById('toggle-dispatch-sections');
  dispatchToggle?.addEventListener('click', async () => {
    const enabled = dispatchToggle.dataset.enabled === '1';
    dispatchToggle.disabled = true;
    const data = await sendJson(`${basePath}/api/admin/operations-sections/`, {
      corp_id: corpId,
      show_dispatch: !enabled,
    });
    if (!data.ok) {
      alert(data.error || 'Update failed.');
      dispatchToggle.disabled = false;
      return;
    }
    window.location.reload();
  });

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

  document.querySelectorAll('.js-assign-request').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      if (!requestId) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/assign/`, { request_id: requestId });
      if (!data.ok) {
        alert(data.error || 'Assign failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });

  const assignModal = document.getElementById('assign-modal');
  const assignSearch = document.getElementById('assign-search');
  const assignList = document.getElementById('assign-list');
  const assignTitle = document.getElementById('assign-title');
  const assignCancel = document.getElementById('assign-cancel');
  let activeRequestId = 0;

  const closeAssignModal = () => {
    if (assignModal) {
      assignModal.setAttribute('hidden', 'hidden');
    }
    if (assignSearch) {
      assignSearch.value = '';
    }
    if (assignList) {
      assignList.querySelectorAll('.js-assign-select').forEach((btn) => {
        btn.removeAttribute('hidden');
      });
    }
    activeRequestId = 0;
  };

  const openAssignModal = (requestId) => {
    if (!assignModal || !assignList) return;
    activeRequestId = requestId;
    if (assignTitle) {
      assignTitle.textContent = `Assign request #${requestId}`;
    }
    assignModal.removeAttribute('hidden');
    assignSearch?.focus();
  };

  document.querySelectorAll('.js-assign-other').forEach((btn) => {
    btn.addEventListener('click', () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      if (!requestId) return;
      openAssignModal(requestId);
    });
  });

  assignCancel?.addEventListener('click', closeAssignModal);
  assignModal?.addEventListener('click', (event) => {
    if (event.target === assignModal) {
      closeAssignModal();
    }
  });

  assignSearch?.addEventListener('input', () => {
    const term = assignSearch.value.trim().toLowerCase();
    assignList?.querySelectorAll('.js-assign-select').forEach((btn) => {
      const label = (btn.dataset.label || '').toLowerCase();
      btn.toggleAttribute('hidden', term !== '' && !label.includes(term));
    });
  });

  assignList?.querySelectorAll('.js-assign-select').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const haulerId = parseInt(btn.dataset.haulerId || '0', 10);
      if (!activeRequestId || !haulerId) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/assign/`, {
        request_id: activeRequestId,
        hauler_user_id: haulerId,
      });
      if (!data.ok) {
        alert(data.error || 'Assign failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });

  document.querySelectorAll('.js-update-status').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const requestId = parseInt(btn.dataset.requestId || '0', 10);
      const select = document.querySelector(`.js-status-select[data-request-id="${requestId}"]`);
      const status = select?.value;
      if (!requestId || !status) return;
      btn.disabled = true;
      const data = await sendJson(`${basePath}/api/requests/update-status/`, { request_id: requestId, status });
      if (!data.ok) {
        alert(data.error || 'Status update failed.');
        btn.disabled = false;
        return;
      }
      window.location.reload();
    });
  });

  const contractModal = document.getElementById('contract-modal');
  const contractClose = document.getElementById('contract-close');
  const contractTitle = document.getElementById('contract-title');
  const contractSubtitle = document.getElementById('contract-subtitle');
  const contractChecks = document.getElementById('contract-checks');
  const contractMismatch = document.getElementById('contract-mismatch');
  const contractMismatchList = document.getElementById('contract-mismatch-list');

  const closeContractModal = () => {
    contractModal?.setAttribute('hidden', 'hidden');
  };

  const renderContractChecks = (details) => {
    if (!contractChecks) return;
    contractChecks.innerHTML = '';
    const checks = Array.isArray(details?.checks) ? details.checks : [];
    if (!checks.length) {
      contractChecks.innerHTML = '<div class="muted">No validation details available yet.</div>';
      return;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'contract-checklist';
    checks.forEach((check) => {
      const row = document.createElement('div');
      row.className = `contract-check ${check.ok ? 'is-ok' : 'is-fail'}`;
      row.innerHTML = `
        <span class="check-dot"></span>
        <span>${check.label || check.key}</span>
      `;
      wrapper.appendChild(row);
    });
    contractChecks.appendChild(wrapper);
  };

  const renderContractMismatch = (details) => {
    if (!contractMismatch || !contractMismatchList) return;
    const mismatches = details?.mismatches || {};
    const entries = Object.entries(mismatches);
    if (!entries.length) {
      contractMismatch.setAttribute('hidden', 'hidden');
      contractMismatchList.innerHTML = '';
      return;
    }
    contractMismatch.removeAttribute('hidden');
    contractMismatchList.innerHTML = '';
    entries.forEach(([key, value]) => {
      const item = document.createElement('li');
      const detail = typeof value === 'string' ? value : JSON.stringify(value);
      item.textContent = `${key}: ${detail}`;
      contractMismatchList.appendChild(item);
    });
  };

  document.querySelectorAll('.js-contract-details').forEach((btn) => {
    btn.addEventListener('click', () => {
      const requestId = btn.dataset.requestId;
      const rawDetails = btn.dataset.contractDetails;
      if (!rawDetails || !contractModal) return;
      const details = JSON.parse(rawDetails);
      const stateLabel = details?.state === 'linked'
        ? 'Matched'
        : (details?.state === 'mismatch' ? 'Mismatch' : 'Pending');
      if (contractTitle) {
        contractTitle.textContent = `Contract checks (${stateLabel})`;
      }
      if (contractSubtitle) {
        contractSubtitle.textContent = `Request #${requestId || '—'}`;
      }
      renderContractChecks(details);
      renderContractMismatch(details);
      contractModal.removeAttribute('hidden');
    });
  });

  contractClose?.addEventListener('click', closeContractModal);
  contractModal?.addEventListener('click', (event) => {
    if (event.target === contractModal) {
      closeContractModal();
    }
  });
})();
