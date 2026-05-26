<?php
/**
 * 创建 API Token
 *
 * 用法：
 * php bin/api/create_token.php "Token 名称" "catalog:read,tasks:read" [admin_id] [expires_at]
 */

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database_admin.php';
require_once __DIR__ . '/../../includes/api_response.php';
require_once __DIR__ . '/../../includes/api_token_service.php';

$name = trim((string) ($argv[1] ?? ''));
$scopesArg = trim((string) ($argv[2] ?? ''));
$adminId = isset($argv[3]) && is_numeric($argv[3]) ? (int) $argv[3] : null;
$expiresAt = trim((string) ($argv[4] ?? ''));

if ($name === '' || $scopesArg === '') {
    fwrite(STDERR, "Usage: php bin/api/create_token.php \"Token 名称\" \"catalog:read,tasks:read\" [admin_id] [expires_at]\n");
    exit(1);
}

$scopes = array_values(array_filter(array_map('trim', explode(',', $scopesArg)), static fn($item) => $item !== ''));

try {
    $service = new ApiTokenService($db);
    $result = $service->createToken($name, $scopes, $adminId, $expiresAt !== '' ? $expiresAt : null);

    fwrite(STDOUT, "Token created successfully\n");
    fwrite(STDOUT, "ID: " . ($result['record']['id'] ?? 'unknown') . "\n");
    fwrite(STDOUT, "Token: " . $result['token'] . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}
