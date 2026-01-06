<h3>Scheduled tasks</h3>
<table class="table cron-table">
  <thead>
    <tr>
      <th>Job Key</th>
      <th>Name</th>
      <th>Interval</th>
      <th>Last Status</th>
      <th>Last Run</th>
      <th>Message</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($taskStates as $taskKey => $state): ?>
      <?php
        $task = $state['task'];
        $enabled = $state['enabled'];
        $lastJob = $state['last_job'];
        $lastStatus = $state['last_status'];
        $lastRun = $state['last_run'];
        $statusClass = $state['status_class'];
        $interval = (int)$task['interval'];
      ?>
      <tr>
        <td><code><?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?></code></td>
        <td>
          <?= htmlspecialchars($task['name'], ENT_QUOTES, 'UTF-8') ?>
          <div class="cron-note"><?= htmlspecialchars($task['description'], ENT_QUOTES, 'UTF-8') ?></div>
        </td>
        <td>
          <form method="post" class="cron-interval-form">
            <input type="hidden" name="action" value="update_interval" />
            <input type="hidden" name="task_key" value="<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>" />
            <label class="visually-hidden" for="interval-<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>">Interval seconds</label>
            <input
              id="interval-<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>"
              type="number"
              name="interval_seconds"
              min="60"
              step="60"
              value="<?= (int)$interval ?>"
            />
            <button class="btn ghost" type="submit">Update</button>
          </form>
          <div class="cron-note">
            Minimum 60s.
            <?php if ($task['scope'] === 'global'): ?>
              Global override stored in settings (falls back to environment).
            <?php endif; ?>
          </div>
        </td>
        <td><span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lastStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
        <td><?= htmlspecialchars($lastRun ? $formatTimestamp((string)$lastRun) : '—', ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($extractJobMessage($lastJob), ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <div class="actions">
            <?php if ($task['runner'] === 'sync'): ?>
              <button class="btn" type="button" data-run-sync="<?= htmlspecialchars((string)($task['sync_scope'] ?? 'all'), ENT_QUOTES, 'UTF-8') ?>">Run Now</button>
            <?php else: ?>
              <button class="btn" type="button" data-run-task="<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>">Run Now</button>
            <?php endif; ?>
            <form method="post">
              <input type="hidden" name="action" value="toggle_task" />
              <input type="hidden" name="task_key" value="<?= htmlspecialchars($taskKey, ENT_QUOTES, 'UTF-8') ?>" />
              <button class="btn ghost" type="submit"><?= $enabled ? 'Disable' : 'Enable' ?></button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<div id="cron-task-message" class="cron-note"></div>
