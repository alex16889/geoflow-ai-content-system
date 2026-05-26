<?php
/**
 * 兼容旧版的单任务执行接口
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

define('FEISHU_TREASURE', true);
session_start();

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/database_admin.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/job_queue_service.php';

    if (!is_admin_logged_in() || !get_current_admin(true)) {
        echo json_encode(['success' => false, 'message' => '未登录或登录已过期']);
        exit;
    }

    session_write_close();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

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

    log_admin_request_if_needed([
        'action' => 'execute_task',
        'page' => 'execute_task.php',
        'target_type' => 'task',
        'target_id' => is_numeric($task_id) ? (int) $task_id : null,
        'details' => sanitize_admin_activity_payload($input ?? [])
    ]);

    if (!$task_id || !is_numeric($task_id)) {
        echo json_encode(['success' => false, 'message' => '无效的任务ID']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, name, status FROM tasks WHERE id = ?" . geoflow_site_scope_sql('tasks') . " LIMIT 1");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'message' => '任务不存在']);
        exit;
    }

    if (($task['status'] ?? '') !== 'active') {
        echo json_encode(['success' => false, 'message' => '任务未激活，无法执行']);
        exit;
    }

    $queueService = new JobQueueService($db);
    $jobId = $queueService->enqueueTaskJob((int) $task_id, 'generate_article', ['source' => 'legacy_execute_task']);

    if ($jobId === null) {
        echo json_encode([
            'success' => true,
            'message' => '任务已处于排队或执行中',
            'status' => 'running'
        ]);
        exit;
    }

    write_log("旧接口 execute_task.php 将任务 {$task_id} 入队 job #{$jobId}", 'INFO');

    echo json_encode([
        'success' => true,
        'message' => '任务已加入执行队列',
        'status' => 'running',
        'job_id' => $jobId
    ]);
} catch (Throwable $e) {
    write_log('旧接口 execute_task.php 处理失败: ' . $e->getMessage(), 'ERROR');
    echo json_encode([
        'success' => false,
        'message' => '任务请求处理失败，请稍后重试'
    ]);
}
?>
