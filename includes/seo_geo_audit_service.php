<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/search_submission_service.php';
require_once __DIR__ . '/image_seo_service.php';

class SeoGeoAuditService {
    public static function audit(PDO $db): array {
        $site = geoflow_current_site();
        $siteId = (int) ($site['id'] ?? 0);
        $settings = self::settings();
        $baseUrl = IndexNowService::publicBaseUrlForSite($site);
        $providers = SearchSubmissionService::enabledProviderCodes($settings);
        $contentStats = self::contentStats($db, $siteId);
        $imageCoverage = ImageSeoService::coverage($db, $siteId);
        $visibilityStats = self::visibilityStats($db, $siteId);

        $checks = [
            'discovery' => [
                self::check('public_domain', '公网主域名', IndexNowService::isSubmittableBaseUrl($baseUrl), '给当前站点配置真实主域名，sitemap/canonical/推送才不会停在 127.0.0.1。'),
                self::check('sitemap', '动态 sitemap.xml', true, '已由站点动态生成。', SearchSubmissionService::sitemapUrlForSite($site)),
                self::check('robots', '动态 robots.txt', true, '已由站点动态生成并屏蔽后台/API/预览路径。', SearchSubmissionService::robotsUrlForSite($site)),
                self::check('llms', '动态 llms.txt / llms-full.txt', true, '已提供 AI crawler 可读站点地图。', geo_absolute_url('llms.txt')),
            ],
            'content' => [
                self::check('published_articles', '已发布内容', $contentStats['published_articles'] > 0, '至少发布 3-5 篇有质量门槛的内容后再提交搜索引擎。', (string) $contentStats['published_articles']),
                self::check('categories', '分类结构', $contentStats['categories'] > 0, '给每个精品站建立清晰分类，不要所有文章堆在默认分类。', (string) $contentStats['categories']),
                self::check('keywords_with_metrics', '关键词指标', $contentStats['keywords_with_metrics'] > 0, '使用 DataForSEO 拉取搜索量/CPC/竞争度后再做内容排期。', (string) $contentStats['keywords_with_metrics']),
                self::check('quality_gate', '发布质量门槛', ($settings['quality_gate_enabled'] ?? '1') === '1', '建议保持质量评分门槛开启，防止 scaled content abuse 风险。'),
            ],
            'technical' => [
                self::check('search_submission', '搜索推送通道', !empty($providers), '按站点启用 IndexNow/Bing/百度；Google 使用 sitemap + Search Console。', implode(', ', $providers) ?: 'none'),
                self::check('schema', '结构化数据', true, 'Article、WebSite、Organization、Breadcrumb、FAQ/ItemList helper 已可用。'),
                self::check('image_seo', '图片 SEO 元数据', $imageCoverage['total'] === 0 || $imageCoverage['coverage'] >= 70, '上传图片会自动生成 alt/SEO 文件名；老图片建议补 alt/caption。', $imageCoverage['coverage'] . '%'),
                self::check('redirects', '重定向与 404 管理', geoflow_db_table_exists($db, 'redirect_rules') && geoflow_db_table_exists($db, 'not_found_logs'), '用后台记录迁移重定向和 404，不需要直接改 Nginx。'),
            ],
            'visibility' => [
                self::check('search_snapshots', 'Search Console/Bing 快照', $visibilityStats['search_snapshots'] > 0, '先手动录入或导入 GSC/Bing 查询表现，后续再接 OAuth 自动化。', (string) $visibilityStats['search_snapshots']),
                self::check('ai_visibility', 'AI 答案可见性记录', $visibilityStats['ai_checks'] > 0, '定期记录 ChatGPT/Perplexity/Gemini/Claude 是否提及或引用本站。', (string) $visibilityStats['ai_checks']),
                self::check('competitor_briefs', '竞品内容简报', $visibilityStats['competitor_briefs'] > 0, '每个核心关键词保留竞品角度、缺口和自己的内容切入点。', (string) $visibilityStats['competitor_briefs']),
            ],
        ];

        $flat = array_merge(...array_values($checks));
        $passed = count(array_filter($flat, static fn(array $check): bool => $check['status'] === 'pass'));
        $score = count($flat) > 0 ? (int) round(($passed / count($flat)) * 100) : 0;

        return [
            'score' => $score,
            'site' => $site,
            'settings' => $settings,
            'checks' => $checks,
            'stats' => [
                'content' => $contentStats,
                'images' => $imageCoverage,
                'visibility' => $visibilityStats,
                'providers' => $providers,
                'base_url' => $baseUrl,
            ],
        ];
    }

    private static function settings(): array {
        $keys = [
            'quality_gate_enabled',
            'quality_gate_min_score',
            'quality_gate_min_words',
            'indexnow_enabled',
            'indexnow_key',
            'bing_url_submission_enabled',
            'bing_url_submission_api_key',
            'baidu_url_submission_enabled',
            'baidu_url_submission_endpoint',
            'dataforseo_daily_budget_usd',
        ];

        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = function_exists('get_setting') ? (string) get_setting($key, '') : '';
        }
        return $settings;
    }

    private static function contentStats(PDO $db, int $siteId): array {
        $stats = ['published_articles' => 0, 'categories' => 0, 'keywords_with_metrics' => 0];
        if ($siteId <= 0) {
            return $stats;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE site_id = ? AND status = 'published' AND deleted_at IS NULL");
        $stmt->execute([$siteId]);
        $stats['published_articles'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE site_id = ?");
        $stmt->execute([$siteId]);
        $stats['categories'] = (int) $stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM keywords k
            INNER JOIN keyword_libraries kl ON kl.id = k.library_id
            WHERE kl.site_id = ?
              AND k.search_volume IS NOT NULL
        ");
        $stmt->execute([$siteId]);
        $stats['keywords_with_metrics'] = (int) $stmt->fetchColumn();

        return $stats;
    }

    private static function visibilityStats(PDO $db, int $siteId): array {
        $tables = [
            'search_snapshots' => 'search_performance_snapshots',
            'ai_checks' => 'ai_visibility_checks',
            'competitor_briefs' => 'competitor_briefs',
        ];
        $stats = ['search_snapshots' => 0, 'ai_checks' => 0, 'competitor_briefs' => 0];

        foreach ($tables as $key => $table) {
            if (!geoflow_db_table_exists($db, $table)) {
                continue;
            }
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE site_id = ?");
            $stmt->execute([$siteId]);
            $stats[$key] = (int) $stmt->fetchColumn();
        }

        return $stats;
    }

    private static function check(string $key, string $label, bool $passed, string $action, string $value = ''): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $passed ? 'pass' : 'warn',
            'action' => $action,
            'value' => $value,
        ];
    }
}

