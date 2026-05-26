<?php
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once 'includes/url-import-helpers.php';

if (!is_admin_logged_in() || !get_current_admin(true)) {
    json_response(['success' => false, 'message' => '未登录或登录已过期'], 401);
}

session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => '无效的请求方法'], 405);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    json_response(['success' => false, 'message' => 'CSRF验证失败'], 400);
}

$jobId = (int) ($_POST['job_id'] ?? 0);
log_admin_request_if_needed([
    'action' => 'url_import_commit',
    'page' => 'url-import-commit.php',
    'target_type' => 'url_import_job',
    'target_id' => $jobId > 0 ? $jobId : null,
    'details' => sanitize_admin_activity_payload($_POST)
]);

if ($jobId <= 0) {
    json_response(['success' => false, 'message' => '无效的任务ID'], 422);
}

try {
    $importResult = commit_url_import_job($db, $jobId);
    json_response([
        'success' => true,
        'message' => '采集结果已成功入库',
        'import_result' => $importResult,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
