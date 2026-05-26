<?php
/**
 * 兼容旧版的批量执行接口
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
        echo json_encode(['success' => false, 'message' => '未登录或登录已过期，请重新登录']);
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

    log_admin_request_if_needed([
        'action' => 'execute_all_tasks',
        'page' => 'execute_all_tasks.php',
        'target_type' => 'task_batch',
        'details' => []
    ]);

    $stmt = $db->query("SELECT id, name FROM tasks WHERE status = 'active'" . geoflow_site_scope_sql('tasks') . " ORDER BY id");
    $activeTasks = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (empty($activeTasks)) {
        echo json_encode(['success' => false, 'message' => '没有活跃的任务需要执行']);
        exit;
    }

    $queueService = new JobQueueService($db);
    $queuedCount = 0;
    $alreadyQueuedCount = 0;

    foreach ($activeTasks as $task) {
        $jobId = $queueService->enqueueTaskJob((int) $task['id'], 'generate_article', ['source' => 'legacy_execute_all_tasks']);
        if ($jobId === null) {
            $alreadyQueuedCount++;
            continue;
        }
        $queuedCount++;
    }

    echo json_encode([
        'success' => true,
        'message' => '批量执行请求已提交到任务队列',
        'total' => count($activeTasks),
        'queued_count' => $queuedCount,
        'already_running_count' => $alreadyQueuedCount
    ]);
} catch (Throwable $e) {
    write_log('旧接口 execute_all_tasks.php 处理失败: ' . $e->getMessage(), 'ERROR');
    echo json_encode([
        'success' => false,
        'message' => '批量任务请求处理失败，请稍后重试'
    ]);
}
?>
