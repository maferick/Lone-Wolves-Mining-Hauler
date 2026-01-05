<?php
declare(strict_types=1);

namespace App\Services;

use App\Db\Db;

final class JobQueueService
{
  public const CRON_SYNC_JOB = 'cron.sync';
  public const CRON_TOKEN_REFRESH_JOB = 'cron.token_refresh';
  public const CRON_STRUCTURES_JOB = 'cron.structures';
  public const CRON_PUBLIC_STRUCTURES_JOB = 'cron.public_structures';
  public const CRON_CONTRACTS_JOB = 'cron.contracts';
  public const CRON_ALLIANCES_JOB = 'cron.alliances';
  public const CRON_NPC_STRUCTURES_JOB = 'cron.npc_structures';
  public const CONTRACT_MATCH_JOB = 'cron.contract_match';
  public const WEBHOOK_DELIVERY_JOB = 'cron.webhook_delivery';
  public const WEBHOOK_REQUEUE_JOB = 'cron.webhook_requeue';

  public function __construct(private Db $db)
  {
  }

  public function enqueueCronSync(
    int $corpId,
    int $characterId,
    string $scope,
    bool $force,
    bool $useSde,
    bool $syncPublicStructures = false,
    array $auditContext = []
  ): int {
    $payload = [
      'scope' => $scope,
      'force' => $force,
      'sde' => $useSde,
      'sync_public_structures' => $syncPublicStructures,
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

  public function enqueueTokenRefresh(int $corpId, int $characterId, array $auditContext = []): int
  {
    return $this->enqueueEsiJob(
      $corpId,
      $characterId,
      self::CRON_TOKEN_REFRESH_JOB,
      'Token refresh queued.',
      [
        'steps' => ['token_refresh'],
      ],
      'cron.token_refresh.queued',
      $auditContext
    );
  }

  public function enqueueStructuresSync(int $corpId, int $characterId, array $auditContext = []): int
  {
    return $this->enqueueEsiJob(
      $corpId,
      $characterId,
      self::CRON_STRUCTURES_JOB,
      'Structures sync queued.',
      [
        'steps' => ['structures'],
      ],
      'cron.structures.queued',
      $auditContext
    );
  }

  public function enqueuePublicStructuresSync(int $corpId, int $characterId, array $auditContext = []): int
  {
    return $this->enqueueEsiJob(
      $corpId,
      $characterId,
      self::CRON_PUBLIC_STRUCTURES_JOB,
      'Public structures sync queued.',
      [
        'steps' => ['public_structures'],
        'sync_public_structures' => true,
      ],
      'cron.public_structures.queued',
      $auditContext
    );
  }

  public function enqueueContractsSync(int $corpId, int $characterId, array $auditContext = []): int
  {
    return $this->enqueueEsiJob(
      $corpId,
      $characterId,
      self::CRON_CONTRACTS_JOB,
      'Contracts sync queued.',
      [
        'steps' => ['contracts'],
      ],
      'cron.contracts.queued',
      $auditContext
    );
  }

  public function enqueueAllianceSync(array $auditContext = []): int
  {
    $payload = [
      'progress' => [
        'current' => 0,
        'total' => 0,
        'label' => 'Queued',
        'stage' => 'queued',
      ],
      'log' => [
        [
          'time' => gmdate('c'),
          'message' => 'Alliance sync queued.',
        ],
      ],
    ];

    $jobId = $this->db->insert('job_queue', [
      'corp_id' => null,
      'job_type' => self::CRON_ALLIANCES_JOB,
      'priority' => 110,
      'status' => 'queued',
      'run_at' => gmdate('Y-m-d H:i:s'),
      'payload_json' => Db::jsonEncode($payload),
    ]);

    $this->db->audit(
      null,
      $auditContext['actor_user_id'] ?? null,
      $auditContext['actor_character_id'] ?? null,
      'cron.alliances.queued',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      $auditContext['ip_address'] ?? null,
      $auditContext['user_agent'] ?? null
    );

    return $jobId;
  }

  public function enqueueNpcStructuresSync(array $auditContext = []): int
  {
    $payload = [
      'progress' => [
        'current' => 0,
        'total' => 0,
        'label' => 'Queued',
        'stage' => 'queued',
      ],
      'log' => [
        [
          'time' => gmdate('c'),
          'message' => 'NPC structures sync queued.',
        ],
      ],
    ];

    $jobId = $this->db->insert('job_queue', [
      'corp_id' => null,
      'job_type' => self::CRON_NPC_STRUCTURES_JOB,
      'priority' => 115,
      'status' => 'queued',
      'run_at' => gmdate('Y-m-d H:i:s'),
      'payload_json' => Db::jsonEncode($payload),
    ]);

    $this->db->audit(
      null,
      $auditContext['actor_user_id'] ?? null,
      $auditContext['actor_character_id'] ?? null,
      'cron.npc_structures.queued',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      $auditContext['ip_address'] ?? null,
      $auditContext['user_agent'] ?? null
    );

    return $jobId;
  }

  public function enqueueContractMatch(int $corpId, array $auditContext = []): int
  {
    $payload = [
      'progress' => [
        'current' => 0,
        'total' => 0,
        'label' => 'Queued',
        'stage' => 'queued',
      ],
      'log' => [
        [
          'time' => gmdate('c'),
          'message' => 'Contract match queued.',
        ],
      ],
    ];

    $jobId = $this->db->insert('job_queue', [
      'corp_id' => $corpId,
      'job_type' => self::CONTRACT_MATCH_JOB,
      'priority' => 120,
      'status' => 'queued',
      'run_at' => gmdate('Y-m-d H:i:s'),
      'payload_json' => Db::jsonEncode($payload),
    ]);

    $this->db->audit(
      $corpId,
      $auditContext['actor_user_id'] ?? null,
      $auditContext['actor_character_id'] ?? null,
      'cron.contract_match.queued',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      $auditContext['ip_address'] ?? null,
      $auditContext['user_agent'] ?? null
    );

    return $jobId;
  }

  public function enqueueWebhookDelivery(int $limit, array $auditContext = []): int
  {
    $payload = [
      'limit' => $limit,
      'progress' => [
        'current' => 0,
        'total' => 0,
        'label' => 'Queued',
        'stage' => 'queued',
      ],
      'log' => [
        [
          'time' => gmdate('c'),
          'message' => 'Webhook delivery queued.',
        ],
      ],
    ];

    $jobId = $this->db->insert('job_queue', [
      'corp_id' => null,
      'job_type' => self::WEBHOOK_DELIVERY_JOB,
      'priority' => 90,
      'status' => 'queued',
      'run_at' => gmdate('Y-m-d H:i:s'),
      'payload_json' => Db::jsonEncode($payload),
    ]);

    $this->db->audit(
      null,
      $auditContext['actor_user_id'] ?? null,
      $auditContext['actor_character_id'] ?? null,
      'cron.webhook_delivery.queued',
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      $auditContext['ip_address'] ?? null,
      $auditContext['user_agent'] ?? null
    );

    return $jobId;
  }

  public function enqueueWebhookRequeue(int $limit, int $minutes = 60, array $auditContext = []): int
  {
    $payload = [
      'limit' => $limit,
      'minutes' => $minutes,
      'progress' => [
        'current' => 0,
        'total' => 0,
        'label' => 'Queued',
        'stage' => 'queued',
      ],
      'log' => [
        [
          'time' => gmdate('c'),
          'message' => 'Webhook requeue queued.',
        ],
      ],
    ];

    $jobId = $this->db->insert('job_queue', [
      'corp_id' => null,
      'job_type' => self::WEBHOOK_REQUEUE_JOB,
      'priority' => 95,
      'status' => 'queued',
      'run_at' => gmdate('Y-m-d H:i:s'),
      'payload_json' => Db::jsonEncode($payload),
    ]);

    $this->db->audit(
      null,
      $auditContext['actor_user_id'] ?? null,
      $auditContext['actor_character_id'] ?? null,
      'cron.webhook_requeue.queued',
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
    return $this->claimNextJob([self::CRON_SYNC_JOB], $workerId);
  }

  public function claimNextJob(array $jobTypes, string $workerId): ?array
  {
    return $this->db->tx(function (Db $db) use ($workerId, $jobTypes) {
      if ($jobTypes === []) {
        return null;
      }
      $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
      $row = $db->one(
        "SELECT *
           FROM job_queue
          WHERE job_type IN ({$placeholders})
            AND status = 'queued'
            AND run_at <= UTC_TIMESTAMP()
            AND attempt < max_attempts
          ORDER BY priority ASC, run_at ASC
          LIMIT 1
          FOR UPDATE",
        $jobTypes
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

  public function hasPendingJob(?int $corpId, string $jobType): bool
  {
    return $this->getPendingJob($corpId, $jobType) !== null;
  }

  public function getPendingJob(?int $corpId, string $jobType): ?array
  {
    $params = ['job_type' => $jobType];
    $corpClause = 'corp_id IS NULL';
    if ($corpId !== null) {
      $corpClause = 'corp_id = :corp_id';
      $params['corp_id'] = $corpId;
    }

    return $this->db->one(
      "SELECT job_id, corp_id, status, run_at, started_at, finished_at, locked_at, updated_at
         FROM job_queue
        WHERE job_type = :job_type
          AND {$corpClause}
          AND status IN ('queued','running')
        ORDER BY COALESCE(started_at, run_at, created_at) DESC, job_id DESC
        LIMIT 1",
      $params
    );
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

  public function markStarted(
    int $jobId,
    string $auditAction = 'cron.sync.started',
    ?string $logMessage = null
  ): void
  {
    $job = $this->fetchJob($jobId);
    if (!$job) return;

    $payload = $this->decodePayload($job);
    $payload['started_at'] = gmdate('c');
    if ($logMessage) {
      $payload['log'][] = [
        'time' => gmdate('c'),
        'message' => $logMessage,
      ];
    }

    $corpId = $job['corp_id'] !== null ? (int)$job['corp_id'] : null;
    if ($corpId !== null && $corpId <= 0) {
      $corpId = null;
    }

    $this->db->audit(
      $corpId,
      null,
      null,
      $auditAction,
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      null,
      null
    );
  }

  public function markSucceeded(
    int $jobId,
    array $result,
    string $auditAction = 'cron.sync.completed',
    string $logMessage = 'Cron sync completed.'
  ): void
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
      'message' => $logMessage,
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

    $corpId = $job['corp_id'] !== null ? (int)$job['corp_id'] : null;
    if ($corpId !== null && $corpId <= 0) {
      $corpId = null;
    }

    $this->db->audit(
      $corpId,
      null,
      null,
      $auditAction,
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      null,
      null
    );
  }

  public function markFailed(
    int $jobId,
    string $error,
    array $result = [],
    string $auditAction = 'cron.sync.failed',
    ?string $logMessage = null
  ): void
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
      'message' => $logMessage ?? "Cron sync failed: {$error}",
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

    $corpId = $job['corp_id'] !== null ? (int)$job['corp_id'] : null;
    if ($corpId !== null && $corpId <= 0) {
      $corpId = null;
    }

    $this->db->audit(
      $corpId,
      null,
      null,
      $auditAction,
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

  private function enqueueEsiJob(
    int $corpId,
    int $characterId,
    string $jobType,
    string $queueMessage,
    array $payloadOverrides,
    string $auditAction,
    array $auditContext
  ): int {
    $payload = array_merge([
      'scope' => 'all',
      'force' => false,
      'sde' => false,
      'sync_public_structures' => false,
      'steps' => null,
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
          'message' => $queueMessage,
        ],
      ],
    ], $payloadOverrides);

    $jobId = $this->db->insert('job_queue', [
      'corp_id' => $corpId,
      'job_type' => $jobType,
      'priority' => 100,
      'status' => 'queued',
      'run_at' => gmdate('Y-m-d H:i:s'),
      'payload_json' => Db::jsonEncode($payload),
    ]);

    $this->db->audit(
      $corpId,
      $auditContext['actor_user_id'] ?? null,
      $auditContext['actor_character_id'] ?? null,
      $auditAction,
      'job_queue',
      (string)$jobId,
      null,
      $payload,
      $auditContext['ip_address'] ?? null,
      $auditContext['user_agent'] ?? null
    );

    return $jobId;
  }
}
