<?php
/**
 * 智能GEO内容系统 - 切换后台当前站点
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();

function admin_switch_redirect_target(string $returnTo = ''): string
{
    $fallback = admin_url('dashboard.php');
    $returnTo = trim($returnTo);

    if ($returnTo === '') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $returnTo)) {
        $parsed = parse_url($returnTo);
        if (!$parsed) {
            return $fallback;
        }

        $returnTo = (string) ($parsed['path'] ?? '');
        if (!empty($parsed['query'])) {
            $returnTo .= '?' . $parsed['query'];
        }
    }

    if ($returnTo === '') {
        return $fallback;
    }

    if ($returnTo[0] !== '/') {
        $returnTo = '/' . ltrim($returnTo, '/');
    }

    $adminBase = rtrim(ADMIN_BASE_PATH, '/');
    if ($returnTo !== $adminBase && !str_starts_with($returnTo, $adminBase . '/')) {
        return $fallback;
    }

    return $returnTo;
}

function admin_switch_redirect_with_flash(string $target, string $type, string $message): void
{
    $parsed = parse_url($target);
    $path = (string) ($parsed['path'] ?? admin_url('dashboard.php'));
    $queryParams = [];

    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $queryParams);
    }

    unset($queryParams['admin_flash_message'], $queryParams['admin_flash_error']);

    $queryParams[$type === 'error' ? 'admin_flash_error' : 'admin_flash_message'] = $message;
    $query = http_build_query($queryParams);

    redirect($path . ($query !== '' ? '?' . $query : ''));
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    redirect(admin_url('dashboard.php'));
}

$redirectTarget = admin_switch_redirect_target((string) ($_POST['return_to'] ?? ''));

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    admin_switch_redirect_with_flash($redirectTarget, 'error', __('message.csrf_failed'));
}

$siteId = (int) ($_POST['site_id'] ?? 0);
$site = $siteId > 0 ? geoflow_find_site_by_id($db, $siteId) : null;

if (!$site || ($site['status'] ?? 'inactive') !== 'active') {
    admin_switch_redirect_with_flash($redirectTarget, 'error', __('message.invalid_site_selection'));
}

geoflow_set_admin_selected_site_id($siteId);
admin_switch_redirect_with_flash($redirectTarget, 'success', __('message.site_switched'));
