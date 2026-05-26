<?php
/**
 * GEO+AI内容生成系统 - 轻量调度器
 * 职责：补齐任务入队、恢复卡死 job、自动发布审核通过文章
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/job_queue_service.php';
require_once $projectRoot . '/includes/content_quality_service.php';
require_once $projectRoot . '/includes/search_submission_service.php';

set_time_limit(120);

$startTime = microtime(true);
$logMessages = [];

function log_message($message) {
    global $logMessages;
    $timestamp = date('Y-m-d H:i:s');
    $logMessages[] = "[{$timestamp}] {$message}";
    echo "[{$timestamp}] {$message}\n";
}

try {
    log_message('轻量调度器开始执行');

    $queueService = new JobQueueService($db);
    $recoveredCount = $queueService->recoverStaleJobs();
    if ($recoveredCount > 0) {
        log_message("恢复 {$recoveredCount} 个卡住的 job");
    }

    $stmt = $db->query("
        SELECT
            t.id,
            t.name,
            t.publish_interval,
            t.draft_limit,
            t.next_run_at,
            COALESCE(t.schedule_enabled, 1) AS schedule_enabled,
            (
                SELECT COUNT(*)
                FROM articles a
                WHERE a.task_id = t.id
                  AND a.status = 'draft'
                  AND a.deleted_at IS NULL
            ) AS draft_count
        FROM tasks t
        WHERE t.status = 'active'
        ORDER BY t.updated_at ASC, t.id ASC
    ");
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    log_message('扫描到 ' . count($tasks) . ' 个活跃任务');

    $queuedCount = 0;
    $skippedCount = 0;

    foreach ($tasks as $task) {
        if ((int) $task['schedule_enabled'] !== 1) {
            $skippedCount++;
            continue;
        }

        if ((int) $task['draft_count'] >= (int) $task['draft_limit']) {
            log_message("任务 {$task['name']} 草稿已满 ({$task['draft_count']}/{$task['draft_limit']})，跳过入队");
            $skippedCount++;
            continue;
        }

        if (empty($task['next_run_at'])) {
            $queueService->initializeTaskSchedule((int) $task['id']);
            log_message("任务 {$task['name']} 初始化 next_run_at，等待下一轮调度");
            $skippedCount++;
            continue;
        }

        if (strtotime($task['next_run_at']) > time()) {
            $skippedCount++;
            continue;
        }

        if ($queueService->hasPendingOrRunningJob((int) $task['id'])) {
            log_message("任务 {$task['name']} 已有待执行 job，跳过重复入队");
            $skippedCount++;
            continue;
        }

        $jobId = $queueService->enqueueTaskJob((int) $task['id']);
        if ($jobId === null) {
            $skippedCount++;
            continue;
        }

        $nextRunAt = date('Y-m-d H:i:s', time() + max(60, (int) $task['publish_interval']));
        $update = $db->prepare("
            UPDATE tasks
            SET next_run_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([$nextRunAt, $task['id']]);

        $queuedCount++;
        log_message("任务 {$task['name']} 已入队 job #{$jobId}，下次执行时间 {$nextRunAt}");
    }

    cleanupTaskSchedules();
    resetDailyAIUsage();
    autoPublishApprovedArticles();
    processSearchSubmissionQueue();

    $executionTime = round(microtime(true) - $startTime, 2);
    log_message("轻量调度器执行完成，入队 {$queuedCount} 个任务，跳过 {$skippedCount} 个任务");
    saveExecutionLog($queuedCount, $skippedCount, $recoveredCount, $executionTime);
} catch (Throwable $e) {
    log_message('轻量调度器异常: ' . $e->getMessage());
    $stmt = $db->prepare("INSERT INTO system_logs (type, message, data) VALUES (?, ?, ?)");
    $stmt->execute([
        'error',
        '轻量调度器执行异常: ' . $e->getMessage(),
        json_encode([
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], JSON_UNESCAPED_UNICODE)
    ]);
    exit(1);
}

function cleanupTaskSchedules() {
    global $db;

    $stmt = $db->prepare("
        DELETE FROM task_schedules
        WHERE created_at < " . db_now_minus_seconds_sql(7 * 24 * 60 * 60) . "
    ");
    $stmt->execute();
}

function resetDailyAIUsage() {
    global $db;

    $today = date('Y-m-d');
    $stmt = $db->prepare("
        UPDATE ai_models
        SET used_today = 0, updated_at = CURRENT_TIMESTAMP
        WHERE DATE(updated_at) < ?
          AND used_today > 0
    ");
    $stmt->execute([$today]);
}

function saveExecutionLog($queuedCount, $skippedCount, $recoveredCount, $executionTime) {
    global $db, $logMessages;

    $isIdleRun = (int) $queuedCount === 0
        && (int) $skippedCount === 0
        && (int) $recoveredCount === 0
        && count($logMessages) <= 3;

    if ($isIdleRun) {
        return;
    }

    $logData = [
        'queued_count' => $queuedCount,
        'skipped_count' => $skippedCount,
        'recovered_count' => $recoveredCount,
        'execution_time' => $executionTime,
        'messages' => $logMessages
    ];

    $stmt = $db->prepare("
        INSERT INTO system_logs (type, message, data)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([
        'cron',
        "轻量调度器执行完成: 入队 {$queuedCount} 个任务，跳过 {$skippedCount} 个任务",
        json_encode($logData, JSON_UNESCAPED_UNICODE)
    ]);

    $logFile = __DIR__ . '/logs/cron_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, implode("\n", $logMessages) . "\n", FILE_APPEND | LOCK_EX);
}

function autoPublishApprovedArticles() {
    global $db;

    $stmt = $db->prepare("
        SELECT a.*, t.publish_interval
        FROM articles a
        JOIN tasks t ON a.task_id = t.id
        WHERE a.review_status = 'approved'
          AND a.status = 'draft'
          AND a.deleted_at IS NULL
          AND t.need_review = 0
    ");
    $stmt->execute();
    $articlesToPublish = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $publishedCount = 0;

    foreach ($articlesToPublish as $article) {
        $createdTime = strtotime($article['created_at']);
        if ((time() - $createdTime) < (int) $article['publish_interval']) {
            continue;
        }

        geoflow_set_runtime_site_id((int) ($article['site_id'] ?? 0));
        $quality = ContentQualityService::guardPublish($db, (int) $article['id'], $article);
        geoflow_clear_runtime_site_id();
        if (empty($quality['allowed'])) {
            log_message("文章质量分不足，跳过自动发布: {$article['title']} (ID: {$article['id']}, score: {$quality['score']})");
            continue;
        }

        $publish = $db->prepare("
            UPDATE articles
            SET status = 'published',
                published_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $publish->execute([$article['id']]);

        $taskUpdate = $db->prepare("
            UPDATE tasks
            SET published_count = published_count + 1
            WHERE id = ?
        ");
        $taskUpdate->execute([$article['task_id']]);

        $publishedCount++;
        geoflow_set_runtime_site_id((int) ($article['site_id'] ?? 0));
        SearchSubmissionService::queueArticle($db, (int) $article['id']);
        geoflow_clear_runtime_site_id();
        log_message("自动发布文章: {$article['title']} (ID: {$article['id']})");
    }

    if ($publishedCount > 0) {
        log_message("自动发布了 {$publishedCount} 篇文章");
    }
}

function processSearchSubmissionQueue() {
    global $db;

    if (!geoflow_db_table_exists($db, 'url_indexing_queue') || !geoflow_db_table_exists($db, 'sites')) {
        return;
    }

    $stmt = $db->query("
        SELECT DISTINCT s.id
        FROM sites s
        INNER JOIN url_indexing_queue q ON q.site_id = s.id
        WHERE s.status = 'active'
          AND q.status IN ('pending', 'failed')
        ORDER BY s.id ASC
        LIMIT 20
    ");
    $siteIds = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    foreach ($siteIds as $siteId) {
        geoflow_set_runtime_site_id((int) $siteId);
        $result = SearchSubmissionService::submitPending($db, (int) $siteId, 100);
        geoflow_clear_runtime_site_id();
        if (($result['submitted'] ?? 0) > 0 || ($result['failed'] ?? 0) > 0) {
            $providerNames = implode(',', array_keys($result['providers'] ?? []));
            log_message("搜索推送 站点 {$siteId}: submitted={$result['submitted']} failed={$result['failed']} providers={$providerNames} message={$result['message']}");
        }
    }
}
