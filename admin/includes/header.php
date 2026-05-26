<?php
/**
 * 智能GEO内容系统 - 后台公共头部
 *
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-06
 */

// 确保已经包含必要的文件
if (!defined('FEISHU_TREASURE')) {
    die('Direct access not allowed');
}

$admin_site_name = function_exists('get_setting') ? get_setting('site_title', SITE_NAME) : SITE_NAME;
$current_admin = function_exists('get_current_admin') ? get_current_admin() : null;
$is_super_admin = function_exists('is_super_admin') ? is_super_admin() : false;
$admin_role_label = $is_super_admin ? __('header.super_admin') : __('header.admin');
$current_site_record = function_exists('geoflow_current_site') ? geoflow_current_site() : [];
$available_admin_sites = ($db instanceof PDO && function_exists('geoflow_list_sites')) ? geoflow_list_sites($db, false) : [];
$current_site_id = (int) ($current_site_record['id'] ?? 0);
$current_site_name = (string) ($current_site_record['name'] ?? $admin_site_name);
$site_switch_return_to = (string) ($_SERVER['REQUEST_URI'] ?? admin_url('dashboard.php'));

if ((!isset($message) || $message === '') && isset($_GET['admin_flash_message'])) {
    $message = trim((string) $_GET['admin_flash_message']);
}

if ((!isset($error) || $error === '') && isset($_GET['admin_flash_error'])) {
    $error = trim((string) $_GET['admin_flash_error']);
}

// 获取当前页面名称，用于高亮菜单
$current_page = basename($_SERVER['PHP_SELF']);

// 定义菜单项和子页面映射
$menu_items = [
    'dashboard.php' => ['name' => __('nav.dashboard'), 'icon' => 'home'],
    'tasks.php' => ['name' => __('nav.tasks'), 'icon' => 'zap'],
    'articles.php' => ['name' => __('nav.articles'), 'icon' => 'file-text'],
    'materials.php' => ['name' => __('nav.materials'), 'icon' => 'folder'],
    'ai-configurator.php' => ['name' => __('nav.ai_config'), 'icon' => 'cpu'],
    'site-settings.php' => ['name' => __('nav.site_settings'), 'icon' => 'settings'],
    'seo-geo-workbench.php' => ['name' => __('nav.seo_geo'), 'icon' => 'radar'],
    'security-settings.php' => ['name' => __('nav.security'), 'icon' => 'shield']
];

if ($is_super_admin) {
    $menu_items['sites.php'] = ['name' => __('nav.sites'), 'icon' => 'layers-3'];
    $menu_items['admin-users.php'] = ['name' => __('nav.admin_users'), 'icon' => 'users'];
}

// 定义子页面与主菜单的映射关系
$sub_page_mapping = [
    // 任务管理相关页面
    'task-create.php' => 'tasks.php',
    'task-edit.php' => 'tasks.php',
    'task-execute.php' => 'tasks.php',

    // 文章管理相关页面
    'article-create.php' => 'articles.php',
    'article-edit.php' => 'articles.php',
    'article-view.php' => 'articles.php',
    'articles-review.php' => 'articles.php',
    'articles-trash.php' => 'articles.php',

    // 素材管理相关页面
    'authors.php' => 'materials.php',
    'keyword-libraries.php' => 'materials.php',
    'keyword-library-detail.php' => 'materials.php',
    'keyword-research.php' => 'materials.php',
    'title-libraries.php' => 'materials.php',
    'title-library-ai-generate.php' => 'materials.php',
    'image-libraries.php' => 'materials.php',
    'image-library-detail.php' => 'materials.php',
    'knowledge-bases.php' => 'materials.php',
    'url-import.php' => 'materials.php',
    'url-import-preview.php' => 'materials.php',
    'url-import-history.php' => 'materials.php',

    // AI配置器相关页面
    'ai-models.php' => 'ai-configurator.php',
    'ai-prompts.php' => 'ai-configurator.php',
    'ai-special-prompts.php' => 'ai-configurator.php',
    'ai-config-backup.php' => 'ai-configurator.php',

    // 管理员相关页面
    'admin-activity-logs.php' => 'admin-users.php',
    'api-tokens.php' => 'admin-users.php'
];

// 定义历史/兼容页面，避免和正式入口混淆
$legacy_pages = [
    'ai-config-backup.php' => 'AI 配置历史备份页，请优先使用“AI配置器”。',
    'dashboard-backup.php' => '仪表盘历史备份页，请优先使用“首页”。',
    'materials-new.php' => '素材管理过渡入口，请优先使用正式菜单入口。',
    'tasks-new.php' => '任务管理过渡入口，请优先使用正式菜单入口。',
    'articles-new.php' => '文章管理过渡入口，请优先使用正式菜单入口。',
    'authors-new.php' => '作者管理过渡入口，请优先使用正式菜单入口。',
    'ai-config-new.php' => 'AI 配置过渡入口，请优先使用正式菜单入口。'
];

// 判断当前激活的菜单
function isActiveMenu($page, $current_page, $sub_page_mapping) {
    // 直接匹配
    if ($page === $current_page) {
        return true;
    }

    // 检查是否为子页面
    if (isset($sub_page_mapping[$current_page]) && $sub_page_mapping[$current_page] === $page) {
        return true;
    }

    return false;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(app_html_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : __('header.admin'); ?> - <?php echo htmlspecialchars($admin_site_name); ?></title>
    <link rel="stylesheet" href="/assets/vendor/tailwind/tailwind.css">
    <script src="/assets/vendor/lucide/lucide.js"></script>
    <?php if (isset($additional_css)): ?>
        <?php echo $additional_css; ?>
    <?php endif; ?>
</head>
<body class="bg-slate-50 text-slate-900">
    <!-- 导航栏 -->
    <nav class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
        <div class="mx-auto max-w-[1560px] px-4 sm:px-6 xl:px-8">
            <div class="flex h-16 items-center gap-4">
                <div class="flex min-w-0 flex-1 items-center gap-5">
                    <!-- Logo -->
                    <a href="<?php echo htmlspecialchars(admin_url('dashboard.php')); ?>" class="max-w-[260px] shrink-0 truncate text-lg font-semibold tracking-tight text-slate-950 2xl:max-w-[300px]"><?php echo htmlspecialchars($admin_site_name); ?></a>
                    
                    <!-- 主导航菜单 -->
                    <nav class="hidden min-w-0 items-center gap-1 xl:flex">
                        <?php foreach ($menu_items as $page => $item): ?>
                            <a href="<?php echo htmlspecialchars(admin_url($page)); ?>"
                               class="<?php echo isActiveMenu($page, $current_page, $sub_page_mapping) ? 'bg-blue-50 text-blue-700 shadow-sm' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-900'; ?> whitespace-nowrap rounded-full px-3 py-2 text-sm font-medium transition-colors duration-200">
                                <?php echo $item['name']; ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                
                <!-- 右侧用户信息 -->
                <div class="ml-auto flex shrink-0 items-center gap-2 lg:gap-3">
                    <?php if (!empty($available_admin_sites)): ?>
                        <div class="hidden items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1.5 2xl:flex">
                            <span class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars(__('header.current_site')); ?></span>
                            <?php if (count($available_admin_sites) > 1): ?>
                                <form method="POST" action="<?php echo htmlspecialchars(admin_url('switch-site.php')); ?>" class="flex items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($site_switch_return_to); ?>">
                                    <select name="site_id" data-auto-submit-form class="max-w-[190px] truncate rounded-full border border-slate-200 bg-white px-3 py-1 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                                        <?php foreach ($available_admin_sites as $siteOption): ?>
                                            <option value="<?php echo (int) ($siteOption['id'] ?? 0); ?>" <?php echo (int) ($siteOption['id'] ?? 0) === $current_site_id ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string) ($siteOption['name'] ?? 'Site')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else: ?>
                                <span class="max-w-[190px] truncate rounded-full bg-white px-3 py-1 text-sm font-medium text-slate-700"><?php echo htmlspecialchars($current_site_name); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- 通知图标 -->
                    <button class="hidden text-slate-400 transition-colors duration-200 hover:text-slate-600 sm:inline-flex">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                    </button>
                    
                    <!-- 用户信息 -->
                    <div class="flex items-center gap-2 lg:gap-3">
                        <div class="hidden items-center rounded-full border border-slate-200 bg-white p-1 shadow-sm lg:flex">
                                <?php foreach (app_supported_locales() as $localeCode => $localeLabel): ?>
                                    <?php $isActiveLocale = app_locale() === $localeCode; ?>
                                    <?php $localeShortLabel = $localeCode === 'zh-CN' ? '中文' : 'English'; ?>
                                    <a
                                        href="<?php echo htmlspecialchars(app_locale_switch_url($localeCode)); ?>"
                                        class="<?php echo $isActiveLocale ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100'; ?> rounded-full px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors duration-150"
                                        title="<?php echo htmlspecialchars(__('header.language_switch_to', ['language' => $localeLabel])); ?>"
                                        aria-label="<?php echo htmlspecialchars(__('header.language_switch_to', ['language' => $localeLabel])); ?>"
                                    >
                                        <?php echo htmlspecialchars($localeShortLabel); ?>
                                    </a>
                                <?php endforeach; ?>
                        </div>
                        <div class="relative">
                            <button type="button" data-action="toggle-user-menu" aria-expanded="false" class="flex items-center space-x-1 text-sm text-slate-600 transition-colors duration-200 hover:text-slate-900">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                                    <i data-lucide="user" class="w-4 h-4 text-blue-600"></i>
                                </div>
                                <i data-lucide="chevron-down" class="w-4 h-4"></i>
                            </button>
                            
                            <!-- 用户下拉菜单 -->
                            <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                                <a href="<?php echo htmlspecialchars(admin_url('dashboard.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i data-lucide="home" class="w-4 h-4 inline mr-2"></i>
                                    <?php echo __('nav.back_home'); ?>
                                </a>
                                <a href="<?php echo htmlspecialchars(admin_url('site-settings.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i data-lucide="settings" class="w-4 h-4 inline mr-2"></i>
                                    <?php echo __('nav.system_settings'); ?>
                                </a>
                                <?php if ($is_super_admin): ?>
                                    <a href="<?php echo htmlspecialchars(admin_url('sites.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="layers-3" class="w-4 h-4 inline mr-2"></i>
                                        <?php echo __('header.manage_sites'); ?>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(admin_url('admin-users.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="users" class="w-4 h-4 inline mr-2"></i>
                                        <?php echo __('nav.admin_management'); ?>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(admin_url('admin-activity-logs.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="clipboard-list" class="w-4 h-4 inline mr-2"></i>
                                        <?php echo __('nav.activity_logs'); ?>
                                    </a>
                                    <a href="<?php echo htmlspecialchars(admin_url('api-tokens.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="key-round" class="w-4 h-4 inline mr-2"></i>
                                        <?php echo __('nav.api_tokens'); ?>
                                    </a>
                                <?php endif; ?>
                                <div class="border-t border-gray-100"></div>
                                <a href="<?php echo htmlspecialchars(admin_url('logout.php')); ?>" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                    <i data-lucide="log-out" class="w-4 h-4 inline mr-2"></i>
                                    <?php echo __('button.logout'); ?>
                                </a>
                            </div>
                        </div>
                        <button type="button" data-action="toggle-mobile-menu" aria-expanded="false" class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white p-2 text-slate-600 shadow-sm transition-colors hover:bg-slate-50 xl:hidden">
                            <i data-lucide="menu" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 移动端菜单 -->
        <div id="mobile-menu" class="hidden xl:hidden">
            <div class="space-y-1 border-t bg-slate-50 px-2 pb-3 pt-2 sm:px-3">
                <?php if (!empty($available_admin_sites)): ?>
                    <div class="px-3 py-2">
                        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500"><?php echo htmlspecialchars(__('header.current_site')); ?></div>
                        <?php if (count($available_admin_sites) > 1): ?>
                            <form method="POST" action="<?php echo htmlspecialchars(admin_url('switch-site.php')); ?>" class="space-y-2">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($site_switch_return_to); ?>">
                                <select name="site_id" data-auto-submit-form class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
                                    <?php foreach ($available_admin_sites as $siteOption): ?>
                                        <option value="<?php echo (int) ($siteOption['id'] ?? 0); ?>" <?php echo (int) ($siteOption['id'] ?? 0) === $current_site_id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($siteOption['name'] ?? 'Site')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php else: ?>
                            <div class="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700"><?php echo htmlspecialchars($current_site_name); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($menu_items as $page => $item): ?>
                    <a href="<?php echo htmlspecialchars(admin_url($page)); ?>"
                       class="<?php echo isActiveMenu($page, $current_page, $sub_page_mapping) ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                        <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4 inline mr-2"></i>
                        <?php echo $item['name']; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <!-- 主要内容区域开始 -->
    <div class="mx-auto max-w-[1400px] px-4 py-8 sm:px-6 xl:px-8">
        
        <!-- 消息提示区域 -->
        <?php if (isset($message) && !empty($message)): ?>
            <div class="admin-flash-alert mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <span class="block sm:inline"><?php echo htmlspecialchars($message); ?></span>
                <button type="button" data-dismiss-closest=".admin-flash-alert" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error) && !empty($error)): ?>
            <div class="admin-flash-alert mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                <?php if (!empty($error_action_url) && !empty($error_action_label)): ?>
                    <div class="mt-3">
                        <a href="<?php echo htmlspecialchars($error_action_url); ?>" class="inline-flex items-center px-3 py-1.5 border border-red-300 text-xs font-medium rounded-md text-red-700 bg-white hover:bg-red-50">
                            <i data-lucide="external-link" class="w-4 h-4 mr-1"></i>
                            <?php echo htmlspecialchars($error_action_label); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <button type="button" data-dismiss-closest=".admin-flash-alert" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if (isset($legacy_pages[$current_page])): ?>
            <div class="mb-4 bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded-lg">
                <div class="flex items-start gap-3">
                    <i data-lucide="triangle-alert" class="w-5 h-5 mt-0.5 text-amber-600"></i>
                    <div>
                        <div class="font-semibold"><?php echo __('legacy.title'); ?></div>
                        <div class="text-sm mt-1"><?php echo htmlspecialchars($legacy_pages[$current_page]); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 页面标题区域 -->
        <?php if (isset($page_header) && $page_header): ?>
            <div class="mb-8">
                <?php echo $page_header; ?>
            </div>
        <?php endif; ?>

    <script>
        const initAdminChrome = function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        };

        function setExpandedState(trigger, isExpanded) {
            if (trigger) {
                trigger.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            }
        }

        function toggleMenuById(menuId, trigger) {
            const menu = document.getElementById(menuId);
            if (!menu) {
                return;
            }

            const isHidden = menu.classList.toggle('hidden');
            setExpandedState(trigger, !isHidden);
        }

        function parseAdminActionArgs(trigger, event) {
            const rawArgs = trigger.getAttribute('data-action-args');
            if (!rawArgs) {
                return [];
            }

            try {
                const args = JSON.parse(rawArgs);
                if (!Array.isArray(args)) {
                    return [];
                }

                return args.map(function(arg) {
                    if (arg === '__element__') {
                        return trigger;
                    }
                    if (arg === '__event__') {
                        return event;
                    }
                    return arg;
                });
            } catch (error) {
                console.warn('Invalid admin action args', error);
                return [];
            }
        }

        function callAdminAction(trigger, event) {
            const actionName = trigger.getAttribute('data-action-call');
            if (!actionName || !/^[A-Za-z_$][\w$]*$/.test(actionName)) {
                return false;
            }

            const action = window[actionName];
            if (typeof action !== 'function') {
                console.warn('Admin action not found:', actionName);
                return false;
            }

            event.preventDefault();
            action.apply(trigger, parseAdminActionArgs(trigger, event));
            return true;
        }

        // 点击外部关闭菜单
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const mobileMenu = document.getElementById('mobile-menu');

            const userMenuTrigger = event.target.closest('[data-action="toggle-user-menu"]');
            const mobileMenuTrigger = event.target.closest('[data-action="toggle-mobile-menu"]');
            const dismissButton = event.target.closest('[data-dismiss-closest]');
            const reloadButton = event.target.closest('[data-reload-page]');
            const actionCallButton = event.target.closest('[data-action-call]');
            const confirmButton = event.target.closest('[data-confirm]');

            if (confirmButton && confirmButton.tagName !== 'FORM' && !confirm(confirmButton.getAttribute('data-confirm') || '')) {
                event.preventDefault();
                return;
            }

            if (reloadButton) {
                event.preventDefault();
                window.location.reload();
                return;
            }

            if (userMenuTrigger) {
                event.preventDefault();
                toggleMenuById('user-menu', userMenuTrigger);
                return;
            }

            if (mobileMenuTrigger) {
                event.preventDefault();
                toggleMenuById('mobile-menu', mobileMenuTrigger);
                return;
            }

            if (
                actionCallButton
                && !['INPUT', 'SELECT', 'TEXTAREA'].includes(actionCallButton.tagName)
                && callAdminAction(actionCallButton, event)
            ) {
                return;
            }

            if (dismissButton) {
                const selector = dismissButton.getAttribute('data-dismiss-closest');
                const target = selector ? dismissButton.closest(selector) : null;
                if (target) {
                    target.style.display = 'none';
                }
                return;
            }

            if (userMenu && !event.target.closest('[data-action="toggle-user-menu"]') && !userMenu.contains(event.target)) {
                userMenu.classList.add('hidden');
                setExpandedState(document.querySelector('[data-action="toggle-user-menu"]'), false);
            }

            if (mobileMenu && !event.target.closest('[data-action="toggle-mobile-menu"]') && !mobileMenu.contains(event.target)) {
                mobileMenu.classList.add('hidden');
                setExpandedState(document.querySelector('[data-action="toggle-mobile-menu"]'), false);
            }
        });

        document.addEventListener('change', function(event) {
            const navigateControl = event.target.closest('[data-navigate-on-change]');
            if (navigateControl) {
                const targetUrl = navigateControl.value;
                if (targetUrl) {
                    window.location.href = targetUrl;
                }
                return;
            }

            const autoSubmitControl = event.target.closest('[data-auto-submit-form]');
            if (autoSubmitControl && autoSubmitControl.form) {
                autoSubmitControl.form.submit();
                return;
            }

            const changeActionControl = event.target.closest('[data-action-call]');
            if (changeActionControl) {
                callAdminAction(changeActionControl, event);
            }
        });

        document.addEventListener('submit', function(event) {
            const form = event.target.closest('form[data-confirm]');
            if (!form) {
                return;
            }

            if (!confirm(form.getAttribute('data-confirm') || '')) {
                event.preventDefault();
            }
        });

        // 自动隐藏消息提示
        setTimeout(function() {
            const alerts = document.querySelectorAll('.admin-flash-alert');
            alerts.forEach(function(alert) {
                if (alert.style.display !== 'none') {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 300);
                }
            });
        }, 5000);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAdminChrome, { once: true });
        } else {
            initAdminChrome();
        }
    </script>
