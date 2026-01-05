(() => {
  const root = document.querySelector('section.card[data-base-path]');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const progressBar = document.getElementById('cron-progress-bar');
  const progressLabel = document.getElementById('cron-progress-label');
  const progressCount = document.getElementById('cron-progress-count');
  const logEl = document.getElementById('cron-log');
  const resultEl = document.getElementById('cron-result');
  const taskMessage = document.getElementById('cron-task-message');
  const logPagination = document.getElementById('cron-log-pagination');
  const logPrev = document.getElementById('cron-log-prev');
  const logNext = document.getElementById('cron-log-next');
  const logPageLabel = document.getElementById('cron-log-page');
  let pollTimer = null;
  let currentJobId = null;
  let logEntries = [];
  let logPage = 1;
  const logPageSize = 10;

  const setProgress = (progress = {}) => {
    if (!progressBar || !progressLabel || !progressCount) return;
    const current = Number(progress.current ?? 0);
    const total = Number(progress.total ?? 0);
    const pct = total > 0 ? Math.round((current / total) * 100) : 0;
    progressBar.style.width = `${pct}%`;
    progressLabel.textContent = progress.label ?? 'Idle';
    progressCount.textContent = `${current}/${total}`;
  };

  const renderLogPage = () => {
    if (!logEl) return;
    if (!Array.isArray(logEntries) || logEntries.length === 0) {
      logEl.textContent = 'No queued syncs yet.';
      if (logPagination) {
        logPagination.style.display = 'none';
      }
      return;
    }
    const totalPages = Math.max(1, Math.ceil(logEntries.length / logPageSize));
    logPage = Math.min(Math.max(logPage, 1), totalPages);
    const start = (logPage - 1) * logPageSize;
    const currentSlice = logEntries.slice(start, start + logPageSize);
    logEl.textContent = currentSlice.map(entry => `[${entry.time}] ${entry.message}`).join('\n');
    if (logPagination && logPrev && logNext && logPageLabel) {
      logPagination.style.display = totalPages > 1 ? 'flex' : 'none';
      logPrev.classList.toggle('disabled', logPage <= 1);
      logNext.classList.toggle('disabled', logPage >= totalPages);
      logPageLabel.textContent = `Page ${logPage} of ${totalPages}`;
    }
  };

  const renderLog = (log = []) => {
    logEntries = Array.isArray(log) ? log : [];
    logPage = 1;
    renderLogPage();
  };

  const renderResult = (result) => {
    if (!resultEl) return;
    if (!result || Object.keys(result).length === 0) {
      resultEl.style.display = 'none';
      resultEl.textContent = '';
      return;
    }
    resultEl.style.display = 'block';
    resultEl.textContent = JSON.stringify(result, null, 2);
  };

  const stopPolling = () => {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  };

  const pollStatus = async () => {
    if (!currentJobId) return;
    try {
      const resp = await fetch(`${basePath}/api/cron/status/?job_id=${currentJobId}`);
      const data = await resp.json();
      if (!data.ok) {
        if (progressLabel) {
          progressLabel.textContent = data.error ?? 'Unable to load status.';
        }
        return;
      }
      const payload = data.payload ?? {};
      setProgress(payload.progress ?? {});
      renderLog(payload.log ?? []);
      renderResult(payload.result ?? {});
      if (['succeeded', 'failed', 'dead'].includes(data.status)) {
        stopPolling();
      }
    } catch (err) {
      if (progressLabel) {
        progressLabel.textContent = 'Status check failed.';
      }
    }
  };

  const startJob = async (scope, force, useSde) => {
    stopPolling();
    setProgress({ current: 0, total: 0, label: 'Queueing...' });
    renderLog([{ time: new Date().toISOString(), message: 'Queueing cron sync...' }]);
    renderResult(null);
    try {
      const resp = await fetch(`${basePath}/api/cron/run/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope, force: force ? 1 : 0, sde: useSde ? 1 : 0 })
      });
      const data = await resp.json();
      if (!data.ok) {
        if (progressLabel) {
          progressLabel.textContent = data.error ?? 'Unable to queue sync.';
        }
        return;
      }
      currentJobId = data.job_id;
      await pollStatus();
      pollTimer = setInterval(pollStatus, 3500);
    } catch (err) {
      if (progressLabel) {
        progressLabel.textContent = 'Queue request failed.';
      }
    }
  };

  const runTask = async (taskKey, force) => {
    if (!taskMessage) return;
    taskMessage.textContent = force ? 'Force queueing task...' : 'Queueing task...';
    try {
      const resp = await fetch(`${basePath}/api/cron/tasks/run/`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_key: taskKey, force: force ? 1 : 0 })
      });
      const data = await resp.json();
      if (!data.ok) {
        taskMessage.textContent = data.error ?? 'Unable to queue task.';
        return;
      }
      taskMessage.textContent = `Queued ${taskKey} as job #${data.job_id}.`;
    } catch (err) {
      taskMessage.textContent = 'Queue request failed.';
    }
  };

  document.querySelectorAll('[data-run-sync]').forEach((button) => {
    button.addEventListener('click', () => {
      const scope = button.getAttribute('data-run-sync') || 'all';
      startJob(scope, false, false);
    });
  });

  document.querySelectorAll('[data-run-task]').forEach((button) => {
    button.addEventListener('click', (event) => {
      const taskKey = button.getAttribute('data-run-task');
      if (taskKey) {
        const force = event.shiftKey;
        runTask(taskKey, force);
      }
    });
  });

  if (logPrev) {
    logPrev.addEventListener('click', () => {
      if (logPage > 1) {
        logPage -= 1;
        renderLogPage();
      }
    });
  }

  if (logNext) {
    logNext.addEventListener('click', () => {
      const totalPages = Math.max(1, Math.ceil(logEntries.length / logPageSize));
      if (logPage < totalPages) {
        logPage += 1;
        renderLogPage();
      }
    });
  }
})();
