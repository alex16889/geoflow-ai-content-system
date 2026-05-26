<?php
/**
 * 智能GEO内容系统 - 网站设置
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/theme_preview.php';
require_once __DIR__ . '/../includes/search_submission_service.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

// 设置页面标题
$page_title = __('site_settings.page_title');

$message = '';
$error = '';
$available_themes = geoflow_discover_themes();

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'update_site_settings':
                $site_name = trim($_POST['site_name'] ?? '');
                $site_subtitle = trim($_POST['site_subtitle'] ?? '');
                $site_description = trim($_POST['site_description'] ?? '');
                $site_keywords = trim($_POST['site_keywords'] ?? '');
                $copyright_info = trim($_POST['copyright_info'] ?? '');
                $site_logo = trim($_POST['site_logo'] ?? '');
                $site_favicon = trim($_POST['site_favicon'] ?? '');
                $analytics_code = trim($_POST['analytics_code'] ?? '');
                $seo_title_template = trim($_POST['seo_title_template'] ?? '');
                $seo_description_template = trim($_POST['seo_description_template'] ?? '');
                $featured_limit = max(1, intval($_POST['featured_limit'] ?? 6));
                $per_page = max(1, intval($_POST['per_page'] ?? 12));
                $quality_gate_enabled = !empty($_POST['quality_gate_enabled']) ? '1' : '0';
                $quality_gate_min_score = (string) max(0, min(100, intval($_POST['quality_gate_min_score'] ?? 65)));
                $quality_gate_min_words = (string) max(50, intval($_POST['quality_gate_min_words'] ?? 300));
                $indexnow_enabled = !empty($_POST['indexnow_enabled']) ? '1' : '0';
                $indexnow_key = trim((string) ($_POST['indexnow_key'] ?? ''));
                $bing_url_submission_enabled = !empty($_POST['bing_url_submission_enabled']) ? '1' : '0';
                $bing_url_submission_api_key_input = trim((string) ($_POST['bing_url_submission_api_key'] ?? ''));
                $bing_url_submission_api_key = !empty($_POST['clear_bing_url_submission_api_key'])
                    ? ''
                    : ($bing_url_submission_api_key_input !== '' ? $bing_url_submission_api_key_input : (string) get_setting('bing_url_submission_api_key', ''));
                $baidu_url_submission_enabled = !empty($_POST['baidu_url_submission_enabled']) ? '1' : '0';
                $baidu_url_submission_endpoint_input = trim((string) ($_POST['baidu_url_submission_endpoint'] ?? ''));
                $baidu_url_submission_endpoint = !empty($_POST['clear_baidu_url_submission_endpoint'])
                    ? ''
                    : ($baidu_url_submission_endpoint_input !== '' ? $baidu_url_submission_endpoint_input : (string) get_setting('baidu_url_submission_endpoint', ''));
                $dataforseo_daily_budget_usd = (string) max(0, (float) ($_POST['dataforseo_daily_budget_usd'] ?? 5));

                if (empty($site_name)) {
                    $error = __('site_settings.error.site_name_required');
                } elseif ($indexnow_key !== '' && !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $indexnow_key)) {
                    $error = 'IndexNow Key 只能包含字母、数字、下划线或短横线，长度 8-128 位';
                } elseif ($bing_url_submission_enabled === '1' && $bing_url_submission_api_key === '') {
                    $error = '启用 Bing URL Submission 前，请先填写 Bing Webmaster API Key';
                } elseif ($baidu_url_submission_enabled === '1' && !SearchSubmissionService::isValidBaiduEndpoint($baidu_url_submission_endpoint)) {
                    $error = '启用百度主动推送前，请粘贴百度搜索资源平台提供的完整 API 提交地址';
                } elseif ($baidu_url_submission_endpoint !== '' && !SearchSubmissionService::isValidBaiduEndpoint($baidu_url_submission_endpoint)) {
                    $error = '百度 API 提交地址格式不正确，应来自 data.zz.baidu.com/urls 并包含 site 和 token';
                } else {
                    try {
                        // 更新网站设置
                        $settings = [
                            'site_name' => $site_name,
                            'site_title' => $site_name,
                            'site_subtitle' => $site_subtitle,
                            'site_description' => $site_description,
                            'site_keywords' => $site_keywords,
                            'copyright_info' => $copyright_info,
                            'site_logo' => $site_logo,
                            'site_favicon' => $site_favicon,
                            'analytics_code' => $analytics_code,
                            'seo_title_template' => $seo_title_template,
                            'seo_description_template' => $seo_description_template,
                            'featured_limit' => (string) $featured_limit,
                            'per_page' => (string) $per_page,
                            'quality_gate_enabled' => $quality_gate_enabled,
                            'quality_gate_min_score' => $quality_gate_min_score,
                            'quality_gate_min_words' => $quality_gate_min_words,
                            'indexnow_enabled' => $indexnow_enabled,
                            'indexnow_key' => $indexnow_key,
                            'bing_url_submission_enabled' => $bing_url_submission_enabled,
                            'bing_url_submission_api_key' => $bing_url_submission_api_key,
                            'baidu_url_submission_enabled' => $baidu_url_submission_enabled,
                            'baidu_url_submission_endpoint' => $baidu_url_submission_endpoint,
                            'dataforseo_daily_budget_usd' => $dataforseo_daily_budget_usd
                        ];

                        foreach ($settings as $key => $value) {
                            if (!set_setting($key, $value)) {
                                throw new RuntimeException('failed_to_save_' . $key);
                            }
                        }

                        $message = __('site_settings.message.saved');
                    } catch (Exception $e) {
                        $error = __('site_settings.message.save_error', ['message' => $e->getMessage()]);
                    }
                }
                break;

            case 'update_article_detail_ads':
                $postedAds = $_POST['ads'] ?? [];
                if (!is_array($postedAds)) {
                    $postedAds = [];
                }

                $ads = [];
                $validationError = '';
                foreach ($postedAds as $index => $postedAd) {
                    if (!is_array($postedAd)) {
                        continue;
                    }

                    $name = trim((string) ($postedAd['name'] ?? ''));
                    $badge = trim((string) ($postedAd['badge'] ?? ''));
                    $title = trim((string) ($postedAd['title'] ?? ''));
                    $copy = trim((string) ($postedAd['copy'] ?? ''));
                    $buttonText = trim((string) ($postedAd['button_text'] ?? ''));
                    $buttonUrl = normalize_cta_target_url((string) ($postedAd['button_url'] ?? ''));
                    $enabled = !empty($postedAd['enabled']);
                    $id = trim((string) ($postedAd['id'] ?? ''));

                    if ($name === '' && $badge === '' && $title === '' && $copy === '' && $buttonText === '' && $buttonUrl === '') {
                        continue;
                    }

                    if ($copy === '' || $buttonText === '' || $buttonUrl === '') {
                        $validationError = __('site_settings.ads.validation_required', ['index' => $index + 1]);
                        break;
                    }

                    $ads[] = [
                        'id' => $id !== '' ? $id : uniqid('article_ad_', true),
                        'name' => $name !== '' ? $name : __('site_settings.ads.default_name', ['index' => count($ads) + 1]),
                        'badge' => $badge,
                        'title' => $title,
                        'copy' => $copy,
                        'button_text' => $buttonText,
                        'button_url' => $buttonUrl,
                        'enabled' => $enabled
                    ];
                }

                if ($validationError !== '') {
                    $error = $validationError;
                } elseif (!set_setting('article_detail_ads', json_encode($ads, JSON_UNESCAPED_UNICODE))) {
                    $error = __('site_settings.ads.save_failed');
                } else {
                    $message = __('site_settings.ads.saved');
                }
                break;

            case 'update_theme_settings':
                $selected_theme = trim((string) ($_POST['active_theme'] ?? ''));
                $allowed_theme_ids = array_column($available_themes, 'id');

                if ($selected_theme !== '' && !in_array($selected_theme, $allowed_theme_ids, true)) {
                    $error = __('site_settings.theme.invalid_selection');
                    break;
                }

                if (set_setting('active_theme', $selected_theme)) {
                    $message = $selected_theme === ''
                        ? __('site_settings.theme.message.default_enabled')
                        : __('site_settings.theme.message.activated', ['name' => $selected_theme]);
                } else {
                    $error = __('site_settings.theme.message.save_failed');
                }
                break;
        }
    }
}

// 设置默认值
$defaults = [
    'site_name' => '智能GEO内容系统',
    'site_subtitle' => '',
    'site_description' => '基于AI的智能内容生成与发布平台',
    'site_keywords' => 'AI内容生成,GEO优化,智能发布,内容管理',
    'copyright_info' => '© 2024 智能GEO内容系统. All rights reserved.',
    'site_logo' => '',
    'site_favicon' => '',
    'analytics_code' => '',
    'seo_title_template' => '{title} - {site_name}',
    'seo_description_template' => '{description}',
    'featured_limit' => '6',
    'per_page' => '12',
    'quality_gate_enabled' => '1',
    'quality_gate_min_score' => '65',
    'quality_gate_min_words' => '300',
    'indexnow_enabled' => '0',
    'indexnow_key' => '',
    'bing_url_submission_enabled' => '0',
    'bing_url_submission_api_key' => '',
    'baidu_url_submission_enabled' => '0',
    'baidu_url_submission_endpoint' => '',
    'dataforseo_daily_budget_usd' => '5',
    'article_detail_ads' => '[]',
    'active_theme' => ''
];

$current_site = geoflow_current_site();
$current_settings = [];
foreach ($defaults as $key => $default_value) {
    $current_settings[$key] = get_setting($key, $default_value);
}
$discovery_sitemap_url = SearchSubmissionService::sitemapUrlForSite($current_site ?: []);
$discovery_robots_url = SearchSubmissionService::robotsUrlForSite($current_site ?: []);
$discovery_base_url = IndexNowService::publicBaseUrlForSite($current_site ?: []);
$has_public_discovery_domain = IndexNowService::isSubmittableBaseUrl($discovery_base_url);
$bing_key_configured = trim((string) ($current_settings['bing_url_submission_api_key'] ?? '')) !== '';
$baidu_endpoint_configured = trim((string) ($current_settings['baidu_url_submission_endpoint'] ?? '')) !== '';

$article_detail_ads = json_decode($current_settings['article_detail_ads'] ?? '[]', true);
if (!is_array($article_detail_ads)) {
    $article_detail_ads = [];
}

// 包含统一头部
require_once __DIR__ . '/includes/header.php';
?>

            <!-- 页面标题 -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900"><?php echo __('site_settings.page_title'); ?></h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.page_subtitle'); ?></p>
                <p class="mt-2 text-xs text-gray-500">当前站点：<?php echo htmlspecialchars((string) ($current_site['name'] ?? 'Default Site')); ?></p>
            </div>

            <!-- 消息提示 -->
            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-5 h-5 text-green-500 mr-2"></i>
                        <span class="text-green-700"><?php echo htmlspecialchars($message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 mr-2"></i>
                        <span class="text-red-700"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <style>
                .settings-accordion > summary {
                    list-style: none;
                }

                .settings-accordion > summary::-webkit-details-marker {
                    display: none;
                }

                .settings-accordion .accordion-chevron {
                    transition: transform 0.2s ease;
                }

                .settings-accordion[open] .accordion-chevron {
                    transform: rotate(180deg);
                }
            </style>

            <div class="space-y-6">
            <!-- 网站设置表单 -->
            <details class="settings-accordion bg-white shadow rounded-lg">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('site_settings.section_basic'); ?></h3>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.page_subtitle'); ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-gray-400"></i>
                </summary>
                <div class="px-6 py-6 border-t border-gray-200">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="update_site_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                        <!-- 基本信息 -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_site_name'); ?></label>
                                <input type="text" name="site_name" required
                                       value="<?php echo htmlspecialchars($current_settings['site_name']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_site_name')); ?>">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_logo'); ?></label>
                                <input type="url" name="site_logo"
                                       value="<?php echo htmlspecialchars($current_settings['site_logo']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="https://example.com/logo.png">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_description'); ?></label>
                            <textarea name="site_description" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_description')); ?>"><?php echo htmlspecialchars($current_settings['site_description']); ?></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_subtitle'); ?></label>
                            <input type="text" name="site_subtitle"
                                   value="<?php echo htmlspecialchars($current_settings['site_subtitle']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_subtitle')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_keywords'); ?></label>
                            <input type="text" name="site_keywords"
                                   value="<?php echo htmlspecialchars($current_settings['site_keywords']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_keywords')); ?>">
                            <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.keywords_help'); ?></p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_copyright'); ?></label>
                            <input type="text" name="copyright_info"
                                   value="<?php echo htmlspecialchars($current_settings['copyright_info']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="© 2024 Site Name. All rights reserved.">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_featured_limit'); ?></label>
                                <input type="number" name="featured_limit" min="1"
                                       value="<?php echo htmlspecialchars($current_settings['featured_limit']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="6">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_per_page'); ?></label>
                                <input type="number" name="per_page" min="1"
                                       value="<?php echo htmlspecialchars($current_settings['per_page']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="12">
                            </div>
                        </div>

                        <!-- SEO设置 -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('site_settings.section_seo'); ?></h4>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_seo_title_template'); ?></label>
                                    <input type="text" name="seo_title_template"
                                           value="<?php echo htmlspecialchars($current_settings['seo_title_template']); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="{title} - {site_name}">
                                    <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.seo_title_help'); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_seo_description_template'); ?></label>
                                    <input type="text" name="seo_description_template"
                                           value="<?php echo htmlspecialchars($current_settings['seo_description_template']); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="{description}">
                                    <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.seo_description_help'); ?></p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_favicon'); ?></label>
                                    <input type="url" name="site_favicon"
                                           value="<?php echo htmlspecialchars($current_settings['site_favicon']); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="https://example.com/favicon.ico">
                                </div>
                            </div>
                        </div>

                        <!-- 增长自动化护栏 -->
                        <div class="border-t border-gray-200 pt-6">
                            <h4 class="text-lg font-medium text-gray-900 mb-4">增长自动化护栏</h4>
                            <div class="grid gap-6 md:grid-cols-3">
                                <label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <input type="checkbox" name="quality_gate_enabled" value="1" <?php echo $current_settings['quality_gate_enabled'] === '1' ? 'checked' : ''; ?> class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block text-sm font-medium text-gray-900">启用发布质量门槛</span>
                                        <span class="mt-1 block text-xs text-gray-500">低分文章会保留为草稿/待审核，避免自动发布低质内容。</span>
                                    </span>
                                </label>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">最低质量分</label>
                                    <input type="number" name="quality_gate_min_score" min="0" max="100" value="<?php echo htmlspecialchars($current_settings['quality_gate_min_score']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <p class="mt-1 text-xs text-gray-500">建议 60-75；越高越严格。</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">最低正文词数</label>
                                    <input type="number" name="quality_gate_min_words" min="50" value="<?php echo htmlspecialchars($current_settings['quality_gate_min_words']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <p class="mt-1 text-xs text-gray-500">英文站建议 300 起步，精品页可提高。</p>
                                </div>
                            </div>

                            <div class="mt-8 rounded-2xl border border-blue-100 bg-blue-50/60 p-5">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <h5 class="text-base font-semibold text-gray-900">站点地图与搜索推送</h5>
                                        <p class="mt-1 text-sm text-gray-600">每个精品站单独配置。发布或更新文章时，只会推送你启用的通道。</p>
                                    </div>
                                    <?php if (!$has_public_discovery_domain): ?>
                                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800">未配置真实公网主域名，外部推送会自动跳过</span>
                                    <?php else: ?>
                                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800">公网主域名已配置</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div class="rounded-xl border border-blue-100 bg-white p-4">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-blue-600">Sitemap</div>
                                        <div class="mt-2 break-all font-mono text-sm text-gray-900"><?php echo htmlspecialchars($discovery_sitemap_url); ?></div>
                                    </div>
                                    <div class="rounded-xl border border-blue-100 bg-white p-4">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-blue-600">Robots</div>
                                        <div class="mt-2 break-all font-mono text-sm text-gray-900"><?php echo htmlspecialchars($discovery_robots_url); ?></div>
                                    </div>
                                </div>

                                <div class="mt-5 grid gap-5 xl:grid-cols-4">
                                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                                        <label class="flex items-start gap-3">
                                            <input type="checkbox" name="indexnow_enabled" value="1" <?php echo $current_settings['indexnow_enabled'] === '1' ? 'checked' : ''; ?> class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span>
                                                <span class="block text-sm font-medium text-gray-900">IndexNow 通用推送</span>
                                                <span class="mt-1 block text-xs text-gray-500">推荐。一个入口通知支持 IndexNow 的搜索引擎。</span>
                                            </span>
                                        </label>
                                        <label class="mt-4 block text-sm font-medium text-gray-700">IndexNow Key</label>
                                        <input type="text" name="indexnow_key" value="<?php echo htmlspecialchars($current_settings['indexnow_key']); ?>" class="mt-2 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="8-128 位字母数字或 _-">
                                        <p class="mt-1 text-xs text-gray-500">系统会通过 /{key}.txt 输出验证文件。</p>
                                    </div>

                                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                                        <label class="flex items-start gap-3">
                                            <input type="checkbox" name="bing_url_submission_enabled" value="1" <?php echo $current_settings['bing_url_submission_enabled'] === '1' ? 'checked' : ''; ?> class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span>
                                                <span class="block text-sm font-medium text-gray-900">Bing URL Submission</span>
                                                <span class="mt-1 block text-xs text-gray-500">需要站点已在 Bing Webmaster Tools 验证。</span>
                                            </span>
                                        </label>
                                        <label class="mt-4 block text-sm font-medium text-gray-700">Bing API Key</label>
                                        <input type="password" name="bing_url_submission_api_key" value="" class="mt-2 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="<?php echo $bing_key_configured ? '已配置，留空保留' : '粘贴 Bing Webmaster API Key'; ?>">
                                        <?php if ($bing_key_configured): ?>
                                            <label class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                                                <input type="checkbox" name="clear_bing_url_submission_api_key" value="1" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                清除当前 Bing Key
                                            </label>
                                        <?php endif; ?>
                                    </div>

                                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                                        <label class="flex items-start gap-3">
                                            <input type="checkbox" name="baidu_url_submission_enabled" value="1" <?php echo $current_settings['baidu_url_submission_enabled'] === '1' ? 'checked' : ''; ?> class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span>
                                                <span class="block text-sm font-medium text-gray-900">百度主动推送</span>
                                                <span class="mt-1 block text-xs text-gray-500">粘贴百度搜索资源平台给你的完整 API 提交地址。</span>
                                            </span>
                                        </label>
                                        <label class="mt-4 block text-sm font-medium text-gray-700">百度 API 提交地址</label>
                                        <input type="password" name="baidu_url_submission_endpoint" value="" class="mt-2 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="<?php echo $baidu_endpoint_configured ? '已配置，留空保留' : 'https://data.zz.baidu.com/urls?site=...&token=...'; ?>">
                                        <?php if ($baidu_endpoint_configured): ?>
                                            <label class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                                                <input type="checkbox" name="clear_baidu_url_submission_endpoint" value="1" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                                清除当前百度接口
                                            </label>
                                        <?php endif; ?>
                                    </div>

                                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                                        <div class="text-sm font-medium text-gray-900">Google</div>
                                        <p class="mt-2 text-xs leading-5 text-gray-500">普通文章不接 Google Indexing API。当前稳定做法是生成 sitemap，并在 Google Search Console 验证站点后提交 sitemap。</p>
                                        <div class="mt-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-600">后续如果做 JobPosting 或直播结构化页面，再单独接 Google Indexing API。</div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 grid gap-6 md:grid-cols-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">DataForSEO 日预算 USD</label>
                                    <input type="number" name="dataforseo_daily_budget_usd" min="0" step="0.01" value="<?php echo htmlspecialchars($current_settings['dataforseo_daily_budget_usd']); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <p class="mt-1 text-xs text-gray-500">0 表示不限额；仍会记录实际花费。</p>
                                </div>
                            </div>

                            <div class="border-t border-gray-200 pt-6">
                                <h4 class="text-lg font-medium text-gray-900 mb-4"><?php echo __('site_settings.section_analytics'); ?></h4>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.field_analytics'); ?></label>
                                    <textarea name="analytics_code" rows="4"
                                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                                              placeholder="<?php echo htmlspecialchars(__('site_settings.placeholder_analytics')); ?>"><?php echo htmlspecialchars($current_settings['analytics_code']); ?></textarea>
                                    <p class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.analytics_help'); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- 提交按钮 -->
                        <div class="flex justify-end pt-6 border-t border-gray-200">
                            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                                <?php echo __('site_settings.save_settings'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </details>

            <details class="settings-accordion bg-white shadow rounded-lg">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('site_settings.theme.section_title'); ?></h3>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.theme.section_desc'); ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-gray-400"></i>
                </summary>
                <div class="px-6 py-6 border-t border-gray-200">
                    <form method="POST" class="space-y-5">
                        <input type="hidden" name="action" value="update_theme_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                        <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4 flex flex-col gap-1">
                            <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.theme.current_label'); ?></div>
                            <div class="text-base font-semibold text-gray-900">
                                <?php
                                $currentThemeLabel = __('site_settings.theme.default_name');
                                foreach ($available_themes as $themeOption) {
                                    if ($themeOption['id'] === $current_settings['active_theme']) {
                                        $currentThemeLabel = $themeOption['name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($currentThemeLabel);
                                ?>
                            </div>
                            <div class="text-xs text-gray-500"><?php echo __('site_settings.theme.current_help'); ?></div>
                        </div>

                        <div class="space-y-4">
                            <label class="flex items-start gap-4 rounded-2xl border border-gray-200 bg-gray-50/70 p-4">
                                <input type="radio" name="active_theme" value="" class="mt-1 text-blue-600 focus:ring-blue-500" <?php echo $current_settings['active_theme'] === '' ? 'checked' : ''; ?>>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-gray-900"><?php echo __('site_settings.theme.default_name'); ?></div>
                                    <div class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.theme.default_desc'); ?></div>
                                </div>
                            </label>

                            <?php foreach ($available_themes as $themeOption): ?>
                                <?php $sampleRoutes = $themeOption['manifest']['sample_routes'] ?? []; ?>
                                <label class="flex items-start gap-4 rounded-2xl border border-gray-200 bg-white p-4">
                                    <input type="radio" name="active_theme" value="<?php echo htmlspecialchars($themeOption['id']); ?>" class="mt-1 text-blue-600 focus:ring-blue-500" <?php echo $current_settings['active_theme'] === $themeOption['id'] ? 'checked' : ''; ?>>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($themeOption['name']); ?></div>
                                            <?php if ($themeOption['version'] !== ''): ?>
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500"><?php echo __('site_settings.theme.version_badge', ['version' => $themeOption['version']]); ?></span>
                                            <?php endif; ?>
                                            <?php if ($current_settings['active_theme'] === $themeOption['id']): ?>
                                                <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700"><?php echo __('site_settings.theme.active_badge'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1 text-sm text-gray-600">
                                            <?php echo htmlspecialchars($themeOption['description'] !== '' ? $themeOption['description'] : __('site_settings.theme.no_description')); ?>
                                        </div>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['home'] ?? geoflow_theme_preview_url($themeOption['id'], 'home')); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_home'); ?></a>
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['category'] ?? geoflow_theme_preview_url($themeOption['id'], 'category', ['slug' => geoflow_preview_first_category_slug($db) ?? ''])); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_category'); ?></a>
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['article'] ?? geoflow_theme_preview_url($themeOption['id'], 'article', ['slug' => geoflow_preview_latest_article_slug($db) ?? ''])); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_article'); ?></a>
                                            <a href="<?php echo htmlspecialchars($sampleRoutes['archive'] ?? geoflow_theme_preview_url($themeOption['id'], 'archive')); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100"><?php echo __('site_settings.theme.preview_archive'); ?></a>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-gray-200">
                            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="layout-template" class="w-5 h-5 mr-2"></i>
                                <?php echo __('site_settings.theme.save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </details>

            <details class="settings-accordion bg-white shadow rounded-lg">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900"><?php echo __('site_settings.ads.section_title'); ?></h3>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.ads.section_desc'); ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="accordion-chevron w-5 h-5 text-gray-400"></i>
                </summary>
                <div class="px-6 py-6 border-t border-gray-200">
                    <div class="flex items-center justify-end mb-6">
                        <button type="button" id="add-article-ad" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            <?php echo __('site_settings.ads.add'); ?>
                        </button>
                    </div>
                    <form method="POST" id="article-ad-form" class="space-y-6">
                        <input type="hidden" name="action" value="update_article_detail_ads">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                        <div class="rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.ads.preview_title'); ?></div>
                            <div class="mt-3 rounded-2xl border border-blue-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="min-w-0">
                                        <div class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700"><?php echo __('site_settings.ads.preview_badge'); ?></div>
                                        <div class="mt-3 text-base font-semibold text-gray-900"><?php echo __('site_settings.ads.preview_heading'); ?></div>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo __('site_settings.ads.preview_copy'); ?></p>
                                    </div>
                                    <button type="button" class="shrink-0 inline-flex items-center rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white"><?php echo __('site_settings.ads.preview_cta'); ?></button>
                                </div>
                            </div>
                        </div>

                        <div id="article-ad-list" class="space-y-5">
                            <?php foreach ($article_detail_ads as $index => $ad): ?>
                                <div class="article-ad-item rounded-2xl border border-gray-200 bg-gray-50/70 p-5" data-ad-index="<?php echo $index; ?>">
                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars((string) ($ad['name'] ?? __('site_settings.ads.default_name', ['index' => $index + 1]))); ?></div>
                                            <div class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.ads.position_label'); ?></div>
                                        </div>
                                        <button type="button" class="remove-article-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                                            <?php echo __('button.delete'); ?>
                                        </button>
                                    </div>

                                    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                                        <input type="hidden" name="ads[<?php echo $index; ?>][id]" value="<?php echo htmlspecialchars((string) ($ad['id'] ?? '')); ?>">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_name'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars((string) ($ad['name'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_name')); ?>">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_badge'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][badge]" value="<?php echo htmlspecialchars((string) ($ad['badge'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_badge')); ?>">
                                        </div>
                                    </div>

                                    <div class="mt-5">
                                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_title'); ?></label>
                                        <input type="text" name="ads[<?php echo $index; ?>][title]" value="<?php echo htmlspecialchars((string) ($ad['title'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_title')); ?>">
                                    </div>

                                    <div class="mt-5">
                                        <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_copy'); ?></label>
                                        <textarea name="ads[<?php echo $index; ?>][copy]" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_copy')); ?>"><?php echo htmlspecialchars((string) ($ad['copy'] ?? '')); ?></textarea>
                                    </div>

                                    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_text'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][button_text]" value="<?php echo htmlspecialchars((string) ($ad['button_text'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_text')); ?>">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_url'); ?></label>
                                            <input type="text" name="ads[<?php echo $index; ?>][button_url]" value="<?php echo htmlspecialchars((string) ($ad['button_url'] ?? '')); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_url')); ?>">
                                        </div>
                                    </div>

                                    <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.ads.field_enabled'); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo __('site_settings.ads.enabled_help'); ?></div>
                                        </div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="ads[<?php echo $index; ?>][enabled]" value="1" <?php echo !empty($ad['enabled']) ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div id="article-ad-empty" class="<?php echo !empty($article_detail_ads) ? 'hidden ' : ''; ?>rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center">
                            <div class="text-base font-medium text-gray-900"><?php echo __('site_settings.ads.empty_title'); ?></div>
                            <div class="mt-2 text-sm text-gray-500"><?php echo __('site_settings.ads.empty_desc'); ?></div>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-gray-200">
                            <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                                <?php echo __('site_settings.ads.save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </details>
            </div>

<?php
// 包含统一底部
require_once __DIR__ . '/includes/footer.php';
?>
<template id="article-ad-template">
    <div class="article-ad-item rounded-2xl border border-gray-200 bg-gray-50/70 p-5" data-ad-index="__INDEX__">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="text-sm font-semibold text-gray-900"><?php echo __('site_settings.ads.new_slot'); ?></div>
                <div class="mt-1 text-xs text-gray-500"><?php echo __('site_settings.ads.position_label'); ?></div>
            </div>
            <button type="button" class="remove-article-ad inline-flex items-center rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50">
                <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                <?php echo __('button.delete'); ?>
            </button>
        </div>

        <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
            <input type="hidden" name="ads[__INDEX__][id]" value="">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_name'); ?></label>
                <input type="text" name="ads[__INDEX__][name]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_name')); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_badge'); ?></label>
                <input type="text" name="ads[__INDEX__][badge]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_badge')); ?>">
            </div>
        </div>

        <div class="mt-5">
            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_title'); ?></label>
            <input type="text" name="ads[__INDEX__][title]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_title')); ?>">
        </div>

        <div class="mt-5">
            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_copy'); ?></label>
            <textarea name="ads[__INDEX__][copy]" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_copy')); ?>"></textarea>
        </div>

        <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_text'); ?></label>
                <input type="text" name="ads[__INDEX__][button_text]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_text')); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('site_settings.ads.field_button_url'); ?></label>
                <input type="text" name="ads[__INDEX__][button_url]" value="" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="<?php echo htmlspecialchars(__('site_settings.ads.placeholder_button_url')); ?>">
            </div>
        </div>

        <div class="mt-5 flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div>
                <div class="text-sm font-medium text-gray-900"><?php echo __('site_settings.ads.field_enabled'); ?></div>
                <div class="text-xs text-gray-500"><?php echo __('site_settings.ads.enabled_help'); ?></div>
            </div>
            <label class="inline-flex items-center">
                <input type="checkbox" name="ads[__INDEX__][enabled]" value="1" checked class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
            </label>
        </div>
    </div>
</template>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const adList = document.getElementById('article-ad-list');
    const emptyState = document.getElementById('article-ad-empty');
    const addButton = document.getElementById('add-article-ad');
    const template = document.getElementById('article-ad-template');

    if (!adList || !emptyState || !addButton || !template) {
        return;
    }

    let adIndex = adList.querySelectorAll('.article-ad-item').length;

    function refreshState() {
        emptyState.classList.toggle('hidden', adList.querySelectorAll('.article-ad-item').length > 0);
    }

    function bindRemove(scope) {
        const removeButton = scope.querySelector('.remove-article-ad');
        if (!removeButton) {
            return;
        }

        removeButton.addEventListener('click', function () {
            scope.remove();
            refreshState();
        });
    }

    addButton.addEventListener('click', function () {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(adIndex)).trim();
        adIndex += 1;
        const adItem = wrapper.firstElementChild;
        if (!adItem) {
            return;
        }

        adList.appendChild(adItem);
        bindRemove(adItem);
        refreshState();

        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    });

    adList.querySelectorAll('.article-ad-item').forEach(bindRemove);
    refreshState();
});
</script>
