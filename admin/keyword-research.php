<?php
/**
 * DataForSEO keyword research import.
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dataforseo_service.php';
require_once __DIR__ . '/../includes/site_spend_guard_service.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

require_admin_login();

$csrfToken = generate_csrf_token();
$message = '';
$error = '';
$lastImportSummary = null;
$connectionStatus = null;
$currentSite = geoflow_current_site();
$currentSiteId = geoflow_current_site_id();
$librarySiteCondition = geoflow_site_scope_condition('keyword_libraries');
$libraryAliasSiteCondition = geoflow_site_scope_condition('keyword_libraries', 'kl');
$dataForSeo = DataForSeoService::fromEnvironment();
$dataForSeoConfig = $dataForSeo->publicConfig();
$dataForSeoDailyBudget = SiteSpendGuardService::dataForSeoDailyBudget();
$dataForSeoTodaySpend = SiteSpendGuardService::todaySpend($db, $currentSiteId, SiteSpendGuardService::PROVIDER_DATAFORSEO);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'test_connection') {
                $connectionStatus = $dataForSeo->testConnection();
                $balanceText = $connectionStatus['balance'] !== null ? '$' . number_format((float) $connectionStatus['balance'], 4) : '未知';
                $message = 'DataForSEO 连接正常，当前余额：' . $balanceText;
            }

            if ($action === 'import_suggestions') {
                $seedText = trim((string) ($_POST['seed_keywords'] ?? ''));
                $targetMode = (string) ($_POST['target_mode'] ?? 'existing');
                $libraryId = (int) ($_POST['library_id'] ?? 0);
                $newLibraryName = trim((string) ($_POST['new_library_name'] ?? ''));
                $newLibraryDescription = trim((string) ($_POST['new_library_description'] ?? ''));
                $limit = max(1, (int) ($_POST['limit'] ?? 50));
                $minSearchVolume = max(0, (int) ($_POST['min_search_volume'] ?? 0));
                $locationCode = max(1, (int) ($_POST['location_code'] ?? $dataForSeoConfig['default_location_code']));
                $languageCode = trim((string) ($_POST['language_code'] ?? $dataForSeoConfig['default_language_code']));

                if ($seedText === '') {
                    throw new InvalidArgumentException('请输入种子关键词');
                }

                $seedCount = count(array_unique(array_filter(array_map(
                    static fn($seed) => mb_strtolower(trim((string) $seed)),
                    preg_split('/[\r\n,，]+/u', $seedText, -1, PREG_SPLIT_NO_EMPTY) ?: []
                ))));
                $estimatedCost = SiteSpendGuardService::estimateDataForSeoCost($seedCount, $limit);
                SiteSpendGuardService::assertCanSpend($db, $currentSiteId, SiteSpendGuardService::PROVIDER_DATAFORSEO, $estimatedCost, $dataForSeoDailyBudget);

                if ($targetMode === 'existing') {
                    if ($libraryId <= 0) {
                        throw new InvalidArgumentException('请选择目标关键词库');
                    }
                    $libraryStmt = $db->prepare("SELECT id, name FROM keyword_libraries WHERE id = ?" . ($librarySiteCondition !== '' ? ' AND ' . $librarySiteCondition : ''));
                    $libraryStmt->execute([$libraryId]);
                    $targetLibrary = $libraryStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$targetLibrary) {
                        throw new InvalidArgumentException('目标关键词库不属于当前站点');
                    }
                } else {
                    if ($newLibraryName === '') {
                        throw new InvalidArgumentException('请输入新关键词库名称');
                    }
                }

                $suggestions = $dataForSeo->fetchKeywordSuggestions([$seedText], [
                    'limit' => $limit,
                    'location_code' => $locationCode,
                    'language_code' => $languageCode,
                    'min_search_volume' => $minSearchVolume,
                ]);

                if (empty($suggestions['items'])) {
                    throw new RuntimeException('DataForSEO 本次没有返回可导入关键词，请换种子词或降低筛选条件');
                }

                $db->beginTransaction();

                if ($targetMode !== 'existing') {
                    if (geoflow_table_has_site_column($db, 'keyword_libraries')) {
                        $createStmt = $db->prepare("
                            INSERT INTO keyword_libraries (site_id, name, description, keyword_count, created_at, updated_at)
                            VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $createStmt->execute([$currentSiteId, $newLibraryName, $newLibraryDescription]);
                    } else {
                        $createStmt = $db->prepare("
                            INSERT INTO keyword_libraries (name, description, keyword_count, created_at, updated_at)
                            VALUES (?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $createStmt->execute([$newLibraryName, $newLibraryDescription]);
                    }
                    $libraryId = db_last_insert_id($db, 'keyword_libraries');
                }

                $importResult = material_import_keywords($db, $libraryId, $suggestions['items'], ['source' => 'dataforseo']);
                SiteSpendGuardService::recordSpend($db, $currentSiteId, SiteSpendGuardService::PROVIDER_DATAFORSEO, (float) $suggestions['cost'], [
                    'event_type' => 'keyword_suggestions',
                    'units' => count($suggestions['items']),
                    'description' => 'DataForSEO keyword suggestions import',
                    'metadata' => [
                        'library_id' => $libraryId,
                        'seed_count' => $seedCount,
                        'limit' => $limit,
                        'estimated_cost' => $estimatedCost,
                    ],
                ]);
                $db->commit();
                $dataForSeoTodaySpend += (float) $suggestions['cost'];

                $lastImportSummary = [
                    'library_id' => $libraryId,
                    'requested_seed_count' => (int) $suggestions['requested_seed_count'],
                    'requested_limit' => (int) $suggestions['requested_limit'],
                    'returned_count' => count($suggestions['items']),
                    'cost' => (float) $suggestions['cost'],
                    'import' => $importResult,
                ];

                $message = sprintf(
                    '已从 DataForSEO 拉取 %d 个关键词，新增 %d 个，重复 %d 个，更新指标 %d 个，本次 API 计费约 $%.6f',
                    $lastImportSummary['returned_count'],
                    (int) $importResult['imported'],
                    (int) $importResult['duplicate'],
                    (int) $importResult['updated'],
                    $lastImportSummary['cost']
                );
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

session_write_close();

$libraries = $db->query("
    SELECT kl.id, kl.name, kl.description, kl.keyword_count,
           (SELECT COUNT(*) FROM keywords WHERE library_id = kl.id) AS actual_count
    FROM keyword_libraries kl
    " . ($libraryAliasSiteCondition !== '' ? 'WHERE ' . $libraryAliasSiteCondition : '') . "
    ORDER BY kl.updated_at DESC, kl.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
$defaultTargetMode = (string) ($_POST['target_mode'] ?? (empty($libraries) ? 'new' : 'existing'));

$page_title = 'DataForSEO 关键词自动获取';
$page_header = '
<div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex items-start gap-4">
        <a href="materials.php" class="mt-1 text-slate-400 hover:text-slate-600">
            <i data-lucide="arrow-left" class="h-5 w-5"></i>
        </a>
        <div>
            <div class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">DataForSEO API</div>
            <h1 class="mt-3 text-2xl font-bold text-slate-950">关键词自动获取</h1>
            <p class="mt-1 text-sm text-slate-600">用实时搜索数据生成关键词，导入当前站点素材库。</p>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="keyword-libraries.php" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            <i data-lucide="database" class="mr-2 h-4 w-4"></i>关键词库
        </a>
    </div>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <section class="lg:col-span-2 space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">一键拉取关键词</h2>
                    <p class="mt-1 text-sm text-slate-500">建议先用 1 个种子词、20-50 条结果测试，稳定后再扩大。</p>
                </div>
                <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">按请求计费</span>
            </div>

            <form method="POST" class="mt-6 space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="import_suggestions">

                <div>
                    <label class="block text-sm font-medium text-slate-700">种子关键词</label>
                    <textarea name="seed_keywords" rows="5" required class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="例如：AI prompt tools&#10;AI SEO software&#10;best ChatGPT prompts"><?php echo htmlspecialchars((string) ($_POST['seed_keywords'] ?? '')); ?></textarea>
                    <p class="mt-2 text-xs text-slate-500">支持换行或逗号分隔；当前单次最多 <?php echo (int) $dataForSeoConfig['max_seed_count']; ?> 个种子词。</p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">导入目标</label>
                        <select name="target_mode" data-target-mode class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="existing" <?php echo $defaultTargetMode === 'existing' ? 'selected' : ''; ?>>导入已有关键词库</option>
                            <option value="new" <?php echo $defaultTargetMode === 'new' ? 'selected' : ''; ?>>创建新关键词库</option>
                        </select>
                    </div>
                    <div data-existing-library>
                        <label class="block text-sm font-medium text-slate-700">关键词库</label>
                        <select name="library_id" class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($libraries as $library): ?>
                                <option value="<?php echo (int) $library['id']; ?>" <?php echo (int) ($_POST['library_id'] ?? 0) === (int) $library['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $library['name']); ?> (<?php echo (int) $library['actual_count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div data-new-library class="hidden grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">新库名称</label>
                        <input type="text" name="new_library_name" value="<?php echo htmlspecialchars((string) ($_POST['new_library_name'] ?? '')); ?>" class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="例如：AI SEO Seed Keywords">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">新库描述</label>
                        <input type="text" name="new_library_description" value="<?php echo htmlspecialchars((string) ($_POST['new_library_description'] ?? 'DataForSEO 自动拉取')); ?>" class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="可选">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">国家代码</label>
                        <input type="number" name="location_code" min="1" value="<?php echo htmlspecialchars((string) ($_POST['location_code'] ?? $dataForSeoConfig['default_location_code'])); ?>" class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">默认 2840 = US</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">语言</label>
                        <input type="text" name="language_code" value="<?php echo htmlspecialchars((string) ($_POST['language_code'] ?? $dataForSeoConfig['default_language_code'])); ?>" class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">默认 en</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">每个词结果数</label>
                        <input type="number" name="limit" min="1" max="<?php echo (int) $dataForSeoConfig['max_keyword_limit']; ?>" value="<?php echo htmlspecialchars((string) ($_POST['limit'] ?? 50)); ?>" class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">上限 <?php echo (int) $dataForSeoConfig['max_keyword_limit']; ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">最低搜索量</label>
                        <input type="number" name="min_search_volume" min="0" value="<?php echo htmlspecialchars((string) ($_POST['min_search_volume'] ?? 0)); ?>" class="mt-2 block w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">0 表示不筛选</p>
                    </div>
                </div>

                <div class="flex flex-col gap-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-600 md:flex-row md:items-center md:justify-between">
                <div>
                    当前站点：<span class="font-semibold text-slate-900"><?php echo htmlspecialchars((string) ($currentSite['name'] ?? 'Site')); ?></span>。
                    今日预算：<?php echo $dataForSeoDailyBudget > 0 ? '$' . number_format($dataForSeoDailyBudget, 2) : '不限额'; ?>，
                    已用：$<?php echo number_format($dataForSeoTodaySpend, 4); ?>。
                </div>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>拉取并导入
                    </button>
                </div>
            </form>
        </div>

        <?php if ($lastImportSummary): ?>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-emerald-950">导入结果</h2>
                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="rounded-xl bg-white p-4"><div class="text-xs text-slate-500">返回</div><div class="mt-1 text-2xl font-bold"><?php echo (int) $lastImportSummary['returned_count']; ?></div></div>
                    <div class="rounded-xl bg-white p-4"><div class="text-xs text-slate-500">新增</div><div class="mt-1 text-2xl font-bold text-emerald-700"><?php echo (int) $lastImportSummary['import']['imported']; ?></div></div>
                    <div class="rounded-xl bg-white p-4"><div class="text-xs text-slate-500">重复</div><div class="mt-1 text-2xl font-bold"><?php echo (int) $lastImportSummary['import']['duplicate']; ?></div></div>
                    <div class="rounded-xl bg-white p-4"><div class="text-xs text-slate-500">更新指标</div><div class="mt-1 text-2xl font-bold"><?php echo (int) $lastImportSummary['import']['updated']; ?></div></div>
                    <div class="rounded-xl bg-white p-4"><div class="text-xs text-slate-500">计费</div><div class="mt-1 text-2xl font-bold">$<?php echo number_format((float) $lastImportSummary['cost'], 6); ?></div></div>
                </div>
                <?php if (!empty($lastImportSummary['import']['samples'])): ?>
                    <div class="mt-5 overflow-hidden rounded-xl border border-emerald-100 bg-white">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">关键词</th>
                                    <th class="px-4 py-3">搜索量</th>
                                    <th class="px-4 py-3">CPC</th>
                                    <th class="px-4 py-3">竞争</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($lastImportSummary['import']['samples'] as $sample): ?>
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-slate-900"><?php echo htmlspecialchars((string) $sample['keyword']); ?></td>
                                        <td class="px-4 py-3"><?php echo $sample['search_volume'] !== null ? (int) $sample['search_volume'] : '-'; ?></td>
                                        <td class="px-4 py-3"><?php echo $sample['cpc'] !== null ? '$' . number_format((float) $sample['cpc'], 4) : '-'; ?></td>
                                        <td class="px-4 py-3"><?php echo htmlspecialchars((string) ($sample['competition'] ?: ($sample['competition_index'] ?? '-'))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <aside class="space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">API 状态</h2>
                    <p class="mt-1 text-sm text-slate-500">密钥只从服务器环境变量读取。</p>
                </div>
                <?php if ($dataForSeoConfig['configured']): ?>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">已配置</span>
                <?php else: ?>
                    <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 ring-1 ring-red-200">未配置</span>
                <?php endif; ?>
            </div>

            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-slate-500">API login</dt><dd class="truncate font-medium text-slate-900"><?php echo htmlspecialchars((string) ($dataForSeoConfig['login'] ?: '未设置')); ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">默认国家</dt><dd class="font-medium text-slate-900"><?php echo (int) $dataForSeoConfig['default_location_code']; ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">默认语言</dt><dd class="font-medium text-slate-900"><?php echo htmlspecialchars((string) $dataForSeoConfig['default_language_code']); ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">单次结果上限</dt><dd class="font-medium text-slate-900"><?php echo (int) $dataForSeoConfig['max_keyword_limit']; ?></dd></div>
            </dl>

            <form method="POST" class="mt-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="test_connection">
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="plug-zap" class="mr-2 h-4 w-4"></i>测试连接
                </button>
            </form>

            <?php if ($connectionStatus): ?>
                <div class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">
                    <div>账号：<?php echo htmlspecialchars((string) $connectionStatus['login']); ?></div>
                    <div class="mt-1">余额：<?php echo $connectionStatus['balance'] !== null ? '$' . number_format((float) $connectionStatus['balance'], 4) : '未知'; ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-amber-950">费用保护</h2>
            <ul class="mt-3 space-y-2 text-sm text-amber-900">
                <li>连接测试走免费接口，不扣费。</li>
                <li>关键词建议接口会扣费，默认限制小批量。</li>
                <li>暂不接自动定时任务，避免后台静默消耗余额。</li>
                <li>后续可以按站点加每日预算和一键建站流程。</li>
            </ul>
        </div>
    </aside>
</div>

<script>
    function syncTargetMode() {
        const select = document.querySelector('[data-target-mode]');
        const existing = document.querySelector('[data-existing-library]');
        const created = document.querySelector('[data-new-library]');
        if (!select || !existing || !created) {
            return;
        }
        const isNew = select.value === 'new';
        existing.classList.toggle('hidden', isNew);
        created.classList.toggle('hidden', !isNew);
    }

    document.addEventListener('DOMContentLoaded', function() {
        syncTargetMode();
        const select = document.querySelector('[data-target-mode]');
        if (select) {
            select.addEventListener('change', syncTargetMode);
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
