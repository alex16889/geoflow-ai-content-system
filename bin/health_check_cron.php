<?php
/**
 * 队列健康检查定时脚本
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

// 设置执行时间限制
set_time_limit(300); // 5分钟
ini_set('memory_limit', '256M');

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/functions.php';
require_once $projectRoot . '/includes/job_queue_service.php';

// 记录开始执行
write_log("任务健康检查开始执行", 'INFO');

try {
    $queueService = new JobQueueService($db);
    $recoveredJobs = $queueService->recoverStaleJobs();

    $healthCheck = db_health_check($db);
    if (!$healthCheck['ok']) {
        throw new RuntimeException($healthCheck['message']);
    }
    write_log($healthCheck['message'], 'INFO');

    $cleanupStmt = $db->prepare("
        DELETE FROM worker_heartbeats
        WHERE last_seen_at < " . db_now_minus_seconds_sql(600) . "
    ");
    $cleanupStmt->execute();
    $staleWorkers = $cleanupStmt->rowCount();

    $stats = $db->query("
        SELECT status, COUNT(*) as count
        FROM job_queue
        GROUP BY status
    ")->fetchAll(PDO::FETCH_ASSOC);

    write_log("队列健康检查完成", 'INFO');
    write_log("恢复卡住 job: {$recoveredJobs}, 清理过期 worker: {$staleWorkers}", 'INFO');
    if (!empty($stats)) {
        write_log("当前队列统计: " . json_encode($stats, JSON_UNESCAPED_UNICODE), 'INFO');
    }
} catch (Exception $e) {
    write_log("健康检查异常: " . $e->getMessage(), 'ERROR');
    write_log("文件: " . $e->getFile() . ", 行号: " . $e->getLine(), 'ERROR');
}

write_log("队列健康检查执行完成", 'INFO');
?>
