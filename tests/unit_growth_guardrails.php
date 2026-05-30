<?php

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/content_quality_service.php';
require_once __DIR__ . '/../includes/site_spend_guard_service.php';
require_once __DIR__ . '/../includes/indexnow_service.php';
require_once __DIR__ . '/../includes/search_submission_service.php';

function assert_true($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_false($condition, string $message): void {
    assert_true(!$condition, $message);
}

function assert_contains_text(string $needle, array $haystack, string $message): void {
    $joined = implode("\n", $haystack);
    assert_true(str_contains($joined, $needle), $message . ' Missing: ' . $needle);
}

$lowQuality = ContentQualityService::evaluate([
    'title' => 'AI',
    'content' => 'Thin content.',
    'meta_description' => '',
    'keywords' => '',
], [
    'min_score' => 65,
    'min_words' => 300,
]);

assert_false($lowQuality['passed'], 'Low quality article should fail the publish gate');
assert_true($lowQuality['score'] < 65, 'Low quality article score should be below threshold');
assert_contains_text('标题过短', $lowQuality['issues'], 'Low quality title issue should be reported');
assert_contains_text('正文过短', $lowQuality['issues'], 'Low quality content issue should be reported');

$strongQuality = ContentQualityService::evaluate([
    'title' => 'AI Search Visibility Benchmarks for Programmatic SEO Teams',
    'content' => str_repeat("## Research Note\nThis section explains concrete workflow evidence with source links and operational examples. ", 80) . ' https://example.com/source',
    'meta_description' => 'A practical benchmark for AI search visibility, programmatic SEO operations, and evidence-led publishing workflows.',
    'keywords' => 'AI search visibility,programmatic SEO,GEO,IndexNow',
], [
    'min_score' => 65,
    'min_words' => 300,
]);

assert_true($strongQuality['passed'], 'Strong article should pass the publish gate');
assert_true($strongQuality['score'] >= 65, 'Strong article score should meet threshold');

$aiStyleQuality = ContentQualityService::evaluate([
    'title' => 'youtube app 下载是什么？新手先看这篇完整说明',
    'content' => str_repeat("## 下载前先判断\n在当今数字化时代，随着移动互联网的发展，本文将全面了解这个问题。值得注意的是，不难看出，首先需要了解，其次需要判断，最后进行总结。参考：https://example.com/source\n", 12),
    'meta_description' => '这是一篇关于 youtube app 下载风险、入口判断、第三方链接识别和新手避坑的完整说明。',
    'keywords' => 'youtube app 下载,安全风险,第三方下载,新手说明',
], [
    'min_score' => 65,
    'min_words' => 120,
    'anti_ai_style_gate_enabled' => '1',
    'anti_ai_style_max_hits' => 2,
]);

assert_contains_text('AI腔表达偏多', $aiStyleQuality['issues'], 'AI-style issue should be reported');
assert_contains_text('结构偏模板化', $aiStyleQuality['issues'], 'Numbered template issue should be reported');

$blockedSpend = SiteSpendGuardService::evaluateBudget(1.00, 0.95, 0.10);
assert_false($blockedSpend['allowed'], 'Spend guard should block requests over daily budget');

$allowedUnlimitedSpend = SiteSpendGuardService::evaluateBudget(0.00, 999.00, 100.00);
assert_true($allowedUnlimitedSpend['allowed'], 'Zero budget should mean unlimited spend while still allowing ledger recording');

assert_false(IndexNowService::isSubmittableBaseUrl('http://127.0.0.1:18080'), 'IndexNow must not submit local tunnel URLs');
assert_false(IndexNowService::isSubmittableBaseUrl('http://localhost'), 'IndexNow must not submit localhost URLs');
assert_true(IndexNowService::isSubmittableBaseUrl('https://example.com'), 'IndexNow should allow public HTTPS hosts');

$enabledProviders = SearchSubmissionService::enabledProviderCodes([
    'indexnow_enabled' => '1',
    'indexnow_key' => 'abcDEF12',
    'bing_url_submission_enabled' => '1',
    'bing_url_submission_api_key' => 'bing-key',
    'baidu_url_submission_enabled' => '1',
    'baidu_url_submission_endpoint' => 'https://data.zz.baidu.com/urls?site=https://example.com&token=baidu-token',
]);
assert_true(in_array('indexnow', $enabledProviders, true), 'IndexNow should be queueable when enabled with a valid key');
assert_true(in_array('bing', $enabledProviders, true), 'Bing should be queueable when enabled with an API key');
assert_true(in_array('baidu', $enabledProviders, true), 'Baidu should be queueable when enabled with an endpoint');
assert_false(in_array('google', $enabledProviders, true), 'Google generic URL push should not be enabled for normal articles');

$disabledProviders = SearchSubmissionService::enabledProviderCodes([
    'indexnow_enabled' => '0',
    'bing_url_submission_enabled' => '1',
    'bing_url_submission_api_key' => '',
    'baidu_url_submission_enabled' => '0',
    'baidu_url_submission_endpoint' => 'https://data.zz.baidu.com/urls?site=https://example.com&token=baidu-token',
]);
assert_true($disabledProviders === [], 'Disabled or incomplete providers should not be queued');

assert_true(SearchSubmissionService::isValidBaiduEndpoint('https://data.zz.baidu.com/urls?site=https://example.com&token=abc123'), 'Baidu endpoint from Search Resource Platform should be accepted');
assert_false(SearchSubmissionService::isValidBaiduEndpoint('https://example.com/urls?site=https://example.com&token=abc123'), 'Non-Baidu endpoint should be rejected');

echo "unit_growth_guardrails: ok\n";
