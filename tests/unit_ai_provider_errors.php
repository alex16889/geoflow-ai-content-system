<?php

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/ai_provider_errors.php';

function assert_true_ai_provider_errors($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$rawUnsupportedRegionResponse = '{"error":{"code":"unsupported_country_region_territory","message":"Country, region, or territory not supported","type":"request_forbidden"}}';
$friendlyError = geoflow_build_ai_http_error_message(403, $rawUnsupportedRegionResponse);

assert_true_ai_provider_errors(
    str_contains($friendlyError, 'AI供应商配置错误'),
    'Unsupported-region provider errors should be converted to a clear operator-facing message'
);
assert_true_ai_provider_errors(
    geoflow_is_non_retryable_ai_provider_error($friendlyError),
    'Unsupported-region provider errors should be classified as non-retryable'
);
assert_true_ai_provider_errors(
    !geoflow_is_non_retryable_ai_provider_error('API调用失败，HTTP状态码: 500, 响应: temporary upstream error'),
    'Generic HTTP 500 provider errors should remain retryable'
);

$legacyRawError = 'API调用失败，HTTP状态码: 403, 响应: ' . $rawUnsupportedRegionResponse;
$taskError = geoflow_format_non_retryable_ai_task_error($legacyRawError);
assert_true_ai_provider_errors(
    str_contains($taskError, '自动暂停任务'),
    'Legacy raw unsupported-region errors should be rewritten with the auto-pause action'
);

echo "unit_ai_provider_errors: ok\n";
