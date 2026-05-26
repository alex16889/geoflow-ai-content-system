<?php
/**
 * 知识切块与检索
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/embedding-service.php';

function knowledge_retrieval_vector_dimensions(): int {
    return 256;
}

function knowledge_retrieval_normalize_text(string $text): string {
    $text = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE3\x80\x80"], ['', ' ', ' '], $text);
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/u", "\n", $text);
    $text = preg_replace("/[ \t]+\n/u", "\n", $text);
    $text = preg_replace("/\n[ \t]+/u", "\n", $text);
    $text = preg_replace("/[ \t]{2,}/u", ' ', $text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);
    return trim((string) $text);
}

function knowledge_retrieval_extract_tokens(string $text): array {
    $normalized = knowledge_retrieval_normalize_text(mb_strtolower($text, 'UTF-8'));
    if ($normalized === '') {
        return [];
    }

    $tokens = [];

    if (preg_match_all('/[a-z0-9][a-z0-9._+#-]{1,}/u', $normalized, $latinMatches)) {
        foreach ($latinMatches[0] as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
    }

    if (preg_match_all('/[\p{Han}]{2,32}/u', $normalized, $hanMatches)) {
        foreach ($hanMatches[0] as $sequence) {
            $sequence = trim((string) $sequence);
            if ($sequence === '') {
                continue;
            }

            $length = mb_strlen($sequence, 'UTF-8');
            if ($length <= 4) {
                $tokens[] = $sequence;
            }

            $maxGram = $length >= 3 ? 3 : 2;
            for ($gram = 2; $gram <= $maxGram; $gram++) {
                if ($length < $gram) {
                    continue;
                }
                for ($offset = 0; $offset <= $length - $gram; $offset++) {
                    $tokens[] = mb_substr($sequence, $offset, $gram, 'UTF-8');
                }
            }
        }
    }

    return array_values(array_filter($tokens, static fn ($token) => $token !== ''));
}

function knowledge_retrieval_term_frequencies(string $text): array {
    $frequencies = [];
    foreach (knowledge_retrieval_extract_tokens($text) as $token) {
        if (!isset($frequencies[$token])) {
            $frequencies[$token] = 0;
        }
        $frequencies[$token]++;
    }

    return $frequencies;
}

function knowledge_retrieval_build_vector(string $text, ?int $dimensions = null): array {
    $dimensions = $dimensions ?: knowledge_retrieval_vector_dimensions();
    $vector = array_fill(0, $dimensions, 0.0);
    $frequencies = knowledge_retrieval_term_frequencies($text);

    if (empty($frequencies)) {
        return $vector;
    }

    foreach ($frequencies as $token => $count) {
        $indexSeed = abs((int) crc32('i:' . $token));
        $signSeed = abs((int) crc32('s:' . $token));
        $index = $indexSeed % $dimensions;
        $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
        $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
        $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
        $vector[$index] += $sign * $weight;
    }

    $norm = 0.0;
    foreach ($vector as $value) {
        $norm += $value * $value;
    }

    if ($norm <= 0.0) {
        return $vector;
    }

    $norm = sqrt($norm);
    foreach ($vector as $index => $value) {
        $vector[$index] = $value / $norm;
    }

    return $vector;
}

function knowledge_retrieval_split_long_text(string $text, int $maxChars): array {
    $text = knowledge_retrieval_normalize_text($text);
    if ($text === '') {
        return [];
    }

    $segments = preg_split('/(?<=[。！？!?；;])/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($segments === false || empty($segments)) {
        $segments = [$text];
    }

    $chunks = [];
    $buffer = '';

    foreach ($segments as $segment) {
        $segment = knowledge_retrieval_normalize_text($segment);
        if ($segment === '') {
            continue;
        }

        if (mb_strlen($segment, 'UTF-8') > $maxChars) {
            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            $length = mb_strlen($segment, 'UTF-8');
            for ($offset = 0; $offset < $length; $offset += $maxChars) {
                $piece = knowledge_retrieval_normalize_text(mb_substr($segment, $offset, $maxChars, 'UTF-8'));
                if ($piece !== '') {
                    $chunks[] = $piece;
                }
            }
            continue;
        }

        $candidate = $buffer === '' ? $segment : $buffer . ' ' . $segment;
        if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
            $buffer = $candidate;
            continue;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        $buffer = $segment;
    }

    if ($buffer !== '') {
        $chunks[] = $buffer;
    }

    return $chunks;
}

function knowledge_retrieval_chunk_text(string $content, int $maxChars = 900): array {
    $content = knowledge_retrieval_normalize_text($content);
    if ($content === '') {
        return [];
    }

    $paragraphs = preg_split("/\n{2,}/u", $content, -1, PREG_SPLIT_NO_EMPTY);
    if ($paragraphs === false || empty($paragraphs)) {
        $paragraphs = [$content];
    }

    $chunks = [];
    $buffer = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = knowledge_retrieval_normalize_text($paragraph);
        if ($paragraph === '') {
            continue;
        }

        if (mb_strlen($paragraph, 'UTF-8') > $maxChars) {
            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            foreach (knowledge_retrieval_split_long_text($paragraph, $maxChars) as $piece) {
                $chunks[] = $piece;
            }
            continue;
        }

        $candidate = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
        if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
            $buffer = $candidate;
            continue;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        $buffer = $paragraph;
    }

    if ($buffer !== '') {
        $chunks[] = $buffer;
    }

    return array_values(array_filter(array_map('knowledge_retrieval_normalize_text', $chunks)));
}

function knowledge_retrieval_pgvector_storage_available(PDO $db): bool {
    static $cache = [];

    $cacheKey = spl_object_id($db);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $cache[$cacheKey] = embedding_service_pgvector_available($db)
        && db_column_exists($db, 'knowledge_chunks', 'embedding_vector');

    return $cache[$cacheKey];
}

function knowledge_retrieval_generate_chunk_embeddings(PDO $db, array $chunks): array {
    if (empty($chunks) || !knowledge_retrieval_pgvector_storage_available($db)) {
        return [];
    }

    try {
        $model = embedding_service_get_default_model($db);
        if (!$model) {
            return [];
        }

        $results = [];
        $batchSize = 12;
        for ($offset = 0; $offset < count($chunks); $offset += $batchSize) {
            $batch = array_slice($chunks, $offset, $batchSize);
            foreach (embedding_service_generate_embeddings($db, $batch, $model) as $item) {
                $results[] = $item;
            }
        }

        return count($results) === count($chunks) ? $results : [];
    } catch (Throwable $e) {
        error_log('知识库 embedding 生成失败，将回退到轻量检索: ' . $e->getMessage());
        return [];
    }
}

function knowledge_retrieval_sync_chunks(PDO $db, int $knowledgeBaseId, string $content): int {
    $knowledgeBaseId = (int) $knowledgeBaseId;
    if ($knowledgeBaseId <= 0) {
        return 0;
    }

    $chunkTexts = knowledge_retrieval_chunk_text($content);
    $chunks = [];
    foreach ($chunkTexts as $index => $chunkText) {
        if ($chunkText === '') {
            continue;
        }

        $chunks[] = [
            'chunk_index' => $index,
            'content' => $chunkText,
        ];
    }

    $realEmbeddings = knowledge_retrieval_generate_chunk_embeddings(
        $db,
        array_map(static fn (array $chunk): string => $chunk['content'], $chunks)
    );
    $usePgvector = knowledge_retrieval_pgvector_storage_available($db) && count($realEmbeddings) === count($chunks);

    $deleteStmt = $db->prepare("DELETE FROM knowledge_chunks WHERE knowledge_base_id = ?");
    if ($usePgvector) {
        $insertStmt = $db->prepare("
            INSERT INTO knowledge_chunks (
                knowledge_base_id, chunk_index, content, content_hash, token_count, embedding_json,
                embedding_model_id, embedding_dimensions, embedding_provider, embedding_vector,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS vector), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
    } else {
        $insertStmt = $db->prepare("
            INSERT INTO knowledge_chunks (
                knowledge_base_id, chunk_index, content, content_hash, token_count, embedding_json,
                embedding_model_id, embedding_dimensions, embedding_provider, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, NULL, 0, '', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
    }

    $startedTransaction = !$db->inTransaction();
    if ($startedTransaction) {
        $db->beginTransaction();
    }

    try {
        $deleteStmt->execute([$knowledgeBaseId]);

        $inserted = 0;
        foreach ($chunks as $position => $chunk) {
            $chunkContent = (string) $chunk['content'];
            $tokens = knowledge_retrieval_extract_tokens($chunkContent);
            $fallbackVector = knowledge_retrieval_build_vector($chunkContent);

            $params = [
                $knowledgeBaseId,
                (int) $chunk['chunk_index'],
                $chunkContent,
                hash('sha256', $chunkContent),
                count($tokens),
                json_encode($fallbackVector, JSON_UNESCAPED_UNICODE),
            ];

            if ($usePgvector) {
                $embedding = $realEmbeddings[$position];
                $params[] = (int) ($embedding['model_id'] ?? 0);
                $params[] = (int) ($embedding['dimensions'] ?? 0);
                $params[] = (string) ($embedding['provider'] ?? '');
                $params[] = (string) ($embedding['vector_literal'] ?? '[]');
            }

            $insertStmt->execute($params);
            $inserted++;
        }

        if ($startedTransaction) {
            $db->commit();
        }

        return $inserted;
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function knowledge_retrieval_ensure_chunks(PDO $db, int $knowledgeBaseId, string $content): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE knowledge_base_id = ?");
    $stmt->execute([$knowledgeBaseId]);
    $count = (int) $stmt->fetchColumn();

    if ($count > 0 || trim($content) === '') {
        return $count;
    }

    return knowledge_retrieval_sync_chunks($db, $knowledgeBaseId, $content);
}

function knowledge_retrieval_dot_product(array $left, array $right): float {
    $sum = 0.0;
    $limit = min(count($left), count($right));
    for ($index = 0; $index < $limit; $index++) {
        $sum += (float) $left[$index] * (float) $right[$index];
    }
    return $sum;
}

function knowledge_retrieval_lexical_score(array $queryTerms, array $chunkTerms): float {
    if (empty($queryTerms) || empty($chunkTerms)) {
        return 0.0;
    }

    $matched = 0;
    $total = 0;
    foreach ($queryTerms as $token => $count) {
        $total += $count;
        if (isset($chunkTerms[$token])) {
            $matched += min($count, $chunkTerms[$token]);
        }
    }

    if ($total <= 0) {
        return 0.0;
    }

    return $matched / $total;
}

function knowledge_retrieval_fetch_context(PDO $db, int $knowledgeBaseId, string $query, int $limit = 4, int $maxChars = 2400): array {
    $query = knowledge_retrieval_normalize_text($query);
    $chunks = [];

    if ($query !== '' && knowledge_retrieval_pgvector_storage_available($db)) {
        try {
            $queryEmbedding = embedding_service_generate_embeddings($db, [$query]);
            if (!empty($queryEmbedding[0]['vector_literal'])) {
                $candidateLimit = max($limit * 3, 8);
                $stmt = $db->prepare("
                    SELECT id, chunk_index, content, embedding_json, token_count,
                           (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                    FROM knowledge_chunks
                    WHERE knowledge_base_id = ?
                      AND embedding_vector IS NOT NULL
                    ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                    LIMIT CAST(? AS INTEGER)
                ");
                $stmt->execute([
                    (string) $queryEmbedding[0]['vector_literal'],
                    $knowledgeBaseId,
                    (string) $queryEmbedding[0]['vector_literal'],
                    $candidateLimit,
                ]);
                $chunks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            error_log('知识库 pgvector 检索失败，将回退到轻量检索: ' . $e->getMessage());
        }
    }

    if (empty($chunks)) {
        $stmt = $db->prepare("
            SELECT id, chunk_index, content, embedding_json, token_count
            FROM knowledge_chunks
            WHERE knowledge_base_id = ?
            ORDER BY chunk_index ASC
        ");
        $stmt->execute([$knowledgeBaseId]);
        $chunks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($chunks)) {
        return [
            'context' => '',
            'chunks' => [],
        ];
    }

    $queryVector = knowledge_retrieval_build_vector($query);
    $queryTerms = knowledge_retrieval_term_frequencies($query);
    $scored = [];

    foreach ($chunks as $chunk) {
        $chunkVector = json_decode((string) ($chunk['embedding_json'] ?? '[]'), true);
        if (!is_array($chunkVector) || empty($chunkVector)) {
            $chunkVector = knowledge_retrieval_build_vector((string) ($chunk['content'] ?? ''));
        }

        $chunkTerms = knowledge_retrieval_term_frequencies((string) ($chunk['content'] ?? ''));
        $vectorScore = $query === '' ? 0.0 : knowledge_retrieval_dot_product($queryVector, $chunkVector);
        if (isset($chunk['vector_distance']) && $chunk['vector_distance'] !== null) {
            $vectorScore = max($vectorScore, 1.0 - (float) $chunk['vector_distance']);
        }
        $lexicalScore = $query === '' ? 0.0 : knowledge_retrieval_lexical_score($queryTerms, $chunkTerms);
        $positionBonus = max(0.0, 0.05 - ((int) ($chunk['chunk_index'] ?? 0) * 0.004));
        $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25) + $positionBonus;

        $chunk['score'] = $score;
        $scored[] = $chunk;
    }

    usort($scored, static function (array $left, array $right): int {
        $scoreDiff = ($right['score'] <=> $left['score']);
        if ($scoreDiff !== 0) {
            return $scoreDiff;
        }

        return ((int) $left['chunk_index']) <=> ((int) $right['chunk_index']);
    });

    $selected = [];
    $charCount = 0;
    $fallbackMode = $query === '' || (($scored[0]['score'] ?? 0.0) <= 0.02);
    $source = $fallbackMode ? $chunks : $scored;

    foreach ($source as $chunk) {
        if (count($selected) >= $limit) {
            break;
        }

        $content = knowledge_retrieval_normalize_text((string) ($chunk['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $nextLength = $charCount + mb_strlen($content, 'UTF-8');
        if (!empty($selected) && $nextLength > $maxChars) {
            continue;
        }

        $selected[] = $chunk;
        $charCount = $nextLength;
    }

    usort($selected, static function (array $left, array $right): int {
        return ((int) $left['chunk_index']) <=> ((int) $right['chunk_index']);
    });

    $parts = [];
    foreach (array_values($selected) as $index => $chunk) {
        $parts[] = "【知识片段" . ($index + 1) . "】\n" . knowledge_retrieval_normalize_text((string) ($chunk['content'] ?? ''));
    }

    return [
        'context' => trim(implode("\n\n", $parts)),
        'chunks' => $selected,
    ];
}
