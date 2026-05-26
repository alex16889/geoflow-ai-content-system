<?php
/**
 * Dynamic site-scoped robots.txt.
 */

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database_admin.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo_functions.php';

header('Content-Type: text/plain; charset=utf-8');

$adminPath = '/' . trim((string) ADMIN_BASE_PATH, '/') . '/';
$rules = [
    'User-agent: *',
    'Allow: /',
    'Disallow: /admin/',
    'Disallow: ' . $adminPath,
    'Disallow: /api/',
    'Disallow: /preview/',
    'Disallow: /search/',
    'Disallow: /includes/',
    'Disallow: /bin/',
    'Disallow: /docker/',
    'Disallow: /docs/',
    'Disallow: /data/',
    '',
    'Sitemap: ' . geo_absolute_url('sitemap.xml'),
    '',
];

echo implode("\n", $rules);
