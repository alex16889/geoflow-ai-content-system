<?php
/**
 * Helpers for classifying AI provider failures.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

if (!function_exists('geoflow_trim_error_preview')) {
    function geoflow_trim_error_preview(string $text, int $maxLength = 260): string {
        $preview = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($preview, 'UTF-8') > $maxLength) {
            return mb_substr($preview, 0, $maxLength - 1, 'UTF-8') . '…';
        }

        return $preview;
    }
}

if (!function_exists('geoflow_ai_provider_error_code_from_response')) {
    function geoflow_ai_provider_error_code_from_response(string $response): string {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return '';
        }

        $error = $decoded['error'] ?? null;
        if (!is_array($error)) {
            return '';
        }

        return trim((string) ($error['code'] ?? ''));
    }
}

if (!function_exists('geoflow_ai_provider_error_code_from_text')) {
    function geoflow_ai_provider_error_code_from_text(string $message): string {
        if (
            str_contains($message, 'unsupported_country_region_territory') ||
            str_contains($message, 'Country, region, or territory not supported')
        ) {
            return 'unsupported_country_region_territory';
        }

        return '';
    }
}

if (!function_exists('geoflow_is_non_retryable_ai_provider_error')) {
    function geoflow_is_non_retryable_ai_provider_error(string $message): bool {
        return geoflow_ai_provider_error_code_from_text($message) !== '';
    }
}

if (!function_exists('geoflow_build_ai_http_error_message')) {
    function geoflow_build_ai_http_error_message(int $httpCode, string $response): string {
        $providerCode = geoflow_ai_provider_error_code_from_response($response);
        if ($providerCode === 'unsupported_country_region_territory') {
            return 'AI供应商配置错误：当前 AI API 不支持服务器出口地区（unsupported_country_region_territory）。系统会停止重试并暂停任务，避免重复失败；请在 AI 配置器改用服务器可访问的模型供应商，或配置可用代理后再启动。';
        }

        return 'API调用失败，HTTP状态码: ' . $httpCode . ', 响应: ' . geoflow_trim_error_preview($response);
    }
}

if (!function_exists('geoflow_format_non_retryable_ai_task_error')) {
    function geoflow_format_non_retryable_ai_task_error(string $message): string {
        if (!geoflow_is_non_retryable_ai_provider_error($message)) {
            return $message;
        }

        if (str_contains($message, '系统会停止重试并暂停任务')) {
            return $message;
        }

        return 'AI供应商配置错误：当前 AI API 不支持服务器出口地区（unsupported_country_region_territory）。系统已自动暂停任务，避免继续重复失败；请在 AI 配置器改用服务器可访问的模型供应商，或配置可用代理后再启动。原始错误：' . geoflow_trim_error_preview($message, 180);
    }
}
