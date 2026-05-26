<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class ContentResearchService {
    public static function saveCompetitorBrief(PDO $db, int $siteId, array $input, ?int $adminId = null): void {
        $seedKeyword = trim((string) ($input['seed_keyword'] ?? ''));
        if ($siteId <= 0 || $seedKeyword === '') {
            throw new InvalidArgumentException('seed_keyword_required');
        }

        $brief = [
            'search_intent' => trim((string) ($input['search_intent'] ?? '')),
            'content_angle' => trim((string) ($input['content_angle'] ?? '')),
            'gaps' => trim((string) ($input['gaps'] ?? '')),
        ];

        $stmt = $db->prepare("
            INSERT INTO competitor_briefs (
                site_id, seed_keyword, competitor_url, competitor_title, notes, brief_json, created_by_admin_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $siteId,
            mb_substr($seedKeyword, 0, 255),
            mb_substr(trim((string) ($input['competitor_url'] ?? '')), 0, 1000),
            mb_substr(trim((string) ($input['competitor_title'] ?? '')), 0, 500),
            mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000),
            json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $adminId ?: null,
        ]);
    }

    public static function recentCompetitorBriefs(PDO $db, int $siteId, int $limit = 8): array {
        $stmt = $db->prepare("
            SELECT *
            FROM competitor_briefs
            WHERE site_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

