<?php
/**
 * 最小化的functions.php - 只包含必需的函数
 */

// 管理员登录检查
function require_admin_login() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        if (function_exists('admin_redirect')) {
            admin_redirect();
        }
        header('Location: /geo_admin/');
        exit;
    }
}

// 管理员登录验证
function verify_admin_login($username, $password) {
    global $db;

    if (!($db instanceof PDO)) {
        return false;
    }

    try {
        $stmt = $db->prepare("SELECT password, status FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([trim((string) $username)]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        return $admin
            && ($admin['status'] ?? 'active') === 'active'
            && password_verify((string) $password, (string) ($admin['password'] ?? ''));
    } catch (Throwable $e) {
        return false;
    }
}

// CSRF保护函数
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// 简单的日志函数
function write_log($message, $level = 'INFO') {
    // 简化版本，只写入到临时文件
    $log_file = sys_get_temp_dir() . '/geo_system.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// 简单的设置函数
function get_setting($key, $default = null) {
    // 简化版本，直接返回默认值
    return $default;
}

function set_setting($key, $value) {
    // 简化版本，总是返回true
    return true;
}

// 基本的HTML转义函数
function escape_html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// 基本的URL生成函数
function url($path = '') {
    return SITE_URL . '/' . ltrim($path, '/');
}

// 格式化日期
function format_date($date, $format = 'Y-m-d H:i:s') {
    return date($format, strtotime($date));
}

// 截取文本
function truncate_text($text, $length = 100, $suffix = '...') {
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . $suffix;
}
?>
