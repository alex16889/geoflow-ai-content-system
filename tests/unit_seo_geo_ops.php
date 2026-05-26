<?php

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/seo_functions.php';
require_once __DIR__ . '/../includes/redirect_service.php';
require_once __DIR__ . '/../includes/image_seo_service.php';

function assert_true($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

$faq = geoflow_extract_faq_pairs("
## What is GEO optimization?
GEO optimization helps content become easier for answer engines to understand and cite.

## How do I improve AI search visibility?
Use clear entities, structured data, source-backed content, and crawlable discovery files.
");

assert_true(count($faq) === 2, 'FAQ extraction should detect question headings');
assert_same('What is GEO optimization?', $faq[0]['question'], 'FAQ question should be normalized');
assert_true(str_contains($faq[1]['answer'], 'structured data'), 'FAQ answer should preserve useful text');

$qaFaq = geoflow_extract_faq_pairs("Q: Can llms.txt guarantee AI citations?\nA: No. It is a discovery aid, not a ranking guarantee.");
assert_true(count($qaFaq) === 1, 'Q/A FAQ extraction should work');

assert_same('/old-path', RedirectService::normalizePath('https://example.com/old-path?x=1'), 'Redirect source should normalize to path only');
assert_same('/new-path', RedirectService::normalizeTarget('new-path'), 'Relative target should become root-relative path');
assert_same('https://example.com/new-path', RedirectService::normalizeTarget('https://example.com/new-path'), 'Absolute target should be preserved');

assert_same('AI Search Visibility Chart', ImageSeoService::defaultAltText('AI_Search-Visibility_Chart.png'), 'Image alt text should be human readable');
assert_same('ai-search-visibility-chart.png', ImageSeoService::seoFilename('AI_Search-Visibility_Chart.png', 'random.png'), 'SEO filename should be ASCII slug');

echo "unit_seo_geo_ops: ok\n";

