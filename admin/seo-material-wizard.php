<?php
/**
 * SEO material wizard.
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_engine.php';
require_once __DIR__ . '/../includes/image_seo_service.php';
require_once __DIR__ . '/../includes/knowledge-retrieval.php';
require_once __DIR__ . '/includes/material-library-helpers.php';
require_once __DIR__ . '/includes/seo-material-wizard-helpers.php';

require_admin_login();

$csrf_token = generate_csrf_token();
session_write_close();

$message = '';
$error = '';
$result = null;
$currentSite = geoflow_current_site();
$currentSiteId = geoflow_current_site_id();
$context = seo_material_wizard_collect_context($db, $currentSiteId);
$activeAiModel = seo_material_wizard_fetch_active_ai_model($db);
$keywordCount = count($context['keywords']);
$categoryCount = count($context['categories']);
$articleCount = count($context['articles']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        set_time_limit(300);
        ini_set('memory_limit', '256M');

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException(__('message.csrf_invalid'));
        }

        if (($_POST['action'] ?? '') !== 'generate_bundle') {
            throw new RuntimeException('未知操作');
        }

        $result = seo_material_wizard_create_material_bundle($db, [
            'use_ai' => !empty($_POST['use_ai']),
            'title_count' => (int) ($_POST['title_count'] ?? 24),
            'image_count' => (int) ($_POST['image_count'] ?? 6),
        ]);
        $message = '已生成 SEO 素材包：标题 ' . (int) $result['title_count'] . ' 个，图片素材 ' . (int) $result['image_count'] . ' 张，知识库分块 ' . (int) $result['knowledge_chunks'] . ' 个。';
        $context = seo_material_wizard_collect_context($db, $currentSiteId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'SEO素材助手';
$page_header = '
<div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex items-center gap-4">
        <a href="materials.php" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 hover:text-slate-900">
            <i data-lucide="arrow-left" class="h-5 w-5"></i>
        </a>
        <div>
            <div class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Beginner SEO</div>
            <h1 class="mt-3 text-2xl font-bold text-slate-950">SEO素材助手</h1>
            <p class="mt-1 text-sm text-slate-600">当前站点：' . htmlspecialchars((string) ($currentSite['name'] ?? 'Site')) . '。一键生成标题、图片素材和 AI 知识库。</p>
        </div>
    </div>
    <a href="materials.php" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
        <i data-lucide="folder" class="mr-2 h-4 w-4"></i>返回素材管理
    </a>
</div>';

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($error !== ''): ?>
    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
    <div class="space-y-6">
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 px-8 py-8 text-white">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.28em] text-cyan-200">One Click Material Pack</p>
                        <h2 class="mt-4 text-3xl font-black tracking-tight">不会 SEO，也能先把素材搭起来</h2>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-200">
                            系统会读取当前站点的名称、描述、关键词库、分类和已有文章，生成可直接用于任务的标题库、图片库和知识库。
                            这个动作不会调用 DataForSEO，不会消耗拉词额度。
                        </p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-4 text-sm text-slate-100 shadow-inner">
                        <div class="text-slate-300">当前上下文</div>
                        <div class="mt-2 grid grid-cols-3 gap-3 text-center">
                            <div>
                                <div class="text-2xl font-black"><?php echo $keywordCount; ?></div>
                                <div class="text-xs text-slate-300">关键词</div>
                            </div>
                            <div>
                                <div class="text-2xl font-black"><?php echo $categoryCount; ?></div>
                                <div class="text-xs text-slate-300">分类</div>
                            </div>
                            <div>
                                <div class="text-2xl font-black"><?php echo $articleCount; ?></div>
                                <div class="text-xs text-slate-300">文章</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" class="p-8">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="generate_bundle">

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="rounded-2xl border border-blue-100 bg-blue-50 p-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-600 text-white">
                                <i data-lucide="type" class="h-5 w-5"></i>
                            </div>
                            <div>
                                <div class="font-bold text-slate-950">标题库</div>
                                <div class="text-sm text-blue-900/70">围绕关键词生成长尾标题</div>
                            </div>
                        </div>
                        <label class="mt-5 block text-xs font-semibold text-slate-500">标题数量</label>
                        <input name="title_count" type="number" min="6" max="50" value="24" class="mt-2 block w-full rounded-xl border-blue-100 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div class="rounded-2xl border border-violet-100 bg-violet-50 p-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-600 text-white">
                                <i data-lucide="image" class="h-5 w-5"></i>
                            </div>
                            <div>
                                <div class="font-bold text-slate-950">图片素材</div>
                                <div class="text-sm text-violet-900/70">生成信息图卡片和 alt</div>
                            </div>
                        </div>
                        <label class="mt-5 block text-xs font-semibold text-slate-500">图片数量</label>
                        <input name="image_count" type="number" min="1" max="12" value="6" class="mt-2 block w-full rounded-xl border-violet-100 bg-white px-3 py-2 text-sm focus:border-violet-500 focus:ring-violet-500">
                    </div>

                    <div class="rounded-2xl border border-orange-100 bg-orange-50 p-5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-orange-600 text-white">
                                <i data-lucide="brain" class="h-5 w-5"></i>
                            </div>
                            <div>
                                <div class="font-bold text-slate-950">AI知识库</div>
                                <div class="text-sm text-orange-900/70">写入内容规则和 SEO 边界</div>
                            </div>
                        </div>
                        <label class="mt-5 flex items-start gap-3 rounded-xl bg-white p-3 text-sm text-slate-700">
                            <input type="checkbox" name="use_ai" value="1" <?php echo $activeAiModel ? 'checked' : ''; ?> class="mt-1 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="font-semibold">使用站内 AI 生成/润色</span>
                                <span class="mt-1 block text-xs text-slate-500"><?php echo $activeAiModel ? '已检测到可用 AI 模型。失败会自动兜底。' : '未检测到可用 AI 模型，将使用本地规则生成。'; ?></span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="font-bold text-emerald-950">建议直接点下面按钮</div>
                            <p class="mt-1 text-sm text-emerald-800">默认参数已经按中文精品站配置好：先生成 24 个标题、6 张基础信息图和 1 个站点知识库。</p>
                        </div>
                        <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-blue-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-blue-600/20 hover:bg-blue-700">
                            <i data-lucide="sparkles" class="mr-2 h-5 w-5"></i>
                            一键生成 SEO 素材包
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <?php if (is_array($result)): ?>
            <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-6">
                <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-600 text-white">
                        <i data-lucide="check" class="h-5 w-5"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-lg font-bold text-emerald-950">素材包已可使用</h3>
                        <p class="mt-1 text-sm text-emerald-800"><?php echo htmlspecialchars((string) $result['message']); ?></p>
                        <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
                            <a href="title-library-detail.php?id=<?php echo (int) $result['title_library_id']; ?>" class="rounded-2xl bg-white p-4 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                                标题库：<?php echo (int) $result['title_count']; ?> 个
                            </a>
                            <a href="image-library-detail.php?id=<?php echo (int) $result['image_library_id']; ?>" class="rounded-2xl bg-white p-4 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                                图片库：<?php echo (int) $result['image_count']; ?> 张
                            </a>
                            <a href="knowledge-base-detail.php?id=<?php echo (int) $result['knowledge_base_id']; ?>" class="rounded-2xl bg-white p-4 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                                知识库：<?php echo (int) $result['knowledge_chunks']; ?> 个分块
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <aside class="space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-bold text-slate-950">它具体做什么？</h3>
            <div class="mt-5 space-y-4 text-sm text-slate-600">
                <div class="flex gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700">1</span>
                    <p>读取当前站点资料和关键词库，不需要你手写 SEO 策略。</p>
                </div>
                <div class="flex gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-700">2</span>
                    <p>自动生成标题库、可用配图、图片 alt/caption 和 AI 知识库。</p>
                </div>
                <div class="flex gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700">3</span>
                    <p>创建任务时直接选择这些素材库，就能进入审核式内容生成流程。</p>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-bold text-slate-950">检测到的站点信息</h3>
            <dl class="mt-4 space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">站点名称</dt>
                    <dd class="mt-1 font-semibold text-slate-900"><?php echo htmlspecialchars($context['site_name']); ?></dd>
                </div>
                <div>
                    <dt class="text-slate-500">站点标题</dt>
                    <dd class="mt-1 font-semibold text-slate-900"><?php echo htmlspecialchars($context['site_title']); ?></dd>
                </div>
                <div>
                    <dt class="text-slate-500">主域名</dt>
                    <dd class="mt-1 break-all font-semibold text-slate-900"><?php echo htmlspecialchars($context['primary_domain'] !== '' ? $context['primary_domain'] : '未配置'); ?></dd>
                </div>
            </dl>
        </section>

        <section class="rounded-3xl border border-amber-200 bg-amber-50 p-6">
            <h3 class="text-base font-bold text-amber-950">费用说明</h3>
            <p class="mt-3 text-sm leading-6 text-amber-800">
                这个页面不会调用 DataForSEO，不会重复扣关键词费用。只有勾选“使用站内 AI”时，才会调用你已经配置的 AI 模型一次；失败会自动使用本地规则兜底。
            </p>
        </section>
    </aside>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
