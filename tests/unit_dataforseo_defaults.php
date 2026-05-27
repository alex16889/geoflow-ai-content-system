<?php

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/dataforseo_service.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

$service = new DataForSeoService('', '');
$config = $service->publicConfig();

assert_same(DataForSeoService::DEFAULT_LOCATION_CODE, $config['default_location_code'], 'Default location should use the Chinese-market preset');
assert_same(DataForSeoService::DEFAULT_LANGUAGE_CODE, $config['default_language_code'], 'Default language should use Simplified Chinese');
assert_same(2702, DataForSeoService::DEFAULT_LOCATION_CODE, 'Default DataForSEO Google location should be the zh-CN supported market');
assert_same('zh-CN', DataForSeoService::DEFAULT_LANGUAGE_CODE, 'Default DataForSEO language should be zh-CN');

echo "unit_dataforseo_defaults: ok\n";
