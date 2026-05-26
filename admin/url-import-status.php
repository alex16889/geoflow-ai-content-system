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

$job_id = (int) ($_GET['job_id'] ?? 0);
if ($job_id <= 0) {
    json_response(['success' => false, 'message' => '无效的任务ID'], 422);
}

$job = get_url_import_job($db, $job_id);
if (!$job) {
    json_response(['success' => false, 'message' => '任务不存在'], 404);
}

$logs = get_url_import_logs($db, $job_id);
$result = [];
if (!empty($job['result_json'])) {
    $decoded = json_decode($job['result_json'], true);
    if (is_array($decoded)) {
        $result = $decoded;
    }
}

json_response([
    'success' => true,
    'job' => [
        'id' => (int) $job['id'],
        'url' => $job['url'],
        'normalized_url' => $job['normalized_url'],
        'source_domain' => $job['source_domain'],
        'page_title' => $job['page_title'],
        'status' => $job['status'],
        'current_step' => $job['current_step'],
        'progress_percent' => (int) $job['progress_percent'],
        'error_message' => $job['error_message'],
        'created_at' => $job['created_at'],
        'updated_at' => $job['updated_at']
    ],
    'step_labels' => url_import_step_definitions(),
    'logs' => $logs,
    'result' => $result
]);
