<?php

define('FEISHU_TREASURE', true);

function db_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

require_once __DIR__ . '/../admin/includes/material-library-helpers.php';

function assert_same_dataforseo_duplicate_guard($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("
    CREATE TABLE keywords (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        library_id INTEGER NOT NULL,
        keyword TEXT NOT NULL,
        source TEXT DEFAULT 'manual',
        seed_keyword TEXT DEFAULT '',
        location_code INTEGER DEFAULT NULL,
        language_code TEXT DEFAULT '',
        search_volume INTEGER DEFAULT NULL,
        cpc NUMERIC DEFAULT NULL,
        competition TEXT DEFAULT '',
        competition_index INTEGER DEFAULT NULL,
        monthly_searches_json TEXT DEFAULT '',
        metrics_updated_at TEXT DEFAULT NULL
    )
");

$insert = $db->prepare("
    INSERT INTO keywords (
        library_id, keyword, source, seed_keyword, location_code, language_code, metrics_updated_at
    ) VALUES (1, ?, 'dataforseo', 'APP下载', 2702, 'zh-CN', '2026-05-28 01:00:00')
");

for ($i = 1; $i <= 50; $i++) {
    $insert->execute(['APP下载 关键词 ' . $i]);
}

$plan = material_dataforseo_seed_import_plan($db, 1, ['APP下载', 'J9入口导航'], 2702, 'zh-CN', 50);

assert_same_dataforseo_duplicate_guard(['J9入口导航'], $plan['request_seeds'], 'Seeds with enough existing DataForSEO rows should be skipped');
assert_same_dataforseo_duplicate_guard(1, count($plan['skipped_seeds']), 'One seed should be skipped');
assert_same_dataforseo_duplicate_guard('APP下载', $plan['skipped_seeds'][0]['seed'], 'Skipped seed should be reported');
assert_same_dataforseo_duplicate_guard(50, $plan['skipped_seeds'][0]['existing_count'], 'Skipped seed should report existing count');

$refreshPlan = material_dataforseo_seed_import_plan($db, 1, ['APP下载'], 2702, 'zh-CN', 100);
assert_same_dataforseo_duplicate_guard(['APP下载'], $refreshPlan['request_seeds'], 'Seed should be requested when existing rows are below requested limit');
assert_same_dataforseo_duplicate_guard([], $refreshPlan['skipped_seeds'], 'No seed should be skipped when existing rows are below requested limit');

echo "unit_dataforseo_duplicate_guard: ok\n";
