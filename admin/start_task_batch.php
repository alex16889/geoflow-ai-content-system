<?php
/**
 * 启动任务批量执行 - 后台静默执行
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('FEISHU_TREASURE', true);
session_start();

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/database_admin.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/job_queue_service.php';

    // 检查管理员登录
    if (!is_admin_logged_in() || !get_current_admin(true)) {
        echo json_encode(['success' => false, 'message' => '未登录或登录已过期']);
        exit;
    }

    // 立即关闭session，释放锁，允许其他请求并发执行
    session_write_close();

    // 只处理POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

    $queueService = new JobQueueService($db);

    // 获取任务ID
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $csrfToken = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!verify_csrf_token($csrfToken)) {
        echo json_encode(['success' => false, 'message' => __('message.csrf_invalid')]);
        exit;
    }

    $task_id = $input['task_id'] ?? 0;
    $action = $input['action'] ?? 'start'; // start 或 stop

    log_admin_request_if_needed([
        'action' => 'task_batch_' . $action,
        'page' => 'start_task_batch.php',
        'target_type' => 'task',
        'target_id' => is_numeric($task_id) ? (int) $task_id : null,
        'details' => sanitize_admin_activity_payload($input ?? [])
    ]);

    if (!$task_id || !is_numeric($task_id)) {
        echo json_encode(['success' => false, 'message' => '无效的任务ID']);
        exit;
    }

    if (!in_array($action, ['start', 'stop'])) {
        echo json_encode(['success' => false, 'message' => '无效的操作']);
        exit;
    }

    // 获取任务信息
    $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ?" . geoflow_site_scope_sql('tasks'));
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();

    if (!$task) {
        echo json_encode(['success' => false, 'message' => '任务不存在']);
        exit;
    }

    if ($action === 'start') {
        $stmt = $db->prepare("
            UPDATE tasks
            SET status = 'active',
                schedule_enabled = 1,
                next_run_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?" . geoflow_site_scope_sql('tasks') . "
        ");
        $stmt->execute([$task_id]);

        $jobId = $queueService->enqueueTaskJob((int) $task_id, 'generate_article', ['source' => 'admin_manual']);
        if ($jobId === null) {
            echo json_encode([
                'success' => true,
                'message' => '任务已处于排队或执行中',
                'status' => 'running'
            ]);
            exit;
        }

        write_log("任务 {$task_id} 已手动入队 job #{$jobId}", 'INFO');
        echo json_encode([
            'success' => true,
            'message' => '任务已加入执行队列',
            'status' => 'running',
            'job_id' => $jobId
        ]);

    } elseif ($action === 'stop') {
        try {
            $stmt = $db->prepare("
                UPDATE tasks
                SET status = 'paused',
                    schedule_enabled = 0,
                    next_run_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?" . geoflow_site_scope_sql('tasks') . "
            ");
            $stmt->execute([$task_id]);

            $cancelPending = $db->prepare("
                UPDATE job_queue
                SET status = 'cancelled',
                    finished_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP,
                    error_message = '管理员手动停止'
                WHERE task_id = ?
                  AND status = 'pending'
            ");
            $cancelPending->execute([$task_id]);

            $runningCountStmt = $db->prepare("
                SELECT COUNT(*)
                FROM job_queue
                WHERE task_id = ?
                  AND status = 'running'
            ");
            $runningCountStmt->execute([$task_id]);
            $runningCount = (int) $runningCountStmt->fetchColumn();

            write_log("任务 {$task_id} 已关闭调度，并取消 {$cancelPending->rowCount()} 个待执行 job", 'INFO');

            echo json_encode([
                'success' => true,
                'message' => '任务已暂停，待执行 job 已取消',
                'status' => 'paused',
                'details' => $runningCount > 0 ? '正在执行的 job 会在下一处理阶段自动停止' : '待执行 job 已取消',
                'process_stopped' => $runningCount === 0,
                'cancelled_jobs' => $cancelPending->rowCount(),
                'running_jobs' => $runningCount
            ]);
        } catch (Exception $e) {
            write_log("停止任务时发生错误: " . $e->getMessage(), 'ERROR');
            echo json_encode([
                'success' => false,
                'message' => '停止任务时发生错误',
                'details' => '请查看系统日志获取更多信息'
            ]);
        }

    } else {
        echo json_encode(['success' => false, 'message' => '无效的操作']);
    }

} catch (Exception $e) {
    write_log('start_task_batch failed: ' . $e->getMessage(), 'ERROR');
    echo json_encode([
        'success' => false,
        'message' => '操作失败，请稍后重试'
    ]);
}
?>
