<?php
/**
 * Dynamic site-scoped LLM discovery files.
 */

if (!defined('FEISHU_TREASURE')) {
    define('FEISHU_TREASURE', true);
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo_functions.php';
require_once __DIR__ . '/includes/llms_service.php';

$database = Database::getInstance();
$db = $database->getPDO();

$mode = trim((string) ($_GET['mode'] ?? ''));
if ($mode === '') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $mode = str_ends_with($path, 'llms-full.txt') ? 'full' : 'summary';
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noarchive');

echo $mode === 'full'
    ? LLMSDiscoveryService::renderFull($db)
    : LLMSDiscoveryService::renderSummary($db);

