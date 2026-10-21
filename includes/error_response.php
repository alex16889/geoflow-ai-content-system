<?php
/**
 * Public error shaping.
 *
 * Logs stay detailed. HTTP and admin responses must not echo stack traces,
 * absolute paths, SQL driver details, or credential fragments.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function geoflow_error_text_is_sensitive(string $text): bool
{
    if ($text === '') {
        return false;
    }

    if (preg_match('/stack\s*trace/i', $text) === 1) {
        return true;
    }

    if (preg_match('/#\d+\s+/', $text) === 1) {
        return true;
    }

    if (preg_match('/\b[A-Za-z]:\\\\/', $text) === 1) {
        return true;
    }

    if (preg_match('/\.php(?:\(\d+\)|:\d+)\b/i', $text) === 1) {
        return true;
    }

    if (preg_match('#/(?:[A-Za-z0-9._-]+/){1,}[A-Za-z0-9._-]+\.php\b#', $text) === 1) {
        return true;
    }

    if (preg_match('/SQLSTATE\[/i', $text) === 1) {
        return true;
    }

    if (preg_match('/\b(?:password|secret|api[_-]?key|authorization)\b\s*[:=]/i', $text) === 1) {
        return true;
    }

    return false;
}

function geoflow_public_error_message(Throwable $e, string $fallback = '请求处理失败，请稍后重试'): string
{
    $message = trim($e->getMessage());
    if ($message === '' || geoflow_error_text_is_sensitive($message)) {
        return $fallback;
    }

    return $message;
}

function geoflow_redact_public_text(string $text, string $fallback = '请求处理失败，请稍后重试'): string
{
    $text = trim($text);
    if ($text === '' || geoflow_error_text_is_sensitive($text)) {
        return $fallback;
    }

    return $text;
}

function geoflow_redact_public_value($value, string $fallback = '请求处理失败，请稍后重试')
{
    if (is_string($value)) {
        return geoflow_redact_public_text($value, $fallback);
    }

    if (!is_array($value)) {
        return $value;
    }

    $blockedKeys = ['trace', 'stack', 'stacktrace', 'file', 'line', 'path'];
    $clean = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array(strtolower($key), $blockedKeys, true)) {
            continue;
        }
        $clean[$key] = geoflow_redact_public_value($item, $fallback);
    }

    return $clean;
}
