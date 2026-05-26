<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class VisibilityTrackingService {
    public static function saveSearchSnapshot(PDO $db, int $siteId, array $input): void {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site_required');
        }

        $source = self::normalizeSource((string) ($input['source'] ?? 'manual'));
        $date = trim((string) ($input['snapshot_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $clicks = max(0, (int) ($input['clicks'] ?? 0));
        $impressions = max(0, (int) ($input['impressions'] ?? 0));
        $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 4) : null;

        $stmt = $db->prepare("
            INSERT INTO search_performance_snapshots (
                site_id, source, snapshot_date, query, page_url, clicks, impressions, ctr, avg_position, notes, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $siteId,
            $source,
            $date,
            mb_substr(trim((string) ($input['query'] ?? '')), 0, 500),
            mb_substr(trim((string) ($input['page_url'] ?? '')), 0, 1000),
            $clicks,
            $impressions,
            $ctr,
            ($input['avg_position'] ?? '') !== '' ? max(0, (float) $input['avg_position']) : null,
            mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000),
        ]);
    }

    public static function saveAiCheck(PDO $db, int $siteId, array $input): void {
        $query = trim((string) ($input['query'] ?? ''));
        if ($siteId <= 0 || $query === '') {
            throw new InvalidArgumentException('query_required');
        }

        $score = ($input['visibility_score'] ?? '') !== '' ? max(0, min(100, (int) $input['visibility_score'])) : null;
        $stmt = $db->prepare("
            INSERT INTO ai_visibility_checks (
                site_id, provider, query, brand_mentioned, citation_url, answer_excerpt, visibility_score, notes, checked_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $siteId,
            mb_substr(trim((string) ($input['provider'] ?? 'manual')), 0, 80),
            mb_substr($query, 0, 800),
            !empty($input['brand_mentioned']) ? 1 : 0,
            mb_substr(trim((string) ($input['citation_url'] ?? '')), 0, 1000),
            mb_substr(trim((string) ($input['answer_excerpt'] ?? '')), 0, 2000),
            $score,
            mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000),
        ]);
    }

    public static function recentSearchSnapshots(PDO $db, int $siteId, int $limit = 8): array {
        $stmt = $db->prepare("
            SELECT *
            FROM search_performance_snapshots
            WHERE site_id = ?
            ORDER BY snapshot_date DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function recentAiChecks(PDO $db, int $siteId, int $limit = 8): array {
        $stmt = $db->prepare("
            SELECT *
            FROM ai_visibility_checks
            WHERE site_id = ?
            ORDER BY checked_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function normalizeSource(string $source): string {
        $source = trim($source);
        $allowed = ['google_search_console', 'bing_webmaster', 'manual', 'other'];
        return in_array($source, $allowed, true) ? $source : 'manual';
    }
}

