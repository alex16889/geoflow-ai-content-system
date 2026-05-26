<?php
/**
 * 系统诊断工具 - 应用级健康检查
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_queue_service.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_super_admin();

// 释放 session 锁，避免诊断页阻塞其他后台请求
session_write_close();

function diagnostics_status_meta(string $status): array {
    switch ($status) {
        case 'ok':
            return [
                'label' => __('system_diagnostics.status.ok'),
                'class' => 'bg-green-100 text-green-800',
            ];
        case 'warning':
            return [
                'label' => __('system_diagnostics.status.warning'),
                'class' => 'bg-yellow-100 text-yellow-800',
            ];
        default:
            return [
                'label' => __('system_diagnostics.status.error'),
                'class' => 'bg-red-100 text-red-800',
            ];
    }
}

function getRuntimeOverview(): array {
    $adminBasePath = '/' . trim((string) (getenv('ADMIN_BASE_PATH') ?: 'geo_admin'), '/');

    return [
        ['label' => __('system_diagnostics.runtime.php_version'), 'value' => PHP_VERSION],
        ['label' => __('system_diagnostics.runtime.php_sapi'), 'value' => PHP_SAPI],
        ['label' => __('system_diagnostics.runtime.timezone'), 'value' => date_default_timezone_get()],
        ['label' => __('system_diagnostics.runtime.db_driver'), 'value' => (string) (getenv('DB_DRIVER') ?: 'pgsql')],
        ['label' => __('system_diagnostics.runtime.admin_path'), 'value' => $adminBasePath],
        ['label' => __('system_diagnostics.runtime.session_name'), 'value' => session_name()],
        ['label' => __('system_diagnostics.runtime.memory_limit'), 'value' => (string) (ini_get('memory_limit') ?: 'N/A')],
        ['label' => __('system_diagnostics.runtime.upload_limit'), 'value' => (string) (ini_get('upload_max_filesize') ?: 'N/A')],
    ];
}

function buildDirectoryHealthCheck(string $label, string $path): array {
    if (!is_dir($path)) {
        return [
            'label' => $label,
            'status' => 'error',
            'details' => $path . ' | ' . __('system_diagnostics.details.directory_missing'),
        ];
    }

    if (!is_writable($path)) {
        return [
            'label' => $label,
            'status' => 'warning',
            'details' => $path . ' | ' . __('system_diagnostics.details.directory_read_only'),
        ];
    }

    return [
        'label' => $label,
        'status' => 'ok',
        'details' => $path . ' | ' . __('system_diagnostics.details.directory_writable'),
    ];
}

function getHealthChecks(PDO $db): array {
    $checks = [];

    try {
        $db->query('SELECT 1');
        $checks[] = [
            'label' => __('system_diagnostics.check.database'),
            'status' => 'ok',
            'details' => __('system_diagnostics.details.database_ok'),
        ];
    } catch (Throwable $e) {
        write_log('system_diagnostics database check failed: ' . $e->getMessage(), 'ERROR');
        $checks[] = [
            'label' => __('system_diagnostics.check.database'),
            'status' => 'error',
            'details' => __('system_diagnostics.details.database_error'),
        ];
    }

    $checks[] = buildDirectoryHealthCheck(
        __('system_diagnostics.check.uploads_dir'),
        realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads')
    );
    $checks[] = buildDirectoryHealthCheck(
        __('system_diagnostics.check.logs_dir'),
        realpath(__DIR__ . '/../logs') ?: (__DIR__ . '/../logs')
    );
    $checks[] = buildDirectoryHealthCheck(
        __('system_diagnostics.check.data_dir'),
        realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data')
    );

    return $checks;
}

function getQueueSummary(PDO $db): array {
    $counts = [
        'pending' => 0,
        'running' => 0,
        'failed' => 0,
        'completed' => 0,
        'cancelled' => 0,
    ];

    try {
        $stmt = $db->query("
            SELECT status, COUNT(*) AS count
            FROM job_queue
            GROUP BY status
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['count'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        write_log('system_diagnostics queue summary failed: ' . $e->getMessage(), 'ERROR');
    }

    $activeWorkers = 0;
    $staleWorkers = 0;
    $staleRunningJobs = 0;

    try {
        $activeWorkers = (int) $db->query("
            SELECT COUNT(*)
            FROM worker_heartbeats
            WHERE last_seen_at >= " . db_now_minus_seconds_sql(180) . "
        ")->fetchColumn();

        $staleWorkers = (int) $db->query("
            SELECT COUNT(*)
            FROM worker_heartbeats
            WHERE last_seen_at < " . db_now_minus_seconds_sql(600) . "
        ")->fetchColumn();

        $staleRunningJobs = (int) $db->query("
            SELECT COUNT(*)
            FROM job_queue
            WHERE status = 'running'
              AND updated_at < " . db_now_minus_seconds_sql(600) . "
        ")->fetchColumn();
    } catch (Throwable $e) {
        write_log('system_diagnostics worker summary failed: ' . $e->getMessage(), 'ERROR');
    }

    return [
        ['label' => __('system_diagnostics.queue.pending'), 'count' => $counts['pending']],
        ['label' => __('system_diagnostics.queue.running'), 'count' => $counts['running']],
        ['label' => __('system_diagnostics.queue.failed'), 'count' => $counts['failed']],
        ['label' => __('system_diagnostics.queue.completed'), 'count' => $counts['completed']],
        ['label' => __('system_diagnostics.queue.cancelled'), 'count' => $counts['cancelled']],
        ['label' => __('system_diagnostics.queue.active_workers'), 'count' => $activeWorkers],
        ['label' => __('system_diagnostics.queue.stale_workers'), 'count' => $staleWorkers],
        ['label' => __('system_diagnostics.queue.stale_running'), 'count' => $staleRunningJobs],
    ];
}

function getTaskStatus(PDO $db): array {
    try {
        $stmt = $db->query("
            SELECT
                t.id,
                t.name,
                t.status,
                COALESCE((
                    SELECT jq.status
                    FROM job_queue jq
                    WHERE jq.task_id = t.id
                      AND jq.status IN ('running', 'pending', 'failed', 'completed')
                    ORDER BY
                        CASE jq.status
                            WHEN 'running' THEN 1
                            WHEN 'pending' THEN 2
                            WHEN 'failed' THEN 3
                            ELSE 4
                        END,
                        jq.updated_at DESC,
                        jq.id DESC
                    LIMIT 1
                ), 'idle') AS batch_status
            FROM tasks t
            ORDER BY id
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        write_log('system_diagnostics task status failed: ' . $e->getMessage(), 'ERROR');
        return [];
    }
}

function getWorkerStatus(PDO $db): array {
    try {
        $stmt = $db->query("
            SELECT worker_id, status, current_job_id, last_seen_at
            FROM worker_heartbeats
            ORDER BY last_seen_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        write_log('system_diagnostics worker status failed: ' . $e->getMessage(), 'ERROR');
        return [];
    }
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_invalid');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'cleanup_orphans') {
            $queueService = new JobQueueService($db);
            $cleaned = $queueService->recoverStaleJobs();
            $message = __('system_diagnostics.message.cleaned', ['count' => $cleaned]);
        }
    }
}

$runtime_overview = getRuntimeOverview();
$health_checks = getHealthChecks($db);
$queue_summary = getQueueSummary($db);
$tasks = getTaskStatus($db);
$workers = getWorkerStatus($db);

$page_title = __('system_diagnostics.page_title');
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">' . __('system_diagnostics.page_heading') . '</h1>
        <p class="mt-1 text-sm text-gray-600">' . __('system_diagnostics.page_subtitle') . '</p>
    </div>
    <div class="flex space-x-3">
        <form method="POST" data-confirm="' . htmlspecialchars(__('system_diagnostics.confirm.recover_stale'), ENT_QUOTES, 'UTF-8') . '" class="inline">
            <input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') . '">
            <input type="hidden" name="action" value="cleanup_orphans">
            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
            <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
            ' . __('system_diagnostics.button.recover_stale') . '
            </button>
        </form>
        <a href="?" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>
            ' . __('button.refresh') . '
        </a>
    </div>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i data-lucide="check-circle" class="h-5 w-5 text-green-400"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($message); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i data-lucide="alert-circle" class="h-5 w-5 text-red-400"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900"><?php echo __('system_diagnostics.section.php_processes'); ?></h3>
        </div>
        <div class="px-6 py-4">
            <?php if (empty($runtime_overview)): ?>
                <p class="text-gray-500"><?php echo __('system_diagnostics.empty.no_php_processes'); ?></p>
            <?php else: ?>
                <dl class="space-y-3">
                    <?php foreach ($runtime_overview as $item): ?>
                        <div class="flex items-start justify-between gap-4 border-b border-gray-100 pb-3 last:border-b-0 last:pb-0">
                            <dt class="text-sm text-gray-500"><?php echo htmlspecialchars($item['label']); ?></dt>
                            <dd class="text-sm font-medium text-gray-900 text-right break-all"><?php echo htmlspecialchars((string) $item['value']); ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900"><?php echo __('system_diagnostics.section.port_8080'); ?></h3>
        </div>
        <div class="px-6 py-4">
            <?php if (empty($health_checks)): ?>
                <p class="text-gray-500"><?php echo __('system_diagnostics.empty.port_8080_free'); ?></p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('system_diagnostics.column.label'); ?></th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('system_diagnostics.column.result'); ?></th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('system_diagnostics.column.details'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($health_checks as $check): ?>
                                <?php $meta = diagnostics_status_meta((string) ($check['status'] ?? 'error')); ?>
                                <tr>
                                    <td class="px-4 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($check['label']); ?></td>
                                    <td class="px-4 py-4 text-sm">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?php echo $meta['class']; ?>">
                                            <?php echo htmlspecialchars($meta['label']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-gray-600 break-all"><?php echo htmlspecialchars($check['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="bg-white shadow rounded-lg my-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-medium text-gray-900"><?php echo __('system_diagnostics.section.queue_summary'); ?></h3>
    </div>
    <div class="px-6 py-4">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <?php foreach ($queue_summary as $item): ?>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($item['label']); ?></p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900"><?php echo (int) $item['count']; ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="bg-white shadow rounded-lg mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-medium text-gray-900"><?php echo __('system_diagnostics.section.worker_status'); ?></h3>
    </div>
    <div class="px-6 py-4">
        <?php if (empty($workers)): ?>
            <p class="text-gray-500"><?php echo __('system_diagnostics.empty.no_worker_heartbeats'); ?></p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Worker ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('status.label'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('system_diagnostics.column.current_job'); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('system_diagnostics.column.last_heartbeat'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($workers as $worker): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-mono"><?php echo htmlspecialchars($worker['worker_id']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($worker['status']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-mono"><?php echo htmlspecialchars((string) ($worker['current_job_id'] ?? '')); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars((string) $worker['last_seen_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="bg-white shadow rounded-lg mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-medium text-gray-900"><?php echo __('system_diagnostics.section.task_status'); ?></h3>
    </div>
    <div class="px-6 py-4">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('system_diagnostics.column.name'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('status.label'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('system_diagnostics.column.batch_status'); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono"><?php echo (int) $task['id']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($task['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($task['status']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?php echo htmlspecialchars($task['batch_status'] ?? __('system_diagnostics.value.idle')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
