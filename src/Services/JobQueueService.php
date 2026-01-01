<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class JobQueueService
{
  public const CRON_SYNC_JOB = 'cron.sync';

  public function __construct(private Db $db)
  {
  }

  public function enqueueCronSync(
    int $corpId,
    int $characterId,
    string $scope,
    bool $force,
    array $auditContext = []
  ): int {
    $payload = [
      'scope' => $scope,
      'force' => $force,
      'character_id' => $characterId,
      'progress' => [
        'current' => 0,
        'total' => 0,
        'label' => 'Queued',
        'stage' => 'queued',
      ],
      'log' => [
        [
          'time' => gmdate('c'),
          'message' => 'Cron sync queued.',
        ],
      ],
    ];

    $jobId = $this->db->insert('job_queue', [
      'corp_id' => $corpId,
      'job_type' => self::CRON_SYNC_JOB,
      'priority' => 100,
      'status' => 'queued',
      'run_at' => gmdate('Y-m-d H:i:s'),
      'payload_json' => Db::jsonEncode($payload),
    ]);

    $this->db->audit(
      $corpId,
      $auditContext['actor_user_id'] ?? null,
      $auditContext['actor_character_id'] ?? null,
      'cron.sync.queued',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      $auditContext['ip_address'] ?? null,
      $auditContext['user_agent'] ?? null
    );

    return $jobId;
  }

  public function claimNextCronSync(string $workerId): ?array
  {
    return $this->db->tx(function (Db $db) use ($workerId) {
      $row = $db->one(
        "SELECT *
           FROM job_queue
          WHERE job_type = :job_type
            AND status = 'queued'
            AND run_at <= UTC_TIMESTAMP()
            AND attempt < max_attempts
          ORDER BY priority ASC, run_at ASC
          LIMIT 1
          FOR UPDATE",
        ['job_type' => self::CRON_SYNC_JOB]
      );

      if (!$row) {
        return null;
      }

      $jobId = (int)$row['job_id'];
      $db->execute(
        "UPDATE job_queue
            SET status = 'running',
                started_at = UTC_TIMESTAMP(),
                locked_by = :worker,
                locked_at = UTC_TIMESTAMP(),
                attempt = attempt + 1
          WHERE job_id = :job_id",
        [
          'worker' => $workerId,
          'job_id' => $jobId,
        ]
      );

      return $db->one(
        "SELECT *
           FROM job_queue
          WHERE job_id = :job_id
          LIMIT 1",
        ['job_id' => $jobId]
      );
    });
  }

  public function getJobForCorp(int $jobId, int $corpId): ?array
  {
    return $this->db->one(
      "SELECT *
         FROM job_queue
        WHERE job_id = :job_id AND corp_id = :corp_id
        LIMIT 1",
      [
        'job_id' => $jobId,
        'corp_id' => $corpId,
      ]
    );
  }

  public function updateProgress(int $jobId, array $progress, ?string $message = null): void
  {
    $this->updatePayload($jobId, function (array $payload) use ($progress, $message) {
      $payload['progress'] = array_merge($payload['progress'] ?? [], $progress);
      if ($message) {
        $payload['log'][] = [
          'time' => gmdate('c'),
          'message' => $message,
        ];
      }
      return $payload;
    });
  }

  public function markStarted(int $jobId): void
  {
    $job = $this->fetchJob($jobId);
    if (!$job) return;

    $payload = $this->decodePayload($job);
    $payload['started_at'] = gmdate('c');

    $this->db->audit(
      (int)$job['corp_id'],
      null,
      null,
      'cron.sync.started',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      null,
      null
    );
  }

  public function markSucceeded(int $jobId, array $result): void
  {
    $job = $this->fetchJob($jobId);
    if (!$job) return;

    $payload = $this->decodePayload($job);
    $payload['result'] = $result;
    $payload['finished_at'] = gmdate('c');
    $payload['progress'] = array_merge($payload['progress'] ?? [], [
      'label' => 'Completed',
      'stage' => 'completed',
      'current' => $payload['progress']['total'] ?? ($payload['progress']['current'] ?? 0),
    ]);
    $payload['log'][] = [
      'time' => gmdate('c'),
      'message' => 'Cron sync completed.',
    ];

    $this->db->execute(
      "UPDATE job_queue
          SET status = 'succeeded',
              finished_at = UTC_TIMESTAMP(),
              payload_json = :payload_json
        WHERE job_id = :job_id",
      [
        'payload_json' => Db::jsonEncode($payload),
        'job_id' => $jobId,
      ]
    );

    $this->db->audit(
      (int)$job['corp_id'],
      null,
      null,
      'cron.sync.completed',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      null,
      null
    );
  }

  public function markFailed(int $jobId, string $error, array $result = []): void
  {
    $job = $this->fetchJob($jobId);
    if (!$job) return;

    $status = ((int)$job['attempt'] >= (int)$job['max_attempts']) ? 'dead' : 'failed';
    $payload = $this->decodePayload($job);
    $payload['error'] = $error;
    if ($result !== []) {
      $payload['result'] = $result;
    }
    $payload['finished_at'] = gmdate('c');
    $payload['progress'] = array_merge($payload['progress'] ?? [], [
      'label' => 'Failed',
      'stage' => 'failed',
    ]);
    $payload['log'][] = [
      'time' => gmdate('c'),
      'message' => "Cron sync failed: {$error}",
    ];

    $this->db->execute(
      "UPDATE job_queue
          SET status = :status,
              finished_at = UTC_TIMESTAMP(),
              last_error = :last_error,
              payload_json = :payload_json
        WHERE job_id = :job_id",
      [
        'status' => $status,
        'last_error' => $error,
        'payload_json' => Db::jsonEncode($payload),
        'job_id' => $jobId,
      ]
    );

    $this->db->audit(
      (int)$job['corp_id'],
      null,
      null,
      'cron.sync.failed',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      null,
      null
    );
  }

  private function updatePayload(int $jobId, callable $mutator): void
  {
    $job = $this->fetchJob($jobId);
    if (!$job) return;

    $payload = $this->decodePayload($job);
    $payload = $mutator($payload);

    $this->db->execute(
      "UPDATE job_queue
          SET payload_json = :payload_json
        WHERE job_id = :job_id",
      [
        'payload_json' => Db::jsonEncode($payload),
        'job_id' => $jobId,
      ]
    );
  }

  private function fetchJob(int $jobId): ?array
  {
    return $this->db->one(
      "SELECT job_id, corp_id, attempt, max_attempts, payload_json
         FROM job_queue
        WHERE job_id = :job_id
        LIMIT 1",
      ['job_id' => $jobId]
    );
  }

  private function decodePayload(array $job): array
  {
    if (empty($job['payload_json'])) {
      return [];
    }
    $decoded = Db::jsonDecode((string)$job['payload_json'], []);
    return is_array($decoded) ? $decoded : [];
  }
}
