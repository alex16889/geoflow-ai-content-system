<?php
/**
 * AI标题生成异步处理脚本
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

require_admin_login();

$currentAdmin = get_current_admin(true);
if (!$currentAdmin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录或登录已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$asyncAdminId = (int) ($currentAdmin['id'] ?? 0);
session_write_close();

header('Content-Type: application/json; charset=utf-8');

function title_generate_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function title_generate_task_storage_dir(): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/geoflow_title_generate_tasks';
}

function title_generate_task_storage_path(string $taskId): string {
    $safeTaskId = preg_replace('/[^A-Za-z0-9_.-]/', '_', $taskId);
    return title_generate_task_storage_dir() . '/' . $safeTaskId . '.json';
}

function title_generate_ensure_storage_dir(): string {
    $dir = title_generate_task_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function title_generate_cleanup_stale_tasks(int $ttlSeconds = 86400): void {
    $dir = title_generate_task_storage_dir();
    if (!is_dir($dir)) {
        return;
    }

    $cutoff = time() - max(3600, $ttlSeconds);
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

function title_generate_load_task_state(string $taskId): ?array {
    $path = title_generate_task_storage_path($taskId);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function title_generate_save_task_state(array $task): array {
    $taskId = trim((string) ($task['task_id'] ?? ''));
    if ($taskId === '') {
        throw new RuntimeException('缺少任务ID');
    }

    title_generate_ensure_storage_dir();
    $task['task_id'] = $taskId;
    $task['updated_at'] = date('Y-m-d H:i:s');
    $path = title_generate_task_storage_path($taskId);
    $payload = json_encode($task, JSON_UNESCAPED_UNICODE);

    if ($payload === false || @file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('任务状态保存失败');
    }

    @chmod($path, 0600);
    return $task;
}

function title_generate_find_latest_task_for_admin(int $adminId): ?array {
    $dir = title_generate_task_storage_dir();
    if (!is_dir($dir)) {
        return null;
    }

    $files = glob($dir . '/*.json') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    foreach ($files as $file) {
        $taskId = pathinfo($file, PATHINFO_FILENAME);
        $task = title_generate_load_task_state($taskId);
        if (is_array($task) && (int) ($task['admin_id'] ?? 0) === $adminId) {
            return $task;
        }
    }

    return null;
}

function title_generate_get_task_for_admin(string $taskId, int $adminId): array {
    $task = title_generate_load_task_state($taskId);
    if (!$task || (int) ($task['admin_id'] ?? 0) !== $adminId) {
        throw new RuntimeException('任务不存在');
    }

    return $task;
}

function title_generate_apply_updates(array $task, array $updates): array {
    return title_generate_save_task_state(array_merge($task, $updates));
}

/**
 * 生成模拟标题（当AI API不可用时使用）
 */
function generateMockTitles($keywords, $count, $style_desc) {
    $templates = [
        '专业严谨的' => [
            '{keyword}的深度分析与研究',
            '关于{keyword}的专业见解',
            '{keyword}行业发展趋势报告',
            '{keyword}技术解决方案详解',
            '{keyword}最佳实践指南'
        ],
        '吸引眼球的' => [
            '震惊！{keyword}的惊人真相',
            '你绝对不知道的{keyword}秘密',
            '{keyword}：改变世界的力量',
            '揭秘{keyword}背后的故事',
            '{keyword}让人意想不到的用途'
        ],
        'SEO优化的' => [
            '{keyword}完整指南：从入门到精通',
            '2025年{keyword}最新趋势分析',
            '{keyword}vs传统方法：哪个更好？',
            '如何选择最适合的{keyword}方案',
            '{keyword}常见问题解答大全'
        ],
        '创意新颖的' => [
            '如果{keyword}会说话，它会告诉你什么？',
            '{keyword}的奇幻之旅',
            '重新定义{keyword}的可能性',
            '{keyword}：未来世界的钥匙',
            '当{keyword}遇上创新思维'
        ],
        '疑问式的' => [
            '{keyword}真的有用吗？',
            '为什么{keyword}如此重要？',
            '{keyword}是否值得投资？',
            '如何正确使用{keyword}？',
            '{keyword}的未来在哪里？'
        ]
    ];

    $styleTemplates = $templates[$style_desc] ?? $templates['专业严谨的'];
    $generatedTitles = [];

    for ($i = 0; $i < $count; $i++) {
        $template = $styleTemplates[array_rand($styleTemplates)];
        $keyword = $keywords[array_rand($keywords)];
        $generatedTitles[] = str_replace('{keyword}', $keyword, $template);
    }

    return $generatedTitles;
}

try {
    $action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['start_generate', 'process_generate'], true)) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException(__('message.csrf_failed'));
        }
    }

    if ($action === 'start_generate') {
        title_generate_cleanup_stale_tasks();

        $siteId = geoflow_required_site_id($db);
        $libraryId = (int) ($_POST['library_id'] ?? 0);
        $keywordLibraryId = (int) ($_POST['keyword_library_id'] ?? 0);
        $aiModelId = (int) ($_POST['ai_model_id'] ?? 0);
        $customPrompt = trim((string) ($_POST['custom_prompt'] ?? ''));
        $titleCount = (int) ($_POST['title_count'] ?? 10);
        $titleStyle = (string) ($_POST['title_style'] ?? 'professional');

        if ($libraryId <= 0 || $keywordLibraryId <= 0 || $aiModelId <= 0) {
            throw new RuntimeException('参数错误');
        }

        if ($siteId <= 0) {
            throw new RuntimeException('当前站点无效，请先选择一个站点');
        }

        if (!geoflow_record_belongs_to_site($db, 'title_libraries', $libraryId, $siteId)) {
            throw new RuntimeException('标题库不属于当前站点');
        }

        if (!geoflow_record_belongs_to_site($db, 'keyword_libraries', $keywordLibraryId, $siteId)) {
            throw new RuntimeException('关键词库不属于当前站点');
        }

        if ($titleCount < 1 || $titleCount > 50) {
            throw new RuntimeException('标题数量超出允许范围');
        }

        $taskId = 'title_gen_' . time() . '_' . bin2hex(random_bytes(6));
        title_generate_save_task_state([
            'task_id' => $taskId,
            'admin_id' => $asyncAdminId,
            'site_id' => $siteId,
            'library_id' => $libraryId,
            'keyword_library_id' => $keywordLibraryId,
            'ai_model_id' => $aiModelId,
            'custom_prompt' => $customPrompt,
            'title_count' => $titleCount,
            'title_style' => $titleStyle,
            'status' => 'running',
            'progress' => 0,
            'generated_count' => 0,
            'total_count' => $titleCount,
            'start_time' => time(),
            'message' => '正在初始化...',
        ]);

        title_generate_json_response([
            'success' => true,
            'task_id' => $taskId,
            'message' => '任务已启动'
        ]);
    }

    if ($action === 'get_progress') {
        $taskId = trim((string) ($_GET['task_id'] ?? ''));
        $task = $taskId !== ''
            ? title_generate_get_task_for_admin($taskId, $asyncAdminId)
            : title_generate_find_latest_task_for_admin($asyncAdminId);

        if (!$task) {
            title_generate_json_response([
                'success' => false,
                'message' => '任务不存在'
            ], 404);
        }

        title_generate_json_response([
            'success' => true,
            'task' => $task
        ]);
    }

    if ($action !== 'process_generate') {
        throw new RuntimeException('无效的操作');
    }

    $taskId = trim((string) ($_POST['task_id'] ?? ''));
    if ($taskId === '') {
        throw new RuntimeException('缺少任务ID');
    }

    $task = title_generate_get_task_for_admin($taskId, $asyncAdminId);
    $siteId = (int) ($task['site_id'] ?? 0);
    if ($siteId > 0) {
        geoflow_set_runtime_site_id($siteId);
    }

    if ($siteId <= 0 || !geoflow_record_belongs_to_site($db, 'title_libraries', (int) $task['library_id'], $siteId)) {
        throw new RuntimeException('标题库不属于当前站点');
    }

    if (!geoflow_record_belongs_to_site($db, 'keyword_libraries', (int) $task['keyword_library_id'], $siteId)) {
        throw new RuntimeException('关键词库不属于当前站点');
    }

    if (($task['status'] ?? '') !== 'running') {
        title_generate_json_response([
            'success' => false,
            'message' => '任务状态异常'
        ], 409);
    }

    $task = title_generate_apply_updates($task, ['message' => '正在获取关键词...']);

    $stmt = $db->prepare("SELECT keyword FROM keywords WHERE library_id = ? ORDER BY RANDOM() LIMIT 10");
    $stmt->execute([(int) $task['keyword_library_id']]);
    $keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($keywords)) {
        throw new RuntimeException('关键词库中没有关键词');
    }

    $stmt = $db->prepare("
        SELECT *
        FROM ai_models
        WHERE id = ?
          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
    ");
    $stmt->execute([(int) $task['ai_model_id']]);
    $aiModel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$aiModel) {
        throw new RuntimeException('AI模型不存在');
    }

    $aiModel['api_key'] = decrypt_ai_api_key($aiModel['api_key'] ?? '');
    $task = title_generate_apply_updates($task, ['message' => '正在调用AI服务...']);

    $stylePrompts = [
        'professional' => '专业严谨的',
        'attractive' => '吸引眼球的',
        'seo' => 'SEO优化的',
        'creative' => '创意新颖的',
        'question' => '疑问式的'
    ];

    $styleDesc = $stylePrompts[$task['title_style']] ?? '专业的';
    $keywordsText = implode('、', $keywords);
    $systemPrompt = "你是一个专业的内容标题生成专家。请根据提供的关键词生成{$styleDesc}文章标题。";
    $userPrompt = "请基于以下关键词生成 {$task['title_count']} 个{$styleDesc}文章标题：\n\n关键词：{$keywordsText}\n\n";

    if (!empty($task['custom_prompt'])) {
        $userPrompt .= "额外要求：{$task['custom_prompt']}\n\n";
    }

    $userPrompt .= "要求：\n1. 每个标题独占一行\n2. 标题要有吸引力和可读性\n3. 适合搜索引擎优化\n4. 不要添加序号或其他标记\n5. 直接输出标题内容";

    try {
        $apiData = [
            'model' => $aiModel['model_id'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'temperature' => 0.8,
            'max_tokens' => 2000
        ];

        $ch = curl_init();
        apply_ai_curl_request_defaults($ch, 180, 10);
        curl_setopt_array($ch, [
            CURLOPT_URL => ai_chat_endpoint_from_url($aiModel['api_url']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($apiData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $aiModel['api_key']
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            $task = title_generate_apply_updates($task, ['message' => 'AI服务不可用，使用模拟生成...']);
            $generatedTitles = generateMockTitles($keywords, (int) $task['title_count'], $styleDesc);
        } else {
            $result = json_decode((string) $response, true);
            if (!$result || !isset($result['choices'][0]['message']['content'])) {
                $generatedTitles = generateMockTitles($keywords, (int) $task['title_count'], $styleDesc);
            } else {
                $generatedContent = trim((string) $result['choices'][0]['message']['content']);
                $generatedTitles = array_filter(array_map('trim', explode("\n", $generatedContent)));
            }
        }
    } catch (Throwable $e) {
        $task = title_generate_apply_updates($task, ['message' => 'AI服务异常，使用模拟生成...']);
        $generatedTitles = generateMockTitles($keywords, (int) $task['title_count'], $styleDesc);
    }

    $task = title_generate_apply_updates($task, ['message' => '正在保存标题...']);

    $savedCount = 0;
    $duplicateCount = 0;
    $startedTransaction = false;

    try {
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTransaction = true;
        }

        foreach ($generatedTitles as $title) {
            if (empty($title)) {
                continue;
            }

            $title = preg_replace('/^\d+[\.\)]\s*/', '', $title);
            $title = trim((string) $title);

            if ($title === '' || mb_strlen($title) > 500) {
                continue;
            }

            $stmt = $db->prepare("SELECT COUNT(*) FROM titles WHERE library_id = ? AND title = ?");
            $stmt->execute([(int) $task['library_id'], $title]);
            if ((int) $stmt->fetchColumn() > 0) {
                $duplicateCount++;
                continue;
            }

            $randomKeyword = $keywords[array_rand($keywords)];
            $stmt = $db->prepare("INSERT INTO titles (library_id, title, keyword, is_ai_generated) VALUES (?, ?, ?, TRUE)");
            $stmt->execute([(int) $task['library_id'], $title, $randomKeyword]);
            $savedCount++;

            $task = title_generate_apply_updates($task, [
                'generated_count' => $savedCount,
                'progress' => (int) round(($savedCount / max(1, (int) $task['title_count'])) * 100),
            ]);
        }

        refresh_title_library_count($db, (int) $task['library_id']);

        if ($startedTransaction && $db->inTransaction()) {
            $db->commit();
        }

        $completionMessage = "生成完成！成功保存 {$savedCount} 个标题";
        if ($duplicateCount > 0) {
            $completionMessage .= "，跳过 {$duplicateCount} 个重复标题";
        }

        $task = title_generate_apply_updates($task, [
            'status' => 'completed',
            'message' => $completionMessage,
            'progress' => 100,
            'generated_count' => $savedCount,
            'duplicate_count' => $duplicateCount,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);

        title_generate_json_response([
            'success' => true,
            'message' => $task['message'],
            'saved_count' => $savedCount,
            'duplicate_count' => $duplicateCount,
        ]);
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }

        title_generate_apply_updates($task, [
            'status' => 'error',
            'message' => '保存失败，请稍后重试',
        ]);

        title_generate_json_response([
            'success' => false,
            'message' => '保存失败，请稍后重试'
        ], 500);
    }
} catch (Throwable $e) {
    title_generate_json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 400);
}
