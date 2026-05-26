<?php
/**
 * 数据库维护脚本
 *
 * 用法:
 *   php bin/db_maintenance.php check
 *   php bin/db_maintenance.php backup
 *   php bin/db_maintenance.php checkpoint
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';

set_time_limit(300);
ini_set('memory_limit', '256M');

$command = $argv[1] ?? 'check';
$backupDir = $projectRoot . '/data/backups';

if (db_is_sqlite()) {
    $dbPath = DB_PATH;
    if (!file_exists($dbPath)) {
        fwrite(STDERR, "SQLite 数据库文件不存在: {$dbPath}\n");
        exit(1);
    }
}

if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "无法创建备份目录: {$backupDir}\n");
    exit(1);
}

try {
    $pdo = db_is_pgsql() ? db_create_pgsql_pdo() : db_create_sqlite_pdo();
} catch (PDOException $e) {
    fwrite(STDERR, "数据库连接失败: {$e->getMessage()}\n");
    exit(1);
}

switch ($command) {
    case 'check':
        if (db_is_pgsql()) {
            $tableCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn();
            echo "driver: pgsql\n";
            echo "database: " . $pdo->query("SELECT current_database()")->fetchColumn() . "\n";
            echo "host: " . (getenv('DB_HOST') ?: 'postgres') . "\n";
            echo "table_count: {$tableCount}\n";
            echo "connection_check: ok\n";
            exit(0);
        }

        $quickCheck = (string) $pdo->query("PRAGMA quick_check")->fetchColumn();
        $integrityCheck = (string) $pdo->query("PRAGMA integrity_check")->fetchColumn();

        echo "quick_check: {$quickCheck}\n";
        echo "integrity_check: {$integrityCheck}\n";
        echo "journal_mode: " . $pdo->query("PRAGMA journal_mode")->fetchColumn() . "\n";
        echo "synchronous: " . $pdo->query("PRAGMA synchronous")->fetchColumn() . "\n";

        exit(($quickCheck === 'ok' && $integrityCheck === 'ok') ? 0 : 2);

    case 'checkpoint':
        if (db_is_pgsql()) {
            $pdo->exec("CHECKPOINT");
            echo "checkpoint: ok\n";
            exit(0);
        }

        $result = $pdo->query("PRAGMA wal_checkpoint(TRUNCATE)")->fetch(PDO::FETCH_NUM) ?: [];
        echo "wal_checkpoint(TRUNCATE): " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);

    case 'backup':
        if (db_is_pgsql()) {
            $timestamp = date('Ymd_His');
            $backupPath = $backupDir . "/pg_backup_{$timestamp}.dump";
            $pgDump = trim((string) shell_exec('command -v pg_dump'));
            if ($pgDump === '') {
                fwrite(STDERR, "未找到 pg_dump，无法执行 PostgreSQL 备份。\n");
                exit(1);
            }

            $dsnHost = getenv('DB_HOST') ?: 'postgres';
            $dsnPort = getenv('DB_PORT') ?: '5432';
            $dsnName = getenv('DB_NAME') ?: 'geo_system';
            $dsnUser = getenv('DB_USER') ?: 'geo_user';
            $dsnPassword = getenv('DB_PASSWORD') ?: 'geo_password';

            $command = 'PGPASSWORD=' . escapeshellarg($dsnPassword) . ' '
                . escapeshellarg($pgDump)
                . ' -h ' . escapeshellarg($dsnHost)
                . ' -p ' . escapeshellarg($dsnPort)
                . ' -U ' . escapeshellarg($dsnUser)
                . ' -Fc '
                . escapeshellarg($dsnName)
                . ' -f ' . escapeshellarg($backupPath)
                . ' 2>&1';

            exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                fwrite(STDERR, "pg_dump 失败:\n" . implode("\n", $output) . "\n");
                exit($exitCode);
            }

            echo "backup_created: {$backupPath}\n";
            echo "backup_size_bytes: " . filesize($backupPath) . "\n";
            echo "backup_format: pg_dump_custom\n";
            exit(0);
        }

        $timestamp = date('Ymd_His');
        $backupPath = $backupDir . "/blog_backup_{$timestamp}.db";
        $quotedBackupPath = str_replace("'", "''", $backupPath);

        $quickCheck = (string) $pdo->query("PRAGMA quick_check")->fetchColumn();
        if ($quickCheck !== 'ok') {
            fwrite(STDERR, "数据库 quick_check 失败，已拒绝备份: {$quickCheck}\n");
            exit(2);
        }

        $pdo->exec("PRAGMA wal_checkpoint(TRUNCATE)");
        $pdo->exec("VACUUM INTO '{$quotedBackupPath}'");

        echo "backup_created: {$backupPath}\n";
        echo "backup_size_bytes: " . filesize($backupPath) . "\n";
        echo "backup_format: sqlite_vacuum_into\n";
        exit(0);

    default:
        fwrite(STDERR, "未知命令: {$command}\n");
        fwrite(STDERR, "可用命令: check | backup | checkpoint\n");
        exit(1);
}
