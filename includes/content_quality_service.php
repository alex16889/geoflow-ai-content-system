<?php
/**
 * Lightweight article quality scoring and publish gates.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class ContentQualityService {
    public const DEFAULT_MIN_SCORE = 65;
    public const DEFAULT_MIN_WORDS = 300;

    public static function evaluate(array $article, array $options = []): array {
        $minScore = max(0, min(100, (int) ($options['min_score'] ?? self::DEFAULT_MIN_SCORE)));
        $minWords = max(50, (int) ($options['min_words'] ?? self::DEFAULT_MIN_WORDS));

        $title = trim((string) ($article['title'] ?? ''));
        $content = trim((string) ($article['content'] ?? ''));
        $description = trim((string) ($article['meta_description'] ?? ''));
        $keywords = trim((string) ($article['keywords'] ?? ''));
        $plainContent = trim(strip_tags($content));
        $wordCount = self::countContentWords($plainContent);
        $issues = [];
        $score = 0;

        $titleLength = mb_strlen($title);
        if ($titleLength >= 20 && $titleLength <= 90) {
            $score += 15;
        } elseif ($titleLength >= 10 && $titleLength <= 120) {
            $score += 8;
        } else {
            $issues[] = $titleLength < 10 ? '标题过短' : '标题过长';
        }

        if ($wordCount >= $minWords) {
            $score += 30;
        } else {
            $score += (int) floor(30 * min(1, $wordCount / $minWords));
            $issues[] = '正文过短，当前约 ' . $wordCount . ' 词，建议至少 ' . $minWords . ' 词';
        }

        $descriptionLength = mb_strlen($description);
        if ($descriptionLength >= 70 && $descriptionLength <= 180) {
            $score += 15;
        } elseif ($descriptionLength > 0) {
            $score += 7;
            $issues[] = '描述长度不理想';
        } else {
            $issues[] = '缺少 meta description';
        }

        $keywordCount = self::countKeywords($keywords);
        if ($keywordCount >= 3) {
            $score += 10;
        } elseif ($keywordCount > 0) {
            $score += 5;
            $issues[] = '关键词数量偏少';
        } else {
            $issues[] = '缺少关键词';
        }

        $headingCount = preg_match_all('/(^|\n)\s{0,3}(#{2,3}\s+|<h[23][^>]*>)/iu', $content);
        if ($headingCount >= 2) {
            $score += 15;
        } elseif ($headingCount === 1) {
            $score += 7;
            $issues[] = '内容结构偏弱，建议增加小标题';
        } else {
            $issues[] = '缺少清晰的小标题结构';
        }

        if (preg_match('/https?:\/\/[^\s)]+/i', $content) || str_contains($content, '引用') || str_contains($content, '来源')) {
            $score += 15;
        } else {
            $issues[] = '缺少来源、引用或证据链接';
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'issues' => $issues,
            'word_count' => $wordCount,
            'min_score' => $minScore,
            'passed' => $score >= $minScore,
        ];
    }

    public static function settingsFromCurrentSite(): array {
        $enabled = function_exists('get_setting') ? (string) get_setting('quality_gate_enabled', '1') : '1';
        $minScore = function_exists('get_setting') ? (int) get_setting('quality_gate_min_score', (string) self::DEFAULT_MIN_SCORE) : self::DEFAULT_MIN_SCORE;
        $minWords = function_exists('get_setting') ? (int) get_setting('quality_gate_min_words', (string) self::DEFAULT_MIN_WORDS) : self::DEFAULT_MIN_WORDS;

        return [
            'enabled' => $enabled !== '0',
            'min_score' => max(0, min(100, $minScore)),
            'min_words' => max(50, $minWords),
        ];
    }

    public static function evaluateArticleRecord(PDO $db, int $articleId, ?array $article = null): array {
        if ($article === null) {
            $stmt = $db->prepare("SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL" . geoflow_site_scope_sql('articles') . " LIMIT 1");
            $stmt->execute([$articleId]);
            $article = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        if (empty($article)) {
            throw new RuntimeException('文章不存在，无法执行质量检查');
        }

        $settings = self::settingsFromCurrentSite();
        $result = self::evaluate($article, [
            'min_score' => $settings['min_score'],
            'min_words' => $settings['min_words'],
        ]);
        $result['enabled'] = (bool) $settings['enabled'];

        self::persistArticleQuality($db, $articleId, $result);

        return $result;
    }

    public static function guardPublish(PDO $db, int $articleId, ?array $article = null): array {
        $result = self::evaluateArticleRecord($db, $articleId, $article);
        if (!($result['enabled'] ?? true)) {
            $result['allowed'] = true;
            return $result;
        }

        $result['allowed'] = (bool) ($result['passed'] ?? false);
        return $result;
    }

    public static function persistArticleQuality(PDO $db, int $articleId, array $result): void {
        if (!geoflow_db_table_exists($db, 'articles')) {
            return;
        }

        $columns = [
            'quality_score' => db_column_exists($db, 'articles', 'quality_score'),
            'quality_issues' => db_column_exists($db, 'articles', 'quality_issues'),
            'quality_checked_at' => db_column_exists($db, 'articles', 'quality_checked_at'),
        ];

        if (!$columns['quality_score'] && !$columns['quality_issues'] && !$columns['quality_checked_at']) {
            return;
        }

        $sets = [];
        $values = [];
        if ($columns['quality_score']) {
            $sets[] = 'quality_score = ?';
            $values[] = (int) ($result['score'] ?? 0);
        }
        if ($columns['quality_issues']) {
            $sets[] = 'quality_issues = ?';
            $values[] = json_encode($result['issues'] ?? [], JSON_UNESCAPED_UNICODE);
        }
        if ($columns['quality_checked_at']) {
            $sets[] = 'quality_checked_at = CURRENT_TIMESTAMP';
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $articleId;
        $stmt = $db->prepare('UPDATE articles SET ' . implode(', ', $sets) . ' WHERE id = ?' . geoflow_site_scope_sql('articles'));
        $stmt->execute($values);
    }

    private static function countKeywords(string $keywords): int {
        if ($keywords === '') {
            return 0;
        }

        $parts = preg_split('/[\s,，;；]+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return count(array_unique(array_map(static fn($item) => mb_strtolower(trim((string) $item)), $parts)));
    }

    private static function countContentWords(string $content): int {
        if ($content === '') {
            return 0;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $content, $matches);
        $tokenCount = count($matches[0] ?? []);
        $compactLength = mb_strlen(preg_replace('/\s+/u', '', $content) ?? '');
        $estimatedCjkWords = (int) ceil($compactLength / 6);
        return max($tokenCount, $estimatedCjkWords);
    }
}
