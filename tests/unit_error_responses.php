<?php

declare(strict_types=1);

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/error_response.php';
require_once __DIR__ . '/../includes/api_response.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$safe = geoflow_public_error_message(new RuntimeException('文章不存在'), '请求处理失败，请稍后重试');
assert_same('文章不存在', $safe, 'Ordinary validation text should remain visible');

$leaky = new RuntimeException("SQLSTATE[HY000] failed at /var/www/html/includes/database.php:45\nStack trace:\n#0 /var/www/html/admin/articles.php(12)");
$hidden = geoflow_public_error_message($leaky, '请求处理失败，请稍后重试');
assert_same('请求处理失败，请稍后重试', $hidden, 'Sensitive exceptions should use the public fallback');
assert_true(!str_contains($hidden, '/var/www'), 'Public errors must not contain file paths');
assert_true(!str_contains($hidden, 'Stack trace'), 'Public errors must not contain stack traces');
assert_true(!str_contains($hidden, 'database.php'), 'Public errors must not contain source file names');

$payload = api_build_error_payload('internal_error', $leaky->getMessage(), 'req-1', [
    'trace' => $leaky->getTraceAsString(),
    'file' => '/var/www/html/includes/database.php',
    'line' => 45,
    'field_errors' => [
        'title' => '文章标题不能为空',
        'storage' => 'Unable to write /tmp/secret/path.php:12',
    ],
]);
$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
assert_true(is_string($encoded), 'Error payload should encode');
assert_true(!str_contains((string) $encoded, 'Stack trace'), 'API payload must not include a stack trace');
assert_true(!str_contains((string) $encoded, '/var/www'), 'API payload must not include absolute paths');
assert_true(!str_contains((string) $encoded, 'database.php'), 'API payload must not include source paths');
assert_true(!str_contains((string) $encoded, 'path.php'), 'Nested sensitive details should be redacted');
assert_true(str_contains((string) $encoded, '文章标题不能为空'), 'Safe field errors should remain');
assert_true(!array_key_exists('trace', $payload['error']['details']), 'Trace keys should be dropped');
assert_true(!array_key_exists('file', $payload['error']['details']), 'File keys should be dropped');

fwrite(STDOUT, "error response tests passed\n");
