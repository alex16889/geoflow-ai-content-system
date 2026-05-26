<?php
/**
 * Catalog 资源服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class CatalogService {
    public function __construct(private PDO $db) {
    }

    public function getCatalog(): array {
        $models = $this->db->query("
            SELECT id, name, model_id, COALESCE(NULLIF(model_type, ''), 'chat') AS model_type, status
            FROM ai_models
            WHERE status = 'active'
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $prompts = $this->db->query("
            SELECT id, name, type
            FROM prompts
            WHERE type = 'content'
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $titleLibraries = $this->db->query("
            SELECT tl.id, tl.name,
                   (SELECT COUNT(*) FROM titles t WHERE t.library_id = tl.id) AS title_count
            FROM title_libraries tl
            ORDER BY tl.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $knowledgeBases = $this->db->query("
            SELECT id, name
            FROM knowledge_bases
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $authors = $this->db->query("
            SELECT id, name
            FROM authors
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $categories = $this->db->query("
            SELECT id, name, slug
            FROM categories
            ORDER BY sort_order ASC, name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'models' => $models,
            'prompts' => $prompts,
            'title_libraries' => $titleLibraries,
            'knowledge_bases' => $knowledgeBases,
            'authors' => $authors,
            'categories' => $categories
        ];
    }
}
