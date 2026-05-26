<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class InternalLinkService {
    public static function opportunities(PDO $db, int $siteId, int $limit = 8): array {
        if ($siteId <= 0) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT a.id, a.title, a.slug, a.category_id, c.name AS category_name
            FROM articles a
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE a.status = 'published'
              AND a.deleted_at IS NULL
              AND a.site_id = ?
            ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $opportunities = [];
        $relatedStmt = $db->prepare("
            SELECT id, title, slug
            FROM articles
            WHERE site_id = ?
              AND status = 'published'
              AND deleted_at IS NULL
              AND category_id = ?
              AND id <> ?
            ORDER BY view_count DESC, COALESCE(published_at, created_at) DESC
            LIMIT 3
        ");

        foreach ($articles as $article) {
            $relatedStmt->execute([$siteId, (int) ($article['category_id'] ?? 0), (int) ($article['id'] ?? 0)]);
            $targets = $relatedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($targets)) {
                continue;
            }

            $opportunities[] = [
                'source' => $article,
                'targets' => $targets,
            ];
        }

        return $opportunities;
    }
}

