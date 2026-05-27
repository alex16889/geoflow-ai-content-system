<?php

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/site_context.php';

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

assert_same('example.com', geoflow_normalize_domain_input('example.com'), 'Plain domain should be preserved');
assert_same('example.com', geoflow_normalize_domain_input('https://Example.com/path?utm=1'), 'Full URL should normalize to host');
assert_same('example.com', geoflow_normalize_domain_input('example.com/path/to/page'), 'Path-only host input should drop the path');
assert_same('example.com', geoflow_normalize_domain_input('example.com:8080'), 'Port should be stripped');
assert_same('', geoflow_normalize_domain_input('https'), 'Scheme-only input should not become a fake domain');

echo "unit_site_domain_input: ok\n";
