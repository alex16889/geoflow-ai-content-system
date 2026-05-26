<?php
/**
 * SEO/GEO operations workbench.
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/seo_functions.php';
require_once __DIR__ . '/../includes/seo_geo_audit_service.php';
require_once __DIR__ . '/../includes/visibility_tracking_service.php';
require_once __DIR__ . '/../includes/content_research_service.php';
require_once __DIR__ . '/../includes/internal_link_service.php';
require_once __DIR__ . '/../includes/redirect_service.php';

require_admin_login();

$page_title = 'SEO/GEO 工作台';
$message = '';
$error = '';
$current_admin = get_current_admin();
$site_id = geoflow_current_site_id();
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            switch ($action) {
                case 'save_search_snapshot':
                    VisibilityTrackingService::saveSearchSnapshot($db, $site_id, $_POST);
                    $message = '搜索表现快照已保存';
                    break;

                case 'save_ai_check':
                    VisibilityTrackingService::saveAiCheck($db, $site_id, $_POST);
                    $message = 'AI 可见性记录已保存';
                    break;

                case 'save_competitor_brief':
                    ContentResearchService::saveCompetitorBrief($db, $site_id, $_POST, (int) ($current_admin['id'] ?? 0));
                    $message = '竞品简报已保存';
                    break;

                case 'save_redirect_rule':
                    RedirectService::saveRule($db, $site_id, $_POST);
                    $message = '重定向规则已保存';
                    break;

                case 'delete_redirect_rule':
                    RedirectService::deleteRule($db, $site_id, (int) ($_POST['rule_id'] ?? 0));
                    $message = '重定向规则已删除';
                    break;
            }
        } catch (Throwable $e) {
            $error = '保存失败：' . $e->getMessage();
        }
    }
}

session_write_close();

$audit = SeoGeoAuditService::audit($db);
$site = $audit['site'];
$stats = $audit['stats'];
$recent_search = VisibilityTrackingService::recentSearchSnapshots($db, $site_id, 6);
$recent_ai = VisibilityTrackingService::recentAiChecks($db, $site_id, 6);
$competitor_briefs = ContentResearchService::recentCompetitorBriefs($db, $site_id, 6);
$internal_links = InternalLinkService::opportunities($db, $site_id, 6);
$redirect_rules = RedirectService::rules($db, $site_id, 8);
$not_found_logs = RedirectService::recent404($db, $site_id, 8);

function seo_geo_status_class(string $status): string {
    return $status === 'pass'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : 'border-amber-200 bg-amber-50 text-amber-800';
}

function seo_geo_status_label(string $status): string {
    return $status === 'pass' ? '已就绪' : '待优化';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-8">
    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 text-white shadow-sm">
        <div class="grid gap-6 px-6 py-8 lg:grid-cols-[1.4fr_0.6fr] lg:px-8">
            <div>
                <div class="inline-flex items-center rounded-full border border-cyan-300/30 bg-cyan-300/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-cyan-100">
                    SEO/GEO Operations
                </div>
                <h1 class="mt-5 text-3xl font-semibold tracking-tight">SEO/GEO 工作台</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                    当前站点：<?php echo htmlspecialchars((string) ($site['name'] ?? 'Default Site')); ?>。这里不自动消耗 DataForSEO 或搜索 API 额度，只基于本地配置、内容和手动记录做 readiness 判断。
                </p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <a href="<?php echo htmlspecialchars(geo_absolute_url('sitemap.xml')); ?>" target="_blank" rel="noopener noreferrer" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm hover:bg-white/10">
                        <div class="text-slate-400">Sitemap</div>
                        <div class="mt-1 truncate text-cyan-100">/sitemap.xml</div>
                    </a>
                    <a href="<?php echo htmlspecialchars(geo_absolute_url('llms.txt')); ?>" target="_blank" rel="noopener noreferrer" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm hover:bg-white/10">
                        <div class="text-slate-400">LLM Map</div>
                        <div class="mt-1 truncate text-cyan-100">/llms.txt</div>
                    </a>
                    <a href="<?php echo htmlspecialchars(admin_url('site-settings.php')); ?>" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm hover:bg-white/10">
                        <div class="text-slate-400">Provider</div>
                        <div class="mt-1 truncate text-cyan-100"><?php echo htmlspecialchars(implode(', ', $stats['providers']) ?: '未配置'); ?></div>
                    </a>
                </div>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/10 p-6">
                <div class="text-sm text-slate-300">Readiness Score</div>
                <div class="mt-3 text-6xl font-semibold"><?php echo (int) $audit['score']; ?><span class="text-2xl text-slate-400">/100</span></div>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-cyan-300" style="width: <?php echo (int) $audit['score']; ?>%"></div>
                </div>
                <p class="mt-4 text-xs leading-5 text-slate-300">分数只做运营提醒，真正关键是下面每一项是否有可执行动作和真实数据。</p>
            </div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="grid gap-5 md:grid-cols-4">
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">已发布文章</div>
            <div class="mt-2 text-3xl font-semibold"><?php echo (int) $stats['content']['published_articles']; ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">关键词指标</div>
            <div class="mt-2 text-3xl font-semibold"><?php echo (int) $stats['content']['keywords_with_metrics']; ?></div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">图片 Alt 覆盖</div>
            <div class="mt-2 text-3xl font-semibold"><?php echo htmlspecialchars((string) $stats['images']['coverage']); ?>%</div>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="text-sm text-slate-500">AI 可见性记录</div>
            <div class="mt-2 text-3xl font-semibold"><?php echo (int) $stats['visibility']['ai_checks']; ?></div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <?php foreach ($audit['checks'] as $groupName => $checks): ?>
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-slate-950"><?php echo htmlspecialchars(ucfirst($groupName)); ?></h2>
                <div class="mt-5 space-y-3">
                    <?php foreach ($checks as $check): ?>
                        <div class="rounded-2xl border px-4 py-3 <?php echo seo_geo_status_class($check['status']); ?>">
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-medium"><?php echo htmlspecialchars($check['label']); ?></div>
                                <span class="shrink-0 rounded-full bg-white/70 px-2.5 py-1 text-xs font-semibold"><?php echo seo_geo_status_label($check['status']); ?></span>
                            </div>
                            <?php if (($check['value'] ?? '') !== ''): ?>
                                <div class="mt-1 break-all text-xs opacity-80"><?php echo htmlspecialchars((string) $check['value']); ?></div>
                            <?php endif; ?>
                            <p class="mt-2 text-sm leading-5 opacity-90"><?php echo htmlspecialchars($check['action']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">搜索表现快照</h2>
            <form method="POST" class="mt-5 space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_search_snapshot">
                <select name="source" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="google_search_console">Google Search Console</option>
                    <option value="bing_webmaster">Bing Webmaster</option>
                    <option value="manual">Manual</option>
                </select>
                <input type="date" name="snapshot_date" value="<?php echo date('Y-m-d'); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <input type="text" name="query" placeholder="查询词 / keyword" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <input type="url" name="page_url" placeholder="页面 URL" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <div class="grid grid-cols-3 gap-2">
                    <input type="number" name="clicks" min="0" placeholder="点击" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <input type="number" name="impressions" min="0" placeholder="展现" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <input type="number" step="0.01" name="avg_position" min="0" placeholder="排名" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                </div>
                <button type="submit" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white">保存快照</button>
            </form>
            <div class="mt-5 space-y-2 text-sm">
                <?php foreach ($recent_search as $row): ?>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="font-medium"><?php echo htmlspecialchars((string) ($row['query'] ?: '(no query)')); ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars((string) $row['source']); ?> · <?php echo htmlspecialchars((string) $row['snapshot_date']); ?> · <?php echo (int) $row['clicks']; ?>/<?php echo (int) $row['impressions']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">AI 答案可见性</h2>
            <form method="POST" class="mt-5 space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_ai_check">
                <select name="provider" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <?php foreach (['ChatGPT', 'Perplexity', 'Gemini', 'Claude', 'Grok', 'AI Overview', 'Other'] as $provider): ?>
                        <option value="<?php echo htmlspecialchars($provider); ?>"><?php echo htmlspecialchars($provider); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="query" required placeholder="测试问题 / prompt" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <input type="url" name="citation_url" placeholder="引用 URL，可空" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <textarea name="answer_excerpt" rows="3" placeholder="答案摘要" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                <div class="flex items-center justify-between gap-3">
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="brand_mentioned" value="1" class="rounded border-slate-300"> 有提及/引用本站</label>
                    <input type="number" name="visibility_score" min="0" max="100" placeholder="0-100" class="w-24 rounded-xl border border-slate-300 px-3 py-2 text-sm">
                </div>
                <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white">保存记录</button>
            </form>
            <div class="mt-5 space-y-2 text-sm">
                <?php foreach ($recent_ai as $row): ?>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="font-medium"><?php echo htmlspecialchars((string) $row['query']); ?></div>
                        <div class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars((string) $row['provider']); ?> · <?php echo !empty($row['brand_mentioned']) ? '已提及' : '未提及'; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">竞品内容简报</h2>
            <form method="POST" class="mt-5 space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_competitor_brief">
                <input type="text" name="seed_keyword" required placeholder="核心关键词" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <input type="url" name="competitor_url" placeholder="竞品 URL" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <input type="text" name="competitor_title" placeholder="竞品标题" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <textarea name="notes" rows="4" placeholder="搜索意图、内容缺口、自己的切入点" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                <button type="submit" class="w-full rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white">保存简报</button>
            </form>
            <div class="mt-5 space-y-2 text-sm">
                <?php foreach ($competitor_briefs as $brief): ?>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <div class="font-medium"><?php echo htmlspecialchars((string) $brief['seed_keyword']); ?></div>
                        <div class="mt-1 truncate text-xs text-slate-500"><?php echo htmlspecialchars((string) ($brief['competitor_title'] ?: $brief['competitor_url'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">重定向规则</h2>
            <form method="POST" class="mt-5 grid gap-3 lg:grid-cols-[1fr_1fr_auto_auto]">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_redirect_rule">
                <input type="text" name="source_path" placeholder="/old-url" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <input type="text" name="target_url" placeholder="/new-url 或 https://..." class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <select name="status_code" class="rounded-xl border border-slate-300 px-3 py-2 text-sm"><option value="301">301</option><option value="302">302</option></select>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300"> 启用</label>
                <button type="submit" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white lg:col-span-4">保存规则</button>
            </form>
            <div class="mt-5 space-y-2 text-sm">
                <?php foreach ($redirect_rules as $rule): ?>
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium"><?php echo htmlspecialchars((string) $rule['source_path']); ?> → <?php echo htmlspecialchars((string) $rule['target_url']); ?></div>
                            <div class="mt-1 text-xs text-slate-500"><?php echo (int) $rule['status_code']; ?> · hits <?php echo (int) $rule['hit_count']; ?> · <?php echo !empty($rule['is_active']) ? 'active' : 'paused'; ?></div>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="delete_redirect_rule">
                            <input type="hidden" name="rule_id" value="<?php echo (int) $rule['id']; ?>">
                            <button type="submit" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-600">删除</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">404 与内链机会</h2>
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div>
                    <div class="text-sm font-medium text-slate-700">最近 404</div>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($not_found_logs as $log): ?>
                            <div class="rounded-xl bg-red-50 p-3 text-sm text-red-800">
                                <div class="truncate font-medium"><?php echo htmlspecialchars((string) $log['path']); ?></div>
                                <div class="mt-1 text-xs opacity-80">hits <?php echo (int) $log['hit_count']; ?> · <?php echo htmlspecialchars((string) $log['last_seen_at']); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($not_found_logs)): ?>
                            <div class="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">暂无 404 记录</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="text-sm font-medium text-slate-700">内链建议</div>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($internal_links as $opportunity): ?>
                            <div class="rounded-xl bg-blue-50 p-3 text-sm text-blue-900">
                                <div class="font-medium"><?php echo htmlspecialchars((string) ($opportunity['source']['title'] ?? '')); ?></div>
                                <div class="mt-1 text-xs opacity-80">建议链接到：<?php echo htmlspecialchars(implode('、', array_map(static fn($target) => (string) $target['title'], $opportunity['targets']))); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($internal_links)): ?>
                            <div class="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">发布更多同分类文章后会出现建议</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
