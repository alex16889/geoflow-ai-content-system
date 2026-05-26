<?php
/**
 * 任务健康检查API
 * 提供任务状态同步、健康检查和自动恢复功能
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_queue_service.php';

header('Content-Type: application/json; charset=utf-8');

function task_health_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    task_health_json_response(['success' => false, 'message' => '未登录或登录已过期'], 401);
}

try {
    if (!get_current_admin(true)) {
        task_health_json_response(['success' => false, 'message' => '未登录或登录已过期'], 401);
    }

    session_write_close();

    $queueService = new JobQueueService($db);
    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'status');
    $mutatingActions = ['health_check', 'cleanup', 'recover'];

    if (in_array($action, $mutatingActions, true)) {
        if ($requestMethod !== 'POST') {
            task_health_json_response(['success' => false, 'message' => '无效的请求方法'], 405);
        }

        $csrfToken = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!verify_csrf_token($csrfToken)) {
            task_health_json_response(['success' => false, 'message' => __('message.csrf_invalid')], 422);
        }
    }

    if (in_array($action, $mutatingActions, true)) {
        log_admin_activity('task_health_check:' . $action, [
            'request_method' => $requestMethod,
            'page' => 'task_health_check.php',
            'details' => [
                'action' => $action
            ]
        ]);
    }

    switch ($action) {
        case 'status':
            // 获取所有任务的当前状态
            $stmt = $db->query("
                SELECT
                    id, name, status,
                    created_count, published_count,
                    draft_limit, publish_interval,
                    next_run_at,
                    last_run_at,
                    last_error_at,
                    schedule_enabled,
                    COALESCE((
                        SELECT jq.error_message
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                          AND jq.status IN ('failed', 'cancelled')
                          AND COALESCE(jq.error_message, '') <> ''
                        ORDER BY jq.updated_at DESC, jq.id DESC
                        LIMIT 1
                    ), NULLIF(last_error_message, ''), '') AS last_error_message,
                    COALESCE((
                        SELECT jq.status
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                          AND jq.status IN ('running', 'pending')
                        ORDER BY
                            CASE jq.status
                                WHEN 'running' THEN 1
                                WHEN 'pending' THEN 2
                                ELSE 3
                            END,
                            jq.updated_at DESC,
                            jq.id DESC
                        LIMIT 1
                    ), (
                        SELECT jq.status
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                          AND jq.status IN ('failed', 'completed', 'cancelled')
                        ORDER BY jq.updated_at DESC, jq.id DESC
                        LIMIT 1
                    ), 'idle') AS queue_status,
                    (
                        SELECT COUNT(*)
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                          AND jq.status = 'pending'
                    ) AS pending_jobs,
                    (
                        SELECT COUNT(*)
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                          AND jq.status = 'running'
                    ) AS running_jobs,
                    (
                        SELECT COUNT(*)
                        FROM task_runs tr
                        WHERE tr.task_id = tasks.id
                          AND tr.status = 'completed'
                    ) AS run_success_count,
                    (
                        SELECT COUNT(*)
                        FROM task_runs tr
                        WHERE tr.task_id = tasks.id
                          AND tr.status IN ('failed', 'retrying')
                    ) AS run_error_count
                    ,
                    COALESCE((
                        SELECT jq.attempt_count
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                        ORDER BY jq.updated_at DESC, jq.id DESC
                        LIMIT 1
                    ), 0) AS latest_attempt_count,
                    COALESCE((
                        SELECT jq.max_attempts
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                        ORDER BY jq.updated_at DESC, jq.id DESC
                        LIMIT 1
                    ), 0) AS latest_max_attempts,
                    COALESCE((
                        SELECT jq.status
                        FROM job_queue jq
                        WHERE jq.task_id = tasks.id
                        ORDER BY jq.updated_at DESC, jq.id DESC
                        LIMIT 1
                    ), 'idle') AS latest_job_status
                FROM tasks
                ORDER BY id DESC
            ");
            $tasks = $stmt->fetchAll();

            foreach ($tasks as &$task) {
                if (($task['status'] ?? '') === 'paused' && (int) ($task['running_jobs'] ?? 0) === 0) {
                    $task['batch_status'] = 'idle';
                } else {
                    $task['batch_status'] = $task['queue_status'] ?: 'idle';
                }
                $task['process_exists'] = ((int) $task['running_jobs']) > 0;
                $task['batch_success_count'] = (int) $task['run_success_count'];
                $task['batch_error_count'] = (int) $task['run_error_count'];
                $task['batch_error_message'] = trim((string) ($task['last_error_message'] ?? ''));
            }

            echo json_encode([
                'success' => true,
                'tasks' => $tasks,
                'workers' => getActiveWorkers($db),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'health_check':
            $recoveredJobs = $queueService->recoverStaleJobs();
            $staleWorkers = cleanupStaleWorkers($db);
            echo json_encode([
                'success' => true,
                'issues_found' => $recoveredJobs + $staleWorkers,
                'details' => [
                    'recovered_jobs' => $recoveredJobs,
                    'stale_workers' => $staleWorkers,
                    'active_workers' => getActiveWorkers($db)
                ]
            ]);
            break;

        case 'cleanup':
            $cleaned = $queueService->recoverStaleJobs();
            echo json_encode([
                'success' => true,
                'message' => "已清理 $cleaned 个孤儿状态",
                'cleaned_count' => $cleaned
            ]);
            break;

        case 'recover':
            $recovered = $queueService->recoverStaleJobs();
            echo json_encode([
                'success' => true,
                'message' => "已恢复 $recovered 个卡住的 job",
                'recovered_count' => $recovered
            ]);
            break;

        case 'statistics':
            $statsStmt = $db->query("
                SELECT status, COUNT(*) as count
                FROM job_queue
                GROUP BY status
            ");
            $stats = $statsStmt->fetchAll();

            $stmt = $db->query("
                SELECT
                    COUNT(*) as total_tasks,
                    SUM(created_count) as total_articles,
                    SUM(published_count) as total_published,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_tasks,
                    COUNT(CASE WHEN status = 'paused' THEN 1 END) as paused_tasks
                FROM tasks
            ");
            $overall = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'batch_status' => $stats,
                'overall' => $overall,
                'workers' => getActiveWorkers($db),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'logs':
            // 获取最近的日志
            $task_id = $_GET['task_id'] ?? null;
            $lines = max(1, min(200, (int) ($_GET['lines'] ?? 50)));

            if ($task_id) {
                $log_file = __DIR__ . "/../logs/batch_{$task_id}.log";
                if (file_exists($log_file)) {
                    $log_content = readLogTail($log_file, $lines);
                    echo json_encode([
                        'success' => true,
                        'log_content' => $log_content,
                        'file_size' => filesize($log_file),
                        'last_modified' => date('Y-m-d H:i:s', filemtime($log_file))
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => '日志文件不存在'
                    ]);
                }
            } else {
                // 获取系统日志
                $log_file = __DIR__ . "/../logs/task_manager_" . date('Y-m-d') . ".log";
                if (file_exists($log_file)) {
                    $log_content = readLogTail($log_file, $lines);
                    echo json_encode([
                        'success' => true,
                        'log_content' => $log_content,
                        'file_size' => filesize($log_file),
                        'last_modified' => date('Y-m-d H:i:s', filemtime($log_file))
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'log_content' => '暂无日志记录',
                        'file_size' => 0,
                        'last_modified' => null
                    ]);
                }
            }
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => '无效的操作'
            ]);
            break;
    }

} catch (Exception $e) {
    write_log('task_health_check failed: ' . $e->getMessage(), 'ERROR');
    echo json_encode([
        'success' => false,
        'message' => '操作失败，请稍后重试'
    ]);
}

function getActiveWorkers(PDO $db): array {
    $stmt = $db->query("
        SELECT worker_id, status, current_job_id, last_seen_at, meta
        FROM worker_heartbeats
        WHERE last_seen_at >= " . db_now_minus_seconds_sql(180) . "
        ORDER BY last_seen_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cleanupStaleWorkers(PDO $db): int {
    $stmt = $db->prepare("
        DELETE FROM worker_heartbeats
        WHERE last_seen_at < " . db_now_minus_seconds_sql(600) . "
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

function readLogTail(string $logFile, int $lines): string {
    $content = @file($logFile, FILE_IGNORE_NEW_LINES);
    if ($content === false) {
        return '';
    }

    $tail = array_slice($content, -$lines);
    return implode(PHP_EOL, $tail);
}
?>
