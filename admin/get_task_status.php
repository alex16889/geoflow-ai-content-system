<?php
/**
 * 获取任务状态API - 用于实时更新任务状态
 */

define('FEISHU_TREASURE', true);
session_start();

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/database_admin.php';
    require_once __DIR__ . '/../includes/functions.php';

    // 检查管理员登录
    if (!is_admin_logged_in() || !get_current_admin(true)) {
        echo json_encode(['success' => false, 'message' => '未登录或登录已过期']);
        exit;
    }

    // 获取任务ID
    $task_id = $_GET['task_id'] ?? 0;

    if (!$task_id || !is_numeric($task_id)) {
        echo json_encode(['success' => false, 'message' => '无效的任务ID']);
        exit;
    }

    // 获取任务状态
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.name,
            t.status,
            t.created_count,
            t.last_run_at,
            t.next_run_at,
            t.publish_interval,
            t.draft_limit,
            COALESCE((
                SELECT jq.status
                FROM job_queue jq
                WHERE jq.task_id = t.id
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
                WHERE jq.task_id = t.id
                  AND jq.status IN ('failed', 'completed', 'cancelled')
                ORDER BY jq.updated_at DESC, jq.id DESC
                LIMIT 1
            ), 'idle') AS batch_status,
            COALESCE((
                SELECT COUNT(*)
                FROM task_runs tr
                WHERE tr.task_id = t.id
                  AND tr.status = 'completed'
            ), 0) AS batch_success_count,
            COALESCE((
                SELECT COUNT(*)
                FROM task_runs tr
                WHERE tr.task_id = t.id
                  AND tr.status IN ('failed', 'retrying')
            ), 0) AS batch_error_count,
            COALESCE((
                SELECT COUNT(*)
                FROM job_queue jq
                WHERE jq.task_id = t.id
                  AND jq.status = 'pending'
            ), 0) AS pending_jobs,
            COALESCE((
                SELECT COUNT(*)
                FROM job_queue jq
                WHERE jq.task_id = t.id
                  AND jq.status = 'running'
            ), 0) AS running_jobs,
            (SELECT COUNT(*) FROM articles WHERE task_id = ? AND deleted_at IS NULL" . geoflow_site_scope_sql('articles') . ") as total_articles,
            (SELECT COUNT(*) FROM articles WHERE task_id = ? AND status = 'published' AND deleted_at IS NULL" . geoflow_site_scope_sql('articles') . ") as published_articles
        FROM tasks t
        WHERE t.id = ?" . geoflow_site_scope_sql('tasks', 't') . "
    ");
    $stmt->execute([$task_id, $task_id, $task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'message' => '任务不存在']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'task' => $task,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '获取状态失败：' . $e->getMessage()
    ]);
}
?>
