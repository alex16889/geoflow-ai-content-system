<?php
/**
 * 将现有 SQLite 数据迁移到 PostgreSQL
 *
 * 用法:
 *   DB_DRIVER=pgsql php bin/migrate_sqlite_to_pg.php [sqlite_path]
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';

if (!db_is_pgsql()) {
    fwrite(STDERR, "当前 DB_DRIVER 不是 pgsql，已停止迁移。\n");
    exit(1);
}

$sourcePath = $argv[1] ?? (getenv('SQLITE_IMPORT_PATH') ?: db_get_sqlite_path());
if (!is_file($sourcePath)) {
    fwrite(STDERR, "SQLite 源文件不存在: {$sourcePath}\n");
    exit(1);
}

$source = db_create_sqlite_pdo($sourcePath);

$target = $db;

$tableOrder = [
    'categories',
    'tags',
    'admins',
    'settings',
    'site_settings',
    'ai_models',
    'prompts',
    'authors',
    'keyword_libraries',
    'keywords',
    'title_libraries',
    'titles',
    'image_libraries',
    'images',
    'knowledge_bases',
    'tasks',
    'task_schedules',
    'articles',
    'article_tags',
    'comments',
    'view_logs',
    'article_queue',
    'task_materials',
    'system_logs',
    'article_reviews',
    'article_images',
    'sensitive_words',
    'submissions',
    'url_import_jobs',
    'url_import_job_logs',
    'job_queue',
    'task_runs',
    'worker_heartbeats',
];

$fallbackAuthorIdStmt = $source->query("SELECT MIN(id) FROM authors");
$fallbackAuthorId = (int) ($fallbackAuthorIdStmt ? $fallbackAuthorIdStmt->fetchColumn() : 0);
$fallbackAuthorId = $fallbackAuthorId > 0 ? $fallbackAuthorId : 1;

function source_table_exists(PDO $source, string $table): bool {
    $stmt = $source->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function target_columns(PDO $target, string $table): array {
    $stmt = $target->prepare("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = ?
        ORDER BY ordinal_position
    ");
    $stmt->execute([$table]);
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['column_name']] = $row['data_type'];
    }
    return $columns;
}

function normalize_value(mixed $value, string $dataType): mixed {
    if ($value === null) {
        return null;
    }

    if ($value === '') {
        if ($dataType === 'boolean') {
            return 'false';
        }

        if (in_array($dataType, ['integer', 'bigint', 'smallint', 'numeric', 'double precision', 'real'], true)) {
            return 0;
        }
    }

    if ($dataType === 'boolean') {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 't', 'yes', 'y'], true) ? 'true' : 'false';
        }
        return $value ? 'true' : 'false';
    }

    return $value;
}

function normalize_table_value(string $table, string $column, mixed $value, string $dataType, int $fallbackAuthorId): mixed {
    if ($table === 'articles' && $column === 'author_id' && ($value === null || $value === '')) {
        return $fallbackAuthorId;
    }

    if ($table === 'tasks' && $column === 'author_id' && $value === '') {
        return null;
    }

    return normalize_value($value, $dataType);
}

function reset_sequence(PDO $target, string $table): void {
    $stmt = $target->prepare("
        SELECT setval(
            pg_get_serial_sequence(?, 'id'),
            COALESCE((SELECT MAX(id) FROM " . $table . "), 1),
            (SELECT COUNT(*) > 0 FROM " . $table . ")
        )
    ");
    $stmt->execute([$table]);
}

try {
    $target->beginTransaction();

    foreach (array_reverse($tableOrder) as $table) {
        $targetColumnMap = target_columns($target, $table);
        if (empty($targetColumnMap)) {
            continue;
        }
        $target->exec("TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE");
    }

    foreach ($tableOrder as $table) {
        if (!source_table_exists($source, $table)) {
            continue;
        }

        $targetColumnMap = target_columns($target, $table);
        if (empty($targetColumnMap)) {
            continue;
        }

        $rows = $source->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "skip {$table}: no rows\n";
            continue;
        }

        $sourceColumns = array_keys($rows[0]);
        $columns = array_values(array_intersect($sourceColumns, array_keys($targetColumnMap)));
        if (empty($columns)) {
            echo "skip {$table}: no overlapping columns\n";
            continue;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', $columns);
        $insert = $target->prepare("INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})");

        $inserted = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = normalize_table_value($table, $column, $row[$column] ?? null, $targetColumnMap[$column] ?? 'text', $fallbackAuthorId);
            }
            $insert->execute($values);
            $inserted++;
        }

        if (array_key_exists('id', $targetColumnMap)) {
            reset_sequence($target, $table);
        }

        echo "migrated {$table}: {$inserted} rows\n";
    }

    $target->commit();
    echo "migration complete\n";
} catch (Throwable $e) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, "迁移失败: {$e->getMessage()}\n");
    exit(1);
}
