<?php
/**
 * 素材库管理共享辅助函数
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function material_library_root_path(): string {
    return dirname(__DIR__, 2);
}

function material_library_absolute_path(string $relativePath): string {
    $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    $segments = array_filter(explode('/', $relativePath), static fn(string $segment): bool => $segment !== '');
    foreach ($segments as $segment) {
        if ($segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('非法文件路径');
        }
    }

    return material_library_root_path() . '/' . implode('/', $segments);
}

function refresh_keyword_library_count(PDO $db, int $libraryId): void {
    $stmt = $db->prepare("
        UPDATE keyword_libraries
        SET keyword_count = (SELECT COUNT(*) FROM keywords WHERE library_id = ?),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$libraryId, $libraryId]);
}

function material_keyword_dedupe_key(string $keyword): string {
    $keyword = preg_replace('/\s+/u', ' ', trim($keyword)) ?? trim($keyword);
    return function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
}

function material_keyword_rows_from_text(string $keywordsText): array {
    $rows = [];
    $parts = preg_split('/[\r\n,，]+/u', $keywordsText) ?: [];
    foreach ($parts as $part) {
        $keyword = trim((string) $part);
        if ($keyword !== '') {
            $rows[] = ['keyword' => $keyword, 'source' => 'manual'];
        }
    }

    return $rows;
}

function material_keyword_available_metric_columns(PDO $db): array {
    static $cache = [];
    $cacheKey = spl_object_hash($db);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $columns = [
        'source',
        'seed_keyword',
        'location_code',
        'language_code',
        'search_volume',
        'cpc',
        'competition',
        'competition_index',
        'monthly_searches_json',
        'metrics_updated_at',
    ];

    $available = [];
    foreach ($columns as $column) {
        try {
            if (db_column_exists($db, 'keywords', $column)) {
                $available[] = $column;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return $cache[$cacheKey] = $available;
}

function material_normalize_keyword_row($row, array $defaults = []): ?array {
    if (is_string($row)) {
        $row = ['keyword' => $row];
    }

    if (!is_array($row)) {
        return null;
    }

    $keyword = preg_replace('/\s+/u', ' ', trim((string) ($row['keyword'] ?? ''))) ?? trim((string) ($row['keyword'] ?? ''));
    if ($keyword === '') {
        return null;
    }

    return [
        'keyword' => $keyword,
        'source' => (string) ($row['source'] ?? $defaults['source'] ?? 'manual'),
        'seed_keyword' => (string) ($row['seed_keyword'] ?? $defaults['seed_keyword'] ?? ''),
        'location_code' => isset($row['location_code']) && is_numeric($row['location_code']) ? (int) $row['location_code'] : ($defaults['location_code'] ?? null),
        'language_code' => (string) ($row['language_code'] ?? $defaults['language_code'] ?? ''),
        'search_volume' => isset($row['search_volume']) && is_numeric($row['search_volume']) ? (int) $row['search_volume'] : null,
        'cpc' => isset($row['cpc']) && is_numeric($row['cpc']) ? (float) $row['cpc'] : null,
        'competition' => (string) ($row['competition'] ?? ''),
        'competition_index' => isset($row['competition_index']) && is_numeric($row['competition_index']) ? (int) $row['competition_index'] : null,
        'monthly_searches_json' => (string) ($row['monthly_searches_json'] ?? ''),
        'metrics_updated_at' => (string) ($row['metrics_updated_at'] ?? ''),
    ];
}

function material_import_keywords(PDO $db, int $libraryId, array $rows, array $defaults = []): array {
    $normalizedRows = [];
    foreach ($rows as $row) {
        $normalized = material_normalize_keyword_row($row, $defaults);
        if ($normalized !== null) {
            $normalizedRows[material_keyword_dedupe_key($normalized['keyword'])] = $normalized;
        }
    }

    $metricColumns = material_keyword_available_metric_columns($db);
    $findStmt = $db->prepare("SELECT id FROM keywords WHERE library_id = ? AND LOWER(keyword) = LOWER(?) LIMIT 1");

    $imported = 0;
    $duplicate = 0;
    $updated = 0;
    $samples = [];

    foreach (array_values($normalizedRows) as $row) {
        $findStmt->execute([$libraryId, $row['keyword']]);
        $existingId = (int) ($findStmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $duplicate++;
            $updateColumns = [];
            $updateValues = [];
            foreach ($metricColumns as $column) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                $value = $row[$column];
                if ($value === null || $value === '') {
                    continue;
                }
                $updateColumns[] = $column . ' = ?';
                $updateValues[] = $value;
            }

            if (!empty($updateColumns)) {
                $updateValues[] = $existingId;
                $updateStmt = $db->prepare("UPDATE keywords SET " . implode(', ', $updateColumns) . " WHERE id = ?");
                if ($updateStmt->execute($updateValues) && $updateStmt->rowCount() > 0) {
                    $updated++;
                }
            }
            continue;
        }

        $columns = ['library_id', 'keyword', 'created_at'];
        $placeholders = ['?', '?', 'CURRENT_TIMESTAMP'];
        $values = [$libraryId, $row['keyword']];

        foreach ($metricColumns as $column) {
            $columns[] = $column;
            $placeholders[] = '?';
            $values[] = $row[$column] ?? null;
        }

        $stmt = $db->prepare("INSERT INTO keywords (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
        if ($stmt->execute($values)) {
            $imported++;
            if (count($samples) < 10) {
                $samples[] = $row;
            }
        }
    }

    refresh_keyword_library_count($db, $libraryId);

    return [
        'total' => count($normalizedRows),
        'imported' => $imported,
        'duplicate' => $duplicate,
        'updated' => $updated,
        'samples' => $samples,
    ];
}

function material_dataforseo_seed_import_plan(PDO $db, int $libraryId, array $seeds, int $locationCode, string $languageCode, int $requestedLimit): array {
    $normalizedSeeds = [];
    foreach ($seeds as $seed) {
        $seed = trim((string) $seed);
        if ($seed !== '') {
            $normalizedSeeds[material_keyword_dedupe_key($seed)] = $seed;
        }
    }

    $requestSeeds = array_values($normalizedSeeds);
    $skippedSeeds = [];
    $metricColumns = material_keyword_available_metric_columns($db);
    foreach (['source', 'seed_keyword', 'location_code', 'language_code'] as $requiredColumn) {
        if (!in_array($requiredColumn, $metricColumns, true)) {
            return ['request_seeds' => $requestSeeds, 'skipped_seeds' => $skippedSeeds];
        }
    }

    $requestedLimit = max(1, $requestedLimit);
    $stmt = $db->prepare("
        SELECT COUNT(*) AS existing_count, MAX(metrics_updated_at) AS last_metrics_at
        FROM keywords
        WHERE library_id = ?
          AND source = 'dataforseo'
          AND location_code = ?
          AND LOWER(language_code) = LOWER(?)
          AND LOWER(seed_keyword) = LOWER(?)
    ");

    $requestSeeds = [];
    foreach ($normalizedSeeds as $seed) {
        $stmt->execute([$libraryId, $locationCode, $languageCode, $seed]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $existingCount = (int) ($row['existing_count'] ?? 0);

        if ($existingCount >= $requestedLimit) {
            $skippedSeeds[] = [
                'seed' => $seed,
                'existing_count' => $existingCount,
                'last_metrics_at' => (string) ($row['last_metrics_at'] ?? ''),
            ];
            continue;
        }

        $requestSeeds[] = $seed;
    }

    return ['request_seeds' => $requestSeeds, 'skipped_seeds' => $skippedSeeds];
}

function refresh_title_library_count(PDO $db, int $libraryId): void {
    $stmt = $db->prepare("
        UPDATE title_libraries
        SET title_count = (SELECT COUNT(*) FROM titles WHERE library_id = ?),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$libraryId, $libraryId]);
}

function refresh_image_library_count(PDO $db, int $libraryId): void {
    $stmt = $db->prepare("
        UPDATE image_libraries
        SET image_count = (SELECT COUNT(*) FROM images WHERE library_id = ?),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$libraryId, $libraryId]);
}

function get_knowledge_base_task_references(PDO $db, int $knowledgeBaseId, int $limit = 5): array {
    $taskSiteScope = geoflow_site_scope_sql('tasks');
    $countStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE knowledge_base_id = ?" . $taskSiteScope);
    $countStmt->execute([$knowledgeBaseId]);
    $total = (int) $countStmt->fetchColumn();

    $taskStmt = $db->prepare("
        SELECT id, name, status
        FROM tasks
        WHERE knowledge_base_id = ?
        " . $taskSiteScope . "
        ORDER BY updated_at DESC, id DESC
        LIMIT ?
    ");
    $taskStmt->bindValue(1, $knowledgeBaseId, PDO::PARAM_INT);
    $taskStmt->bindValue(2, $limit, PDO::PARAM_INT);
    $taskStmt->execute();

    return [
        'count' => $total,
        'tasks' => $taskStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function validate_uploaded_image_file(array $file, int $maxBytes = 10_485_760): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('上传失败，请重新选择图片');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException('未检测到有效的上传文件');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new InvalidArgumentException('图片文件为空');
    }

    if ($size > $maxBytes) {
        throw new InvalidArgumentException('图片大小超过限制，单张请控制在 10MB 以内');
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        throw new InvalidArgumentException('文件内容不是有效图片');
    }

    $detectedMime = (string) ($imageInfo['mime'] ?? '');
    if ($detectedMime === '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = (string) finfo_file($finfo, $tmpName);
            finfo_close($finfo);
        }
    }

    $allowedMimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimeMap[$detectedMime])) {
        throw new InvalidArgumentException('仅支持 JPG、PNG、GIF、WEBP 图片');
    }

    return [
        'mime_type' => $detectedMime,
        'extension' => $allowedMimeMap[$detectedMime],
        'width' => (int) ($imageInfo[0] ?? 0),
        'height' => (int) ($imageInfo[1] ?? 0),
        'file_size' => $size,
    ];
}

function store_uploaded_image_file(array $file, ?string $subDirectory = null): array {
    $metadata = validate_uploaded_image_file($file);
    $relativeDirectory = 'uploads/images/' . trim($subDirectory ?? date('Y/m'), '/');
    $absoluteDirectory = material_library_absolute_path($relativeDirectory);

    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('创建图片目录失败');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $metadata['extension'];
    $relativePath = $relativeDirectory . '/' . $filename;
    $absolutePath = material_library_absolute_path($relativePath);

    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('保存上传图片失败');
    }

    @chmod($absolutePath, 0644);

    return $metadata + [
        'filename' => $filename,
        'file_name' => $filename,
        'file_path' => $relativePath,
        'absolute_path' => $absolutePath,
        'original_name' => (string) ($file['name'] ?? $filename),
    ];
}

function delete_material_files(array $relativePaths): array {
    $failed = [];

    foreach (array_unique($relativePaths) as $relativePath) {
        $relativePath = trim((string) $relativePath);
        if ($relativePath === '') {
            continue;
        }

        try {
            $absolutePath = material_library_absolute_path($relativePath);
        } catch (InvalidArgumentException $e) {
            $failed[] = $relativePath;
            continue;
        }
        if (!file_exists($absolutePath)) {
            continue;
        }

        if (!@unlink($absolutePath)) {
            $failed[] = $relativePath;
        }
    }

    return $failed;
}
