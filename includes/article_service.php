<?php
/**
 * 文章服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/content_quality_service.php';
require_once __DIR__ . '/search_submission_service.php';

class ArticleService {
    public function __construct(private PDO $db) {
    }

    public function listArticles(int $page = 1, int $perPage = 20, array $filters = []): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['a.deleted_at IS NULL'];
        $params = [];
        $siteScope = geoflow_site_scope_condition('articles', 'a');
        if ($siteScope !== '') {
            $where[] = $siteScope;
        }

        $map = [
            'task_id' => 'a.task_id',
            'status' => 'a.status',
            'review_status' => 'a.review_status',
            'author_id' => 'a.author_id'
        ];
        foreach ($map as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = ?";
                $params[] = $filters[$key];
            }
        }

        if (!empty($filters['search'])) {
            $where[] = '(a.title LIKE ? OR a.content LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM articles a WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "
            SELECT a.id, a.title, a.slug, a.status, a.review_status,
                   a.task_id, a.author_id, a.category_id, a.published_at,
                   a.created_at, a.updated_at
            FROM articles a
            WHERE {$whereSql}
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->db->prepare($sql);
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage)
            ]
        ];
    }

    public function createArticle(array $data): array {
        $normalized = $this->normalizeCreateInput($data);
        $workflowState = normalize_article_workflow_state(
            $normalized['status'],
            $normalized['review_status']
        );
        $slug = $normalized['slug'] ?: generate_unique_article_slug($this->db, $normalized['title']);
        $excerpt = $normalized['excerpt'] !== '' ? $normalized['excerpt'] : mb_substr(strip_tags($normalized['content']), 0, 200);
        if ($workflowState['status'] === 'published') {
            $quality = ContentQualityService::evaluate($normalized, ContentQualityService::settingsFromCurrentSite());
            if (get_setting('quality_gate_enabled', '1') === '1' && empty($quality['passed'])) {
                throw new ApiException('quality_gate_failed', '文章质量分不足，已阻止发布：' . implode('；', $quality['issues'] ?? []), 409, [
                    'quality' => $quality
                ]);
            }
        }

        $articleHasSiteId = geoflow_table_has_site_column($this->db, 'articles');
        $stmt = $this->db->prepare("
            INSERT INTO articles (
                " . ($articleHasSiteId ? "site_id, " : "") . "title, slug, content, excerpt, keywords, meta_description,
                category_id, author_id, task_id, status, review_status,
                is_ai_generated, created_at, updated_at, published_at
            ) VALUES (" . ($articleHasSiteId ? "?, " : "") . "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
        ");
        $values = [
            $normalized['title'],
            $slug,
            $normalized['content'],
            $excerpt,
            $normalized['keywords'],
            $normalized['meta_description'],
            $normalized['category_id'],
            $normalized['author_id'],
            $normalized['task_id'],
            $workflowState['status'],
            $workflowState['review_status'],
            $normalized['is_ai_generated'],
            $workflowState['published_at']
        ];
        if ($articleHasSiteId) {
            array_unshift($values, geoflow_required_site_id($this->db));
        }

        $stmt->execute($values);

        $articleId = db_last_insert_id($this->db, 'articles');
        ContentQualityService::evaluateArticleRecord($this->db, (int) $articleId);
        if ($workflowState['status'] === 'published') {
            SearchSubmissionService::queueArticle($this->db, (int) $articleId);
        }
        return $this->getArticle($articleId);
    }

    public function getArticle(int $articleId): array {
        $stmt = $this->db->prepare("
            SELECT a.*,
                   t.name AS task_name,
                   au.name AS author_name,
                   c.name AS category_name
            FROM articles a
            LEFT JOIN tasks t ON a.task_id = t.id
            LEFT JOIN authors au ON a.author_id = au.id
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.id = ? AND a.deleted_at IS NULL" . geoflow_site_scope_sql('articles', 'a') . "
            LIMIT 1
        ");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        $imagesStmt = $this->db->prepare("
            SELECT ai.id, ai.image_id, ai.position, i.file_path, i.original_name
            FROM article_images ai
            LEFT JOIN images i ON ai.image_id = i.id
            WHERE ai.article_id = ?
            ORDER BY ai.position ASC, ai.id ASC
        ");
        $imagesStmt->execute([$articleId]);

        return [
            'id' => (int) $article['id'],
            'title' => $article['title'],
            'slug' => $article['slug'],
            'content' => $article['content'],
            'excerpt' => $article['excerpt'],
            'keywords' => $article['keywords'],
            'meta_description' => $article['meta_description'],
            'status' => $article['status'],
            'review_status' => $article['review_status'],
            'task_id' => $this->nullableInt($article['task_id'] ?? null),
            'task_name' => $article['task_name'] ?? null,
            'author_id' => $this->nullableInt($article['author_id'] ?? null),
            'author_name' => $article['author_name'] ?? null,
            'category_id' => $this->nullableInt($article['category_id'] ?? null),
            'category_name' => $article['category_name'] ?? null,
            'published_at' => $article['published_at'] ?? null,
            'created_at' => $article['created_at'] ?? null,
            'updated_at' => $article['updated_at'] ?? null,
            'images' => $imagesStmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    public function updateArticle(int $articleId, array $data): array {
        $existing = $this->getArticleRecord($articleId);
        $normalized = $this->normalizeUpdateInput($data, $existing);
        if (empty($normalized)) {
            throw new ApiException('validation_failed', '没有可更新的字段', 422);
        }

        $fields = [];
        $values = [];
        foreach ($normalized as $field => $value) {
            $fields[] = "{$field} = ?";
            $values[] = $value;
        }
        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $values[] = $articleId;

        $stmt = $this->db->prepare("UPDATE articles SET " . implode(', ', $fields) . " WHERE id = ?" . geoflow_site_scope_sql('articles'));
        $stmt->execute($values);
        return $this->getArticle($articleId);
    }

    public function reviewArticle(int $articleId, string $reviewStatus, string $reviewNote, int $auditAdminId): array {
        $article = $this->getArticleRecord($articleId);
        $reviewStatus = trim($reviewStatus);
        if (!in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            throw new ApiException('validation_failed', '审核状态无效', 422, [
                'field_errors' => ['review_status' => '审核状态无效']
            ]);
        }

        $desiredStatus = $article['status'] ?? 'draft';
        if (in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            $taskNeedReview = 1;
            if (!empty($article['task_id'])) {
                $taskStmt = $this->db->prepare("SELECT need_review FROM tasks WHERE id = ?" . geoflow_site_scope_sql('tasks') . " LIMIT 1");
                $taskStmt->execute([(int) $article['task_id']]);
                $taskNeedReview = (int) ($taskStmt->fetchColumn() ?? 1);
            }

            if ($reviewStatus === 'auto_approved' || $taskNeedReview === 0) {
                $desiredStatus = 'published';
            }
        }

        $workflowState = normalize_article_workflow_state(
            $desiredStatus,
            $reviewStatus,
            $article['published_at'] ?? null
        );
        if ($workflowState['status'] === 'published') {
            $quality = ContentQualityService::guardPublish($this->db, $articleId, $article);
            if (empty($quality['allowed'])) {
                throw new ApiException('quality_gate_failed', '文章质量分不足，已阻止发布：' . implode('；', $quality['issues'] ?? []), 409, [
                    'quality' => $quality
                ]);
            }
        }

        $this->db->beginTransaction();
        try {
            $updateStmt = $this->db->prepare("
                UPDATE articles
                SET status = ?, review_status = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?" . geoflow_site_scope_sql('articles') . "
            ");
            $updateStmt->execute([
                $workflowState['status'],
                $workflowState['review_status'],
                $workflowState['published_at'],
                $articleId
            ]);

            $reviewStmt = $this->db->prepare("
                INSERT INTO article_reviews (article_id, admin_id, review_status, review_note, created_at)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $reviewStmt->execute([
                $articleId,
                $auditAdminId,
                $reviewStatus,
                trim($reviewNote)
            ]);

            $this->db->commit();
            if ($workflowState['status'] === 'published') {
                SearchSubmissionService::queueArticle($this->db, $articleId);
            }
            return $this->getArticle($articleId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function publishArticle(int $articleId): array {
        $article = $this->getArticleRecord($articleId);
        $reviewStatus = $article['review_status'] ?? 'pending';
        if (!in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            throw new ApiException('article_not_publishable', '当前文章状态不允许直接发布', 409);
        }

        $workflowState = normalize_article_workflow_state(
            'published',
            $reviewStatus,
            $article['published_at'] ?? null
        );
        $quality = ContentQualityService::guardPublish($this->db, $articleId, $article);
        if (empty($quality['allowed'])) {
            throw new ApiException('quality_gate_failed', '文章质量分不足，已阻止发布：' . implode('；', $quality['issues'] ?? []), 409, [
                'quality' => $quality
            ]);
        }

        $stmt = $this->db->prepare("
            UPDATE articles
            SET status = ?, review_status = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?" . geoflow_site_scope_sql('articles') . "
        ");
        $stmt->execute([
            $workflowState['status'],
            $workflowState['review_status'],
            $workflowState['published_at'],
            $articleId
        ]);

        SearchSubmissionService::queueArticle($this->db, $articleId);
        return $this->getArticle($articleId);
    }

    public function trashArticle(int $articleId): array {
        $stmt = $this->db->prepare("
            UPDATE articles
            SET deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND deleted_at IS NULL" . geoflow_site_scope_sql('articles') . "
        ");
        $stmt->execute([$articleId]);
        if ($stmt->rowCount() !== 1) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        return [
            'id' => $articleId,
            'trashed' => true
        ];
    }

    private function normalizeCreateInput(array $data): array {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            $errors = [];
            if ($title === '') {
                $errors['title'] = '文章标题不能为空';
            }
            if ($content === '') {
                $errors['content'] = '文章内容不能为空';
            }
            throw new ApiException('validation_failed', '参数校验失败', 422, ['field_errors' => $errors]);
        }

        $normalized = [
            'title' => $title,
            'content' => $content,
            'excerpt' => trim((string) ($data['excerpt'] ?? '')),
            'keywords' => trim((string) ($data['keywords'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
            'status' => trim((string) ($data['status'] ?? 'draft')),
            'review_status' => trim((string) ($data['review_status'] ?? 'pending')),
            'is_ai_generated' => $this->toFlag($data['is_ai_generated'] ?? 0)
        ];

        $normalized['slug'] = null;
        if (!empty($data['slug'])) {
            $slug = trim((string) $data['slug']);
            $this->ensureSlugAvailable($slug);
            $normalized['slug'] = $slug;
        }

        $normalized['category_id'] = $this->normalizeReference('categories', $data['category_id'] ?? null, 'category_id', true);
        $normalized['author_id'] = $this->normalizeReference('authors', $data['author_id'] ?? null, 'author_id', true);
        $normalized['task_id'] = $this->normalizeNullableReference('tasks', $data['task_id'] ?? null, 'task_id');

        return $normalized;
    }

    private function normalizeUpdateInput(array $data, array $existing): array {
        $normalized = [];
        $fieldErrors = [];

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                $fieldErrors['title'] = '文章标题不能为空';
            } else {
                $normalized['title'] = $title;
            }
        }

        if (array_key_exists('content', $data)) {
            $content = trim((string) $data['content']);
            if ($content === '') {
                $fieldErrors['content'] = '文章内容不能为空';
            } else {
                $normalized['content'] = $content;
            }
        }

        foreach (['excerpt', 'keywords', 'meta_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $normalized[$field] = trim((string) $data[$field]);
            }
        }

        if (array_key_exists('category_id', $data)) {
            $normalized['category_id'] = $this->normalizeReference('categories', $data['category_id'], 'category_id', true);
        }

        if (array_key_exists('author_id', $data)) {
            $normalized['author_id'] = $this->normalizeReference('authors', $data['author_id'], 'author_id', true);
        }

        if (array_key_exists('task_id', $data)) {
            $normalized['task_id'] = $this->normalizeNullableReference('tasks', $data['task_id'], 'task_id');
        }

        if (array_key_exists('slug', $data)) {
            $slug = trim((string) $data['slug']);
            if ($slug === '') {
                $fieldErrors['slug'] = 'slug 不能为空';
            } else {
                $this->ensureSlugAvailable($slug, (int) $existing['id']);
                $normalized['slug'] = $slug;
            }
        } elseif (isset($normalized['title']) && $normalized['title'] !== $existing['title']) {
            $normalized['slug'] = generate_unique_article_slug($this->db, $normalized['title'], (int) $existing['id']);
        }

        if (!empty($fieldErrors)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, ['field_errors' => $fieldErrors]);
        }

        return $normalized;
    }

    private function getArticleRecord(int $articleId): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM articles
            WHERE id = ?
              AND deleted_at IS NULL" . geoflow_site_scope_sql('articles') . "
            LIMIT 1
        ");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }
        return $article;
    }

    private function normalizeNullableReference(string $table, mixed $value, string $field): ?int {
        return $this->normalizeReference($table, $value, $field, false);
    }

    private function normalizeReference(string $table, mixed $value, string $field, bool $required = false): ?int {
        if ($value === null || $value === '' || (int) $value <= 0) {
            if ($required) {
                throw new ApiException('validation_failed', '参数校验失败', 422, [
                    'field_errors' => [$field => $this->requiredReferenceMessage($field)]
                ]);
            }
            return null;
        }

        $id = (int) $value;
        $siteScope = geoflow_site_scope_condition($table);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE id = ?" . ($siteScope !== '' ? ' AND ' . $siteScope : ''));
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => [$field => "{$field} 对应资源不存在"]
            ]);
        }

        return $id;
    }

    private function requiredReferenceMessage(string $field): string {
        return match ($field) {
            'category_id' => '请选择文章分类',
            'author_id' => '请选择文章作者',
            default => "{$field} 不能为空"
        };
    }

    private function ensureSlugAvailable(string $slug, ?int $excludeId = null): void {
        if (!$this->isSlugAvailable($slug, $excludeId)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['slug' => 'slug 已存在']
            ]);
        }
    }

    private function isSlugAvailable(string $slug, ?int $excludeId = null): bool {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM articles WHERE slug = ? AND id != ?" . geoflow_site_scope_sql('articles'));
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM articles WHERE slug = ?" . geoflow_site_scope_sql('articles'));
            $stmt->execute([$slug]);
        }

        return (int) $stmt->fetchColumn() === 0;
    }

    private function nullableInt(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function toFlag(mixed $value): int {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) $value > 0 ? 1 : 0;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
