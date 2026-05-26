<?php
/**
 * GEO+AI内容生成系统 - 管理员登出
 * 
 * @author 姚金刚
 * @version 1.0
 * @date 2025-10-03
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_admin_logged_in()) {
    log_admin_activity('auth:logout', [
        'request_method' => 'GET',
        'page' => 'logout.php',
        'details' => [
            'username' => $_SESSION['admin_username'] ?? ''
        ]
    ]);
}

// 执行登出操作
clear_admin_session();

// 跳转到登录页面
header('Location: index.php');
exit;
?>
