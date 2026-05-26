<?php
/**
 * Dynamic site-scoped XML sitemap.
 */

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database_admin.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo_functions.php';

function geoflow_sitemap_escape(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function geoflow_sitemap_lastmod($value): string {
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('c', $timestamp) : '';
}

function geoflow_sitemap_url_node(string $url, string $lastmod = '', string $changefreq = '', string $priority = ''): string {
    $xml = "  <url>\n";
    $xml .= '    <loc>' . geoflow_sitemap_escape($url) . "</loc>\n";
    if ($lastmod !== '') {
        $xml .= '    <lastmod>' . geoflow_sitemap_escape($lastmod) . "</lastmod>\n";
    }
    if ($changefreq !== '') {
        $xml .= '    <changefreq>' . geoflow_sitemap_escape($changefreq) . "</changefreq>\n";
    }
    if ($priority !== '') {
        $xml .= '    <priority>' . geoflow_sitemap_escape($priority) . "</priority>\n";
    }
    $xml .= "  </url>\n";
    return $xml;
}

function geoflow_sitemap_max_article_lastmod(PDO $db): string {
    $stmt = $db->query("
        SELECT MAX(COALESCE(updated_at, published_at, created_at)) AS lastmod
        FROM articles
        WHERE status = 'published'
          AND deleted_at IS NULL
        " . geoflow_site_scope_sql('articles') . "
    ");
    return geoflow_sitemap_lastmod($stmt ? ($stmt->fetchColumn() ?: '') : '');
}

function geoflow_sitemap_category_nodes(PDO $db): array {
    $categoryScope = geoflow_site_scope_sql('categories', 'c');
    $articleScope = geoflow_site_scope_sql('articles', 'a');
    $stmt = $db->query("
        SELECT
            c.id,
            c.slug,
            COALESCE(MAX(a.updated_at), c.created_at) AS lastmod,
            COUNT(a.id) AS published_count
        FROM categories c
        LEFT JOIN articles a
          ON a.category_id = c.id
         AND a.status = 'published'
         AND a.deleted_at IS NULL
         " . $articleScope . "
        WHERE 1=1
        " . $categoryScope . "
        GROUP BY c.id, c.slug, c.created_at, c.sort_order, c.name
        HAVING COUNT(a.id) > 0
        ORDER BY c.sort_order ASC, c.name ASC
        LIMIT 1000
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function geoflow_sitemap_archive_nodes(PDO $db): array {
    $stmt = $db->query("
        SELECT
            EXTRACT(YEAR FROM COALESCE(published_at, created_at))::int AS year,
            EXTRACT(MONTH FROM COALESCE(published_at, created_at))::int AS month,
            MAX(COALESCE(updated_at, published_at, created_at)) AS lastmod
        FROM articles
        WHERE status = 'published'
          AND deleted_at IS NULL
        " . geoflow_site_scope_sql('articles') . "
        GROUP BY year, month
        ORDER BY year DESC, month DESC
        LIMIT 240
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function geoflow_sitemap_article_nodes(PDO $db): array {
    $stmt = $db->query("
        SELECT slug, COALESCE(updated_at, published_at, created_at) AS lastmod
        FROM articles
        WHERE status = 'published'
          AND deleted_at IS NULL
        " . geoflow_site_scope_sql('articles') . "
        ORDER BY COALESCE(published_at, created_at) DESC, id DESC
        LIMIT 45000
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noarchive');

$latestArticleLastmod = geoflow_sitemap_max_article_lastmod($db);
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
$xml .= geoflow_sitemap_url_node(geo_absolute_url('/'), $latestArticleLastmod, 'daily', '1.0');
$xml .= geoflow_sitemap_url_node(geo_absolute_url('archive'), $latestArticleLastmod, 'weekly', '0.6');

foreach (geoflow_sitemap_category_nodes($db) as $category) {
    $categoryPath = 'category/' . ((string) ($category['slug'] ?? '') !== '' ? (string) $category['slug'] : (string) $category['id']);
    $xml .= geoflow_sitemap_url_node(geo_absolute_url($categoryPath), geoflow_sitemap_lastmod($category['lastmod'] ?? ''), 'weekly', '0.8');
}

foreach (geoflow_sitemap_archive_nodes($db) as $archive) {
    $year = (int) ($archive['year'] ?? 0);
    $month = (int) ($archive['month'] ?? 0);
    if ($year > 0 && $month > 0) {
        $xml .= geoflow_sitemap_url_node(
            geo_absolute_url(sprintf('archive/%04d/%02d', $year, $month)),
            geoflow_sitemap_lastmod($archive['lastmod'] ?? ''),
            'monthly',
            '0.5'
        );
    }
}

foreach (geoflow_sitemap_article_nodes($db) as $article) {
    $slug = trim((string) ($article['slug'] ?? ''));
    if ($slug !== '') {
        $xml .= geoflow_sitemap_url_node(geo_absolute_url('article/' . $slug), geoflow_sitemap_lastmod($article['lastmod'] ?? ''), 'weekly', '0.9');
    }
}

$xml .= "</urlset>\n";
echo $xml;
