<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class LLMSDiscoveryService {
    public static function renderSummary(PDO $db): string {
        $site = geoflow_current_site();
        $settings = self::siteSettings();
        $categories = self::categories($db, 8);
        $articles = self::publishedArticles($db, 12);

        $lines = [
            '# ' . self::cleanLine($settings['site_name'] ?: ($site['name'] ?? 'GEOflow Site')),
            '',
            '> ' . self::cleanLine($settings['site_description'] ?: ($site['description'] ?? 'AI content operations and SEO/GEO publishing site.')),
            '',
            'Base URL: ' . geo_absolute_url('/'),
            'Sitemap: ' . geo_absolute_url('sitemap.xml'),
            'Robots: ' . geo_absolute_url('robots.txt'),
            'Full LLM map: ' . geo_absolute_url('llms-full.txt'),
            '',
            '## What this site covers',
        ];

        $keywords = array_filter(array_map('trim', preg_split('/\s*,\s*/u', (string) ($settings['site_keywords'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
        foreach (array_slice($keywords, 0, 12) as $keyword) {
            $lines[] = '- ' . self::cleanLine($keyword);
        }

        if (!empty($categories)) {
            $lines[] = '';
            $lines[] = '## Main sections';
            foreach ($categories as $category) {
                $lines[] = '- [' . self::cleanLine($category['name'] ?? '') . '](' . geo_absolute_url('category/' . (($category['slug'] ?? '') ?: ($category['id'] ?? ''))) . ')';
            }
        }

        if (!empty($articles)) {
            $lines[] = '';
            $lines[] = '## Recent published articles';
            foreach ($articles as $article) {
                $lines[] = '- [' . self::cleanLine($article['title'] ?? '') . '](' . geo_absolute_url('article/' . ($article['slug'] ?? '')) . ') - ' . self::cleanLine(clean_markdown_for_summary((string) ($article['excerpt'] ?: $article['content']), 120));
            }
        }

        $lines[] = '';
        $lines[] = '## AI crawler guidance';
        $lines[] = '- Prefer canonical URLs from the sitemap.';
        $lines[] = '- Treat published article pages as primary source pages.';
        $lines[] = '- Ignore admin, preview, API, and internal system paths.';

        return implode("\n", $lines) . "\n";
    }

    public static function renderFull(PDO $db): string {
        $site = geoflow_current_site();
        $settings = self::siteSettings();
        $articles = self::publishedArticles($db, 60);
        $categories = self::categories($db, 30);
        $keywordTopics = self::keywordTopics($db, 40);

        $lines = [
            '# ' . self::cleanLine($settings['site_name'] ?: ($site['name'] ?? 'GEOflow Site')) . ' - Full AI Discovery Map',
            '',
            'Generated for: ' . geo_absolute_url('/'),
            'Sitemap: ' . geo_absolute_url('sitemap.xml'),
            'Robots: ' . geo_absolute_url('robots.txt'),
            'Summary map: ' . geo_absolute_url('llms.txt'),
            '',
            '## Site summary',
            self::cleanLine($settings['site_description'] ?: ($site['description'] ?? 'AI content operations and SEO/GEO publishing site.')),
            '',
            '## Structured data expectations',
            '- WebSite and Organization describe the site entity.',
            '- Article pages expose Article, BreadcrumbList, and FAQPage when clear FAQ pairs exist.',
            '- Category and archive pages expose CollectionPage/ItemList style signals.',
            '',
            '## Main categories',
        ];

        if (empty($categories)) {
            $lines[] = '- No published categories yet.';
        } else {
            foreach ($categories as $category) {
                $description = self::cleanLine((string) ($category['description'] ?? ''));
                $lines[] = '- [' . self::cleanLine($category['name'] ?? '') . '](' . geo_absolute_url('category/' . (($category['slug'] ?? '') ?: ($category['id'] ?? ''))) . ')' . ($description !== '' ? ': ' . $description : '');
            }
        }

        $lines[] = '';
        $lines[] = '## Keyword and topic signals';
        if (empty($keywordTopics)) {
            $lines[] = '- No keyword library terms with metrics are available yet.';
        } else {
            foreach ($keywordTopics as $keyword) {
                $parts = [self::cleanLine($keyword['keyword'] ?? '')];
                if (($keyword['search_volume'] ?? null) !== null) {
                    $parts[] = 'volume ' . (int) $keyword['search_volume'];
                }
                if (!empty($keyword['competition'])) {
                    $parts[] = 'competition ' . self::cleanLine($keyword['competition']);
                }
                $lines[] = '- ' . implode(' | ', array_filter($parts));
            }
        }

        $lines[] = '';
        $lines[] = '## Published article index';
        if (empty($articles)) {
            $lines[] = '- No published articles yet.';
        } else {
            foreach ($articles as $article) {
                $summary = self::cleanLine(clean_markdown_for_summary((string) ($article['excerpt'] ?: $article['content']), 180));
                $date = substr((string) ($article['published_at'] ?: $article['created_at'] ?? ''), 0, 10);
                $lines[] = '- [' . self::cleanLine($article['title'] ?? '') . '](' . geo_absolute_url('article/' . ($article['slug'] ?? '')) . ')' . ($date !== '' ? ' | ' . $date : '') . ($summary !== '' ? ' | ' . $summary : '');
            }
        }

        $lines[] = '';
        $lines[] = '## Crawl boundaries';
        $lines[] = '- Crawl public home, category, archive, and article pages.';
        $lines[] = '- Do not crawl admin, API, preview, upload execution, or internal include paths.';
        $lines[] = '- Use sitemap timestamps to detect changed URLs.';

        return implode("\n", $lines) . "\n";
    }

    private static function siteSettings(): array {
        return [
            'site_name' => function_exists('site_setting_value') ? site_setting_value('site_name', '') : '',
            'site_description' => function_exists('site_setting_value') ? site_setting_value('site_description', '') : '',
            'site_keywords' => function_exists('site_setting_value') ? site_setting_value('site_keywords', '') : '',
        ];
    }

    private static function categories(PDO $db, int $limit): array {
        $stmt = $db->prepare("
            SELECT c.id, c.name, c.slug, c.description, COUNT(a.id) AS article_count
            FROM categories c
            LEFT JOIN articles a ON a.category_id = c.id
             AND a.status = 'published'
             AND a.deleted_at IS NULL
             " . geoflow_site_scope_sql('articles', 'a') . "
            WHERE 1=1
            " . geoflow_site_scope_sql('categories', 'c') . "
            GROUP BY c.id, c.name, c.slug, c.description, c.sort_order
            ORDER BY article_count DESC, c.sort_order ASC, c.name ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function publishedArticles(PDO $db, int $limit): array {
        $stmt = $db->prepare("
            SELECT id, title, slug, excerpt, content, published_at, created_at
            FROM articles
            WHERE status = 'published'
              AND deleted_at IS NULL
            " . geoflow_site_scope_sql('articles') . "
            ORDER BY COALESCE(published_at, created_at) DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function keywordTopics(PDO $db, int $limit): array {
        if (!geoflow_db_table_exists($db, 'keywords')) {
            return [];
        }

        $scope = geoflow_table_has_site_column($db, 'keyword_libraries') ? ' AND kl.site_id = ?' : '';
        $stmt = $db->prepare("
            SELECT k.keyword, k.search_volume, k.competition, k.competition_index
            FROM keywords k
            INNER JOIN keyword_libraries kl ON kl.id = k.library_id
            WHERE COALESCE(k.keyword, '') <> ''
            {$scope}
            ORDER BY COALESCE(k.search_volume, 0) DESC, k.keyword ASC
            LIMIT ?
        ");
        $index = 1;
        if ($scope !== '') {
            $stmt->bindValue($index++, geoflow_current_site_id(), PDO::PARAM_INT);
        }
        $stmt->bindValue($index, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function cleanLine(string $value): string {
        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return str_replace(["\r", "\n"], ' ', $value);
    }
}

