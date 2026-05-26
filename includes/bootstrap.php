<?php
if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
    return;
}

@ini_set('implicit_flush', '0');
@ob_implicit_flush(false);

if (!function_exists('geoflow_request_is_https')) {
    function geoflow_request_is_https(): bool {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }

        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));

        return $forwardedProto === 'https' || $forwardedSsl === 'on';
    }
}

if (!function_exists('geoflow_apply_runtime_security_headers')) {
    function geoflow_apply_runtime_security_headers(): void {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if (geoflow_request_is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

if (!function_exists('geoflow_configure_session_ini')) {
    function geoflow_configure_session_ini(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $sessionName = trim((string) (getenv('SESSION_NAME') ?: 'blog_secure_session'));
        if ($sessionName !== '') {
            session_name($sessionName);
        }

        $timeout = (int) (getenv('SESSION_TIMEOUT') ?: 3600);
        if ($timeout <= 0) {
            $timeout = 3600;
        }

        @ini_set('session.gc_maxlifetime', (string) max(300, $timeout));
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', (string) (getenv('SESSION_COOKIE_SAMESITE') ?: 'Lax'));
        @ini_set('session.cookie_secure', geoflow_request_is_https() ? '1' : '0');
    }
}

if (!function_exists('geoflow_response_is_html')) {
    function geoflow_response_is_html(string $buffer): bool {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }

        $trimmed = ltrim($buffer);
        return $trimmed !== '' && (
            stripos($trimmed, '<!DOCTYPE html') === 0
            || stripos($trimmed, '<html') !== false
            || stripos($trimmed, '<body') !== false
        );
    }
}

if (!function_exists('geoflow_hash_script_source')) {
    function geoflow_hash_script_source(string $script, array &$hashes): void {
        $variants = [$script];
        $trimmed = trim($script);
        if ($trimmed !== '' && $trimmed !== $script) {
            $variants[] = $trimmed;
        }

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            $hashes['sha256-' . base64_encode(hash('sha256', $variant, true))] = true;
        }
    }
}

if (!function_exists('geoflow_collect_inline_script_hashes')) {
    function geoflow_collect_inline_script_hashes(string $html): array {
        $hashes = [];
        if (!preg_match_all('/<script\b(?![^>]*\bsrc=)([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            geoflow_hash_script_source((string) ($match[2] ?? ''), $hashes);
        }

        return array_keys($hashes);
    }
}

if (!function_exists('geoflow_collect_inline_handler_hashes')) {
    function geoflow_collect_inline_handler_hashes(string $html): array {
        $hashes = [];
        if (!preg_match_all('/\s(on[a-z0-9_-]+)\s*=\s*(["\'])(.*?)\2/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $handler = html_entity_decode((string) ($match[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            geoflow_hash_script_source($handler, $hashes);
        }

        return array_keys($hashes);
    }
}

if (!function_exists('geoflow_build_content_security_policy')) {
    function geoflow_build_content_security_policy(array $scriptHashes, array $attributeHashes): string {
        $quoteHashSources = static function (array $hashes): array {
            return array_map(static function (string $hash): string {
                return "'" . trim($hash, "'") . "'";
            }, $hashes);
        };

        $scriptSources = array_merge(["'self'", 'https:'], $quoteHashSources($scriptHashes));
        $scriptAttrPolicy = empty($attributeHashes)
            ? "'none'"
            : "'unsafe-hashes' " . implode(' ', $quoteHashSources($attributeHashes));

        return implode(' ', [
            "default-src 'self' https: data:;",
            'script-src ' . implode(' ', $scriptSources) . ';',
            "script-src-attr {$scriptAttrPolicy};",
            "style-src 'self' 'unsafe-inline' https:;",
            "img-src 'self' data: https:;",
            "font-src 'self' data: https:;",
            "object-src 'none';",
            "base-uri 'self';",
            "form-action 'self';",
            "frame-ancestors 'self';",
        ]);
    }
}

if (!function_exists('geoflow_finalize_dynamic_csp_response')) {
    function geoflow_finalize_dynamic_csp_response(): void {
        if (!defined('GEOFLOW_RESPONSE_BUFFER_LEVEL')) {
            return;
        }

        while (ob_get_level() > GEOFLOW_RESPONSE_BUFFER_LEVEL) {
            ob_end_flush();
        }

        $buffer = ob_get_contents();
        if ($buffer === false || !geoflow_response_is_html($buffer)) {
            return;
        }

        $scriptHashes = geoflow_collect_inline_script_hashes($buffer);
        $attributeHashes = geoflow_collect_inline_handler_hashes($buffer);
        $policy = geoflow_build_content_security_policy($scriptHashes, $attributeHashes);

        if (!headers_sent()) {
            header_remove('Content-Security-Policy');
            header('Content-Security-Policy: ' . $policy);
        }

        if (ob_get_level() >= GEOFLOW_RESPONSE_BUFFER_LEVEL) {
            ob_end_clean();
        }

        echo $buffer;
    }
}

geoflow_configure_session_ini();
geoflow_apply_runtime_security_headers();

if (!defined('GEOFLOW_RESPONSE_BUFFER_ACTIVE')) {
    define('GEOFLOW_RESPONSE_BUFFER_ACTIVE', true);
    ob_start();
    define('GEOFLOW_RESPONSE_BUFFER_LEVEL', ob_get_level());
    register_shutdown_function('geoflow_finalize_dynamic_csp_response');
}
