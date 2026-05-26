<?php
/**
 * 任务队列服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class JobQueueService {
    private PDO $db;

    public function __construct(PDO $database) {
        $this->db = $database;
    }

    public function initializeTaskSchedule(int $taskId, int $delaySeconds = 60): void {
        $stmt = $this->db->prepare("
            UPDATE tasks
            SET next_run_at = COALESCE(next_run_at, " . db_now_plus_seconds_sql($delaySeconds) . "),
                schedule_enabled = COALESCE(schedule_enabled, 1),
                max_retry_count = COALESCE(max_retry_count, 3),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$taskId]);
    }

    public function hasPendingOrRunningJob(int $taskId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM job_queue
            WHERE task_id = ?
              AND status IN ('pending', 'running')
        ");
        $stmt->execute([$taskId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function enqueueTaskJob(int $taskId, string $jobType = 'generate_article', array $payload = [], ?string $availableAt = null): ?int {
        if ($this->hasPendingOrRunningJob($taskId)) {
            return null;
        }

        $task = $this->db->prepare("SELECT max_retry_count FROM tasks WHERE id = ?");
        $task->execute([$taskId]);
        $taskConfig = $task->fetch(PDO::FETCH_ASSOC);
        if (!$taskConfig) {
            return null;
        }

        $stmt = $this->db->prepare("
            INSERT INTO job_queue (
                task_id, job_type, status, payload, attempt_count, max_attempts,
                available_at, created_at, updated_at
            ) VALUES (?, ?, 'pending', ?, 0, ?, COALESCE(?, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $taskId,
            $jobType,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            max(1, (int) ($taskConfig['max_retry_count'] ?? 3)),
            $availableAt
        ]);

        return db_last_insert_id($this->db, 'job_queue');
    }

    public function claimNextJob(string $workerId): ?array {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->query("
                SELECT jq.*, t.publish_interval, t.status AS task_status
                FROM job_queue jq
                INNER JOIN tasks t ON t.id = jq.task_id
                WHERE jq.status = 'pending'
                  AND jq.available_at <= CURRENT_TIMESTAMP
                  AND t.status = 'active'
                  AND COALESCE(t.schedule_enabled, 1) = 1
                ORDER BY jq.available_at ASC, jq.id ASC
                LIMIT 1
            ");
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->db->commit();
                return null;
            }

            $claim = $this->db->prepare("
                UPDATE job_queue
                SET status = 'running',
                    claimed_at = CURRENT_TIMESTAMP,
                    worker_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND status = 'pending'
            ");
            $claim->execute([$workerId, $job['id']]);

            if ($claim->rowCount() !== 1) {
                $this->db->rollBack();
                return null;
            }

            $this->db->commit();
            $job['status'] = 'running';
            $job['worker_id'] = $workerId;
            return $job;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function completeJob(int $jobId, int $taskId, ?int $articleId, int $durationMs, array $meta = []): void {
        $stmt = $this->db->prepare("
            UPDATE job_queue
            SET status = 'completed',
                finished_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP,
                error_message = ''
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);

        $run = $this->db->prepare("
            INSERT INTO task_runs (
                task_id, job_id, status, article_id, duration_ms, meta, started_at, finished_at, created_at
            ) VALUES (?, ?, 'completed', ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $run->execute([
            $taskId,
            $jobId,
            $articleId,
            $durationMs,
            json_encode($meta, JSON_UNESCAPED_UNICODE)
        ]);

        $task = $this->db->prepare("
            UPDATE tasks
            SET last_run_at = CURRENT_TIMESTAMP,
                last_success_at = CURRENT_TIMESTAMP,
                last_error_message = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $task->execute([$taskId]);
    }

    public function failJob(int $jobId, int $taskId, string $errorMessage, int $durationMs, int $retryDelaySeconds = 60): void {
        $stmt = $this->db->prepare("
            SELECT attempt_count, max_attempts
            FROM job_queue
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return;
        }

        $attemptCount = (int) $job['attempt_count'] + 1;
        $maxAttempts = max(1, (int) $job['max_attempts']);
        $shouldRetry = $attemptCount < $maxAttempts;

        $update = $this->db->prepare("
            UPDATE job_queue
            SET status = ?,
                attempt_count = ?,
                available_at = CASE WHEN ? THEN " . db_now_plus_seconds_sql($retryDelaySeconds) . " ELSE available_at END,
                finished_at = CASE WHEN ? THEN NULL ELSE CURRENT_TIMESTAMP END,
                error_message = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([
            $shouldRetry ? 'pending' : 'failed',
            $attemptCount,
            $shouldRetry ? 1 : 0,
            $shouldRetry ? 1 : 0,
            $errorMessage,
            $jobId
        ]);

        $run = $this->db->prepare("
            INSERT INTO task_runs (
                task_id, job_id, status, error_message, duration_ms, started_at, finished_at, created_at
            ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $run->execute([
            $taskId,
            $jobId,
            $shouldRetry ? 'retrying' : 'failed',
            $errorMessage,
            $durationMs
        ]);

        $task = $this->db->prepare("
            UPDATE tasks
            SET last_run_at = CURRENT_TIMESTAMP,
                last_error_at = CURRENT_TIMESTAMP,
                last_error_message = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $task->execute([$errorMessage, $taskId]);
    }

    public function cancelJob(int $jobId, int $taskId, string $reason = '管理员手动停止'): void {
        $stmt = $this->db->prepare("
            UPDATE job_queue
            SET status = 'cancelled',
                finished_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP,
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$reason, $jobId]);

        $run = $this->db->prepare("
            INSERT INTO task_runs (
                task_id, job_id, status, error_message, duration_ms, started_at, finished_at, created_at
            ) VALUES (?, ?, 'cancelled', ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $run->execute([$taskId, $jobId, $reason]);

        $task = $this->db->prepare("
            UPDATE tasks
            SET last_run_at = CURRENT_TIMESTAMP,
                last_error_at = CURRENT_TIMESTAMP,
                last_error_message = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $task->execute([$reason, $taskId]);
    }

    public function recoverStaleJobs(int $timeoutSeconds = 600): int {
        $stmt = $this->db->prepare("
            UPDATE job_queue
            SET status = 'pending',
                claimed_at = NULL,
                worker_id = '',
                updated_at = CURRENT_TIMESTAMP,
                available_at = CURRENT_TIMESTAMP
            WHERE status = 'running'
              AND claimed_at IS NOT NULL
              AND claimed_at < " . db_now_minus_seconds_sql(max(60, $timeoutSeconds)) . "
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
