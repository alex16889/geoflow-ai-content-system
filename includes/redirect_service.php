<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class RedirectService {
    public static function normalizePath(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $parsedPath = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : $path;
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/');
        return $path === '' ? '/' : mb_substr($path, 0, 500);
    }

    public static function normalizeTarget(string $target): string {
        $target = trim($target);
        if ($target === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $target)) {
            return mb_substr($target, 0, 1000);
        }

        if (str_starts_with($target, '/')) {
            return self::normalizePath($target);
        }

        return self::normalizePath('/' . $target);
    }

    public static function serveIfMatched(PDO $db, string $path): bool {
        if (!geoflow_db_table_exists($db, 'redirect_rules')) {
            return false;
        }

        $sourcePath = self::normalizePath($path);
        $siteId = geoflow_current_site_id();
        if ($siteId <= 0) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT id, target_url, status_code
            FROM redirect_rules
            WHERE site_id = ?
              AND source_path = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$siteId, $sourcePath]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            return false;
        }

        $target = self::normalizeTarget((string) ($rule['target_url'] ?? ''));
        if ($target === '' || $target === $sourcePath) {
            return false;
        }

        $update = $db->prepare("
            UPDATE redirect_rules
            SET hit_count = hit_count + 1,
                last_hit_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([(int) $rule['id']]);

        $status = (int) ($rule['status_code'] ?? 301);
        if (!in_array($status, [301, 302], true)) {
            $status = 301;
        }

        header('Location: ' . $target, true, $status);
        return true;
    }

    public static function logNotFound(PDO $db, string $path): void {
        if (!geoflow_db_table_exists($db, 'not_found_logs')) {
            return;
        }

        $siteId = geoflow_current_site_id();
        $path = self::normalizePath($path);
        if ($siteId <= 0 || $path === '/' || self::shouldSkipLogging($path)) {
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO not_found_logs (site_id, path, referrer, user_agent, hit_count, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (site_id, path)
            DO UPDATE SET
                hit_count = not_found_logs.hit_count + 1,
                referrer = EXCLUDED.referrer,
                user_agent = EXCLUDED.user_agent,
                last_seen_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $siteId,
            $path,
            mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
        ]);
    }

    public static function saveRule(PDO $db, int $siteId, array $input): void {
        $source = self::normalizePath((string) ($input['source_path'] ?? ''));
        $target = self::normalizeTarget((string) ($input['target_url'] ?? ''));
        $status = (int) ($input['status_code'] ?? 301);

        if ($siteId <= 0 || $source === '/' || $target === '' || $source === $target) {
            throw new InvalidArgumentException('invalid_redirect_rule');
        }

        if (!in_array($status, [301, 302], true)) {
            $status = 301;
        }

        $stmt = $db->prepare("
            INSERT INTO redirect_rules (
                site_id, source_path, target_url, status_code, is_active, notes, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (site_id, source_path)
            DO UPDATE SET
                target_url = EXCLUDED.target_url,
                status_code = EXCLUDED.status_code,
                is_active = EXCLUDED.is_active,
                notes = EXCLUDED.notes,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $siteId,
            $source,
            $target,
            $status,
            !empty($input['is_active']) ? 1 : 0,
            mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000),
        ]);
    }

    public static function deleteRule(PDO $db, int $siteId, int $ruleId): void {
        $stmt = $db->prepare("DELETE FROM redirect_rules WHERE site_id = ? AND id = ?");
        $stmt->execute([$siteId, $ruleId]);
    }

    public static function rules(PDO $db, int $siteId, int $limit = 10): array {
        $stmt = $db->prepare("
            SELECT *
            FROM redirect_rules
            WHERE site_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function recent404(PDO $db, int $siteId, int $limit = 10): array {
        $stmt = $db->prepare("
            SELECT *
            FROM not_found_logs
            WHERE site_id = ?
            ORDER BY last_seen_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function shouldSkipLogging(string $path): bool {
        return (bool) preg_match('#^/(?:assets|uploads|themes|api|geo_admin|admin|favicon\.ico)#i', $path);
    }
}

