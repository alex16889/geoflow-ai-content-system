<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function app_supported_locales(): array {
    return [
        'zh-CN' => '简体中文',
        'en' => 'English',
    ];
}

function app_default_locale(): string {
    return 'zh-CN';
}

function app_locale_session_key(): string {
    return 'app_locale';
}

function app_locale_cookie_name(): string {
    return 'app_locale';
}

function app_normalize_locale(?string $locale): string {
    $locale = trim((string) $locale);
    if ($locale === '') {
        return app_default_locale();
    }

    if ($locale === 'zh' || $locale === 'zh_CN') {
        return 'zh-CN';
    }

    if ($locale === 'en_US' || $locale === 'en-GB') {
        return 'en';
    }

    $supported = app_supported_locales();
    if (isset($supported[$locale])) {
        return $locale;
    }

    $short = strtolower(substr($locale, 0, 2));
    if ($short === 'en') {
        return 'en';
    }
    if ($short === 'zh') {
        return 'zh-CN';
    }

    return app_default_locale();
}

function app_locale_cookie_options(): array {
    $secure = function_exists('geoflow_request_is_https') ? geoflow_request_is_https() : (
        !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    );

    return [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ];
}

function app_persist_locale(string $locale): void {
    $locale = app_normalize_locale($locale);
    setcookie(app_locale_cookie_name(), $locale, app_locale_cookie_options());
    $_COOKIE[app_locale_cookie_name()] = $locale;

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[app_locale_session_key()] = $locale;
    }
}

function app_boot_locale(): void {
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    $sessionKey = app_locale_session_key();
    $resolvedLocale = null;

    if (isset($_GET['lang'])) {
        $resolvedLocale = app_normalize_locale((string) $_GET['lang']);
        app_persist_locale($resolvedLocale);
        $GLOBALS['app_current_locale'] = $resolvedLocale;
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[$sessionKey])) {
        $resolvedLocale = app_normalize_locale((string) $_SESSION[$sessionKey]);
        $_SESSION[$sessionKey] = $resolvedLocale;
    } elseif (!empty($_COOKIE[app_locale_cookie_name()])) {
        $resolvedLocale = app_normalize_locale((string) $_COOKIE[app_locale_cookie_name()]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$sessionKey] = $resolvedLocale;
        }
    } else {
        $acceptLanguage = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if (str_starts_with($acceptLanguage, 'en') || str_contains($acceptLanguage, ',en')) {
            $resolvedLocale = 'en';
        } else {
            $resolvedLocale = app_default_locale();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$sessionKey] = $resolvedLocale;
        }
    }

    $GLOBALS['app_current_locale'] = app_normalize_locale($resolvedLocale);
}

function app_locale(): string {
    app_boot_locale();
    return app_normalize_locale((string) ($GLOBALS['app_current_locale'] ?? app_default_locale()));
}

function app_html_lang(): string {
    return app_locale() === 'zh-CN' ? 'zh-CN' : 'en';
}

function app_locale_label(?string $locale = null): string {
    $locale = app_normalize_locale($locale ?? app_locale());
    $supported = app_supported_locales();
    return $supported[$locale] ?? $supported[app_default_locale()];
}

function app_load_messages(string $locale): array {
    static $cache = [];

    $locale = app_normalize_locale($locale);
    if (isset($cache[$locale])) {
        return $cache[$locale];
    }

    $file = __DIR__ . '/../lang/' . $locale . '.php';
    $messages = [];
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $messages = $loaded;
        }
    }

    $cache[$locale] = $messages;
    return $messages;
}

function __(string $key, array $vars = [], ?string $locale = null): string {
    $locale = app_normalize_locale($locale ?? app_locale());
    $messages = app_load_messages($locale);
    $fallbackMessages = $locale === app_default_locale() ? $messages : app_load_messages(app_default_locale());
    $message = $messages[$key] ?? $fallbackMessages[$key] ?? $key;

    if ($vars) {
        $replace = [];
        foreach ($vars as $varKey => $value) {
            $replace['{' . $varKey . '}'] = (string) $value;
        }
        $message = strtr($message, $replace);
    }

    return $message;
}

function app_locale_switch_url(string $locale): string {
    $locale = app_normalize_locale($locale);
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '');
    $query = [];

    parse_str((string) (parse_url($requestUri, PHP_URL_QUERY) ?? ''), $query);
    $query['lang'] = $locale;

    $queryString = http_build_query($query);
    return $path . ($queryString !== '' ? '?' . $queryString : '');
}
