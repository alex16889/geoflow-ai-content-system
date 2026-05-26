<?php
/**
 * URL智能采集辅助函数
 */

if (!defined('FEISHU_TREASURE')) {
    die('Direct access not allowed');
}

require_once dirname(__DIR__, 2) . '/includes/knowledge-retrieval.php';

function url_import_step_definitions() {
    return [
        'queued' => ['label' => '等待开始', 'progress' => 2],
        'fetch' => ['label' => '抓取页面', 'progress' => 12],
        'extract' => ['label' => '提取正文', 'progress' => 28],
        'images' => ['label' => '分析图片', 'progress' => 42],
        'ai_clean' => ['label' => 'AI 清洗', 'progress' => 58],
        'keywords' => ['label' => '生成关键词', 'progress' => 72],
        'titles' => ['label' => '生成标题', 'progress' => 86],
        'knowledge' => ['label' => '整理知识库', 'progress' => 96],
        'completed' => ['label' => '处理完成', 'progress' => 100],
        'failed' => ['label' => '处理失败', 'progress' => 100]
    ];
}

function normalize_import_url($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
    $path = preg_replace('#/+#', '/', $path);
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    $normalized = $scheme . '://' . $host . $path;
    if (!empty($parts['query'])) {
        $normalized .= '?' . $parts['query'];
    }

    return $normalized;
}

function validate_import_url($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return 'URL 不能为空';
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return 'URL 格式不正确';
    }

    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower($parts['host'] ?? '');

    if (!in_array($scheme, ['http', 'https'], true)) {
        return '仅支持 http 或 https 地址';
    }

    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
        return '不允许采集本地地址';
    }

    if (preg_match('/(^|\.)local$/', $host)) {
        return '不允许采集本地域名';
    }

    $resolvedIps = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $resolvedIps[] = $host;
    } else {
        $ipv4Records = gethostbynamel($host);
        if (is_array($ipv4Records)) {
            $resolvedIps = array_merge($resolvedIps, $ipv4Records);
        }

        $ipv6Records = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (!empty($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }
    }

    $resolvedIps = array_values(array_unique(array_filter($resolvedIps, static function ($ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    })));

    if (empty($resolvedIps)) {
        return '目标域名解析失败';
    }

    foreach ($resolvedIps as $resolvedIp) {
        if (filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return '不允许采集内网或保留地址';
        }
    }

    return null;
}

function extract_import_response_header(string $rawHeaders, string $headerName): string {
    $pattern = '/^' . preg_quote($headerName, '/') . ':\s*(.+)$/im';
    if (!preg_match_all($pattern, $rawHeaders, $matches) || empty($matches[1])) {
        return '';
    }

    return trim((string) end($matches[1]));
}

function fetch_import_response_with_validation(
    string $url,
    array $curlOptions,
    array $headers,
    int $maxRedirects,
    string $errorPrefix
): array {
    $currentUrl = normalize_import_url($url) ?: $url;
    $validationError = validate_import_url($currentUrl);
    if ($validationError !== null) {
        throw new RuntimeException($errorPrefix . '：' . $validationError);
    }

    for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
        $ch = curl_init();
        apply_curl_network_defaults($ch);

        $options = [
            CURLOPT_URL => $currentUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GEOBot/1.0; +https://localhost)',
            CURLOPT_HTTPHEADER => $headers,
        ] + $curlOptions;

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new RuntimeException($errorPrefix . '：' . ($error ?: '未知网络错误'));
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
            if ($redirects >= $maxRedirects) {
                throw new RuntimeException($errorPrefix . '：重定向次数过多');
            }

            $location = extract_import_response_header($rawHeaders, 'Location');
            if ($location === '') {
                throw new RuntimeException($errorPrefix . '：重定向响应缺少 Location');
            }

            $nextUrl = resolve_import_url($currentUrl, $location);
            $validationError = validate_import_url($nextUrl);
            if ($validationError !== null) {
                throw new RuntimeException($errorPrefix . '：重定向目标不安全');
            }

            $currentUrl = normalize_import_url($nextUrl) ?: $nextUrl;
            continue;
        }

        return [
            'body' => $body,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'effective_url' => $currentUrl,
        ];
    }

    throw new RuntimeException($errorPrefix . '：重定向次数过多');
}

function fetch_import_html(string $url): array {
    $response = fetch_import_response_with_validation(
        $url,
        [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ],
        [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
        ],
        5,
        '页面抓取失败'
    );

    if ($response['http_code'] >= 400) {
        throw new RuntimeException('页面抓取失败：HTTP ' . $response['http_code']);
    }

    if ($response['body'] === '' || stripos((string) $response['content_type'], 'html') === false) {
        throw new RuntimeException('目标地址未返回有效的 HTML 页面');
    }

    return [
        'html' => $response['body'],
        'effective_url' => $response['effective_url'],
        'content_type' => $response['content_type'],
    ];
}

function download_import_image(string $url): ?array {
    $response = fetch_import_response_with_validation(
        $url,
        [
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ],
        [],
        3,
        '图片下载失败'
    );

    if ($response['http_code'] >= 400 || $response['body'] === '') {
        return null;
    }

    $mimeType = preg_replace('/;.*$/', '', (string) $response['content_type']);
    if (!is_string($mimeType) || stripos($mimeType, 'image/') !== 0) {
        return null;
    }

    return [
        'binary' => $response['body'],
        'mime_type' => $mimeType,
    ];
}

function add_url_import_log(PDO $db, $job_id, $message, $level = 'info') {
    $stmt = $db->prepare("INSERT INTO url_import_job_logs (job_id, level, message, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([$job_id, $level, $message]);
}

function get_url_import_job(PDO $db, int $job_id): ?array {
    $stmt = $db->prepare("SELECT * FROM url_import_jobs WHERE id = ?" . geoflow_site_scope_sql('url_import_jobs'));
    $stmt->execute([$job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

function update_url_import_job(PDO $db, int $job_id, array $fields): void {
    if (empty($fields)) {
        return;
    }

    $sets = [];
    $params = [];
    foreach ($fields as $column => $value) {
        $sets[] = "{$column} = ?";
        $params[] = $value;
    }

    $sets[] = 'updated_at = CURRENT_TIMESTAMP';
    $params[] = $job_id;

    $sql = "UPDATE url_import_jobs SET " . implode(', ', $sets) . " WHERE id = ?" . geoflow_site_scope_sql('url_import_jobs');
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function set_url_import_step(PDO $db, int $job_id, string $step, string $message, string $status = 'running'): void {
    $stepMap = url_import_step_definitions();
    update_url_import_job($db, $job_id, [
        'status' => $status,
        'current_step' => $step,
        'progress_percent' => $stepMap[$step]['progress'] ?? 0,
    ]);
    add_url_import_log($db, $job_id, $message, $status === 'failed' ? 'error' : 'info');
}

function normalize_import_text(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/u", "\n", $text);
    $text = preg_replace("/[ \t]+/u", ' ', $text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);
    return trim((string) $text);
}

function resolve_import_url(string $baseUrl, string $relativeUrl): string {
    $relativeUrl = trim($relativeUrl);
    if ($relativeUrl === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $relativeUrl)) {
        return $relativeUrl;
    }

    $baseParts = parse_url($baseUrl);
    if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return $relativeUrl;
    }

    $scheme = $baseParts['scheme'];
    $host = $baseParts['host'];
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    if (str_starts_with($relativeUrl, '//')) {
        return $scheme . ':' . $relativeUrl;
    }

    if (str_starts_with($relativeUrl, '/')) {
        return "{$scheme}://{$host}{$port}{$relativeUrl}";
    }

    $basePath = $baseParts['path'] ?? '/';
    $baseDir = preg_replace('#/[^/]*$#', '/', $basePath);
    return "{$scheme}://{$host}{$port}{$baseDir}{$relativeUrl}";
}

function create_import_dom(string $html): DOMDocument {
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $wrappedHtml = '<?xml encoding="UTF-8">' . $html;
    $dom->loadHTML($wrappedHtml, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $dom;
}

function remove_import_noise(DOMXPath $xpath): void {
    $noiseQuery = '//script|//style|//noscript|//iframe|//svg|//form|//button|//header|//footer|//nav|//aside';
    $nodes = [];
    foreach ($xpath->query($noiseQuery) as $node) {
        $nodes[] = $node;
    }
    foreach ($nodes as $node) {
        if ($node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }
}

function extract_import_meta(DOMXPath $xpath): array {
    $title = trim((string) $xpath->evaluate('string(//title)'));
    $description = trim((string) $xpath->evaluate("string(//meta[@name='description']/@content | //meta[@property='og:description']/@content)"));
    $h1 = trim((string) $xpath->evaluate('string((//h1)[1])'));
    return [
        'title' => $title !== '' ? $title : $h1,
        'description' => $description,
    ];
}

function score_import_candidate(DOMElement $node): float {
    $text = normalize_import_text($node->textContent ?? '');
    $textLength = mb_strlen($text);
    if ($textLength < 120) {
        return -INF;
    }

    $paragraphs = $node->getElementsByTagName('p')->length;
    $headings = $node->getElementsByTagName('h2')->length + $node->getElementsByTagName('h3')->length;
    $images = $node->getElementsByTagName('img')->length;
    $links = $node->getElementsByTagName('a')->length;

    return $textLength + ($paragraphs * 80) + ($headings * 30) + ($images * 12) - ($links * 8);
}

function select_import_content_node(DOMXPath $xpath): ?DOMElement {
    $bestNode = null;
    $bestScore = -INF;

    foreach (['//article', '//main', '//section', '//div'] as $query) {
        foreach ($xpath->query($query) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $score = score_import_candidate($node);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode = $node;
            }
        }
        if ($bestScore > 1500) {
            break;
        }
    }

    return $bestNode;
}

function extract_import_paragraphs(DOMElement $node): array {
    $paragraphs = [];
    foreach ($node->getElementsByTagName('p') as $paragraph) {
        $text = normalize_import_text($paragraph->textContent ?? '');
        if (mb_strlen($text) >= 20) {
            $paragraphs[] = $text;
        }
    }

    if (empty($paragraphs)) {
        $fallback = normalize_import_text($node->textContent ?? '');
        if ($fallback !== '') {
            $paragraphs = preg_split("/\n{2,}/u", $fallback) ?: [];
            $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn ($item) => mb_strlen($item) >= 20));
        }
    }

    return array_slice(array_values(array_unique($paragraphs)), 0, 12);
}

function extract_import_images(DOMElement $node, string $baseUrl): array {
    $images = [];
    foreach ($node->getElementsByTagName('img') as $img) {
        $src = trim((string) $img->getAttribute('src'));
        if ($src === '' || str_starts_with($src, 'data:')) {
            continue;
        }
        $resolved = resolve_import_url($baseUrl, $src);
        if ($resolved === '') {
            continue;
        }
        if (validate_import_url($resolved) !== null) {
            continue;
        }
        $images[] = [
            'label' => trim((string) $img->getAttribute('alt')) ?: '正文图片',
            'source' => $resolved,
        ];
    }

    return array_slice($images, 0, 6);
}

function extract_import_keywords(array $chunks, string $fallbackTitle = ''): array {
    $text = implode("\n", $chunks);
    if ($fallbackTitle !== '') {
        $text = $fallbackTitle . "\n" . $text;
    }

    preg_match_all('/[\p{Han}]{2,8}/u', $text, $hanMatches);
    preg_match_all('/[A-Za-z][A-Za-z0-9\-]{2,}/u', $text, $latinMatches);

    $stopwords = ['我们', '你们', '他们', '这个', '一个', '以及', '通过', '开始', '可以', '进行', '内容', '页面', '文章', '智能', '采集', '生成'];
    $weights = [];

    foreach (array_merge($hanMatches[0] ?? [], $latinMatches[0] ?? []) as $word) {
        $word = trim(mb_strtolower($word));
        if ($word === '' || mb_strlen($word) < 2 || in_array($word, $stopwords, true)) {
            continue;
        }
        $weights[$word] = ($weights[$word] ?? 0) + 1;
    }

    arsort($weights);
    return array_slice(array_keys($weights), 0, 8);
}

function generate_import_titles(string $pageTitle, array $keywords): array {
    $pageTitle = trim($pageTitle);
    $primary = $keywords[0] ?? $pageTitle;
    $secondary = $keywords[1] ?? '内容整理';
    $titles = array_filter([
        $pageTitle !== '' ? $pageTitle : null,
        $primary . '：页面内容要点与结构化解读',
        $primary . '：基于真实页面的 GEO 素材整理',
        $primary . '与' . $secondary . '：从网页采集到知识沉淀',
    ]);

    return array_values(array_unique($titles));
}

function build_real_import_result(array $job, array $meta, array $paragraphs, array $keywords, array $titles, array $images): array {
    $domain = $job['source_domain'] ?: (parse_url($job['url'], PHP_URL_HOST) ?: '来源页面');
    $summary = $meta['description'] ?: ($paragraphs[0] ?? '');
    $knowledgeContent = implode("\n\n", array_slice($paragraphs, 0, 4));

    return [
        'summary' => $summary !== '' ? $summary : "已完成 {$domain} 页面的正文抽取与结构化整理。",
        'knowledge_preview' => [
            'title' => $meta['title'] ?: ($job['page_title'] ?: '页面内容'),
            'content' => $knowledgeContent !== '' ? $knowledgeContent : '未能提取到足够的正文内容。',
        ],
        'keywords' => $keywords,
        'titles' => $titles,
        'images' => $images,
    ];
}

function get_url_import_options(array $job): array {
    $options = json_decode($job['options_json'] ?? '{}', true);
    return is_array($options) ? $options : [];
}

function create_import_library_name(array $job, string $suffix): string {
    $options = get_url_import_options($job);
    $base = trim((string) ($options['project_name'] ?? ''));
    if ($base === '') {
        $base = trim((string) ($job['page_title'] ?? ''));
    }
    if ($base === '') {
        $base = preg_replace('/^www\./', '', (string) ($job['source_domain'] ?? '来源页面'));
    }
    return trim($base . ' - ' . $suffix);
}

function ensure_keyword_library(PDO $db, array $job): int {
    $options = get_url_import_options($job);
    $siteId = geoflow_required_site_id($db);
    $targetId = (int) ($options['target_keyword_library_id'] ?? 0);
    if ($targetId > 0) {
        if (!geoflow_record_belongs_to_site($db, 'keyword_libraries', $targetId, $siteId)) {
            throw new RuntimeException('目标关键词库不属于当前站点');
        }
        return $targetId;
    }

    $name = create_import_library_name($job, 'URL采集关键词');
    $hasSiteId = geoflow_table_has_site_column($db, 'keyword_libraries');
    $stmt = $db->prepare("INSERT INTO keyword_libraries (" . ($hasSiteId ? "site_id, " : "") . "name, keyword_count, created_at, updated_at) VALUES (" . ($hasSiteId ? "?, " : "") . "?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $values = [$name];
    if ($hasSiteId) {
        array_unshift($values, $siteId);
    }
    $stmt->execute($values);
    return db_last_insert_id($db, 'keyword_libraries');
}

function ensure_title_library(PDO $db, array $job, ?int $keywordLibraryId = null): int {
    $options = get_url_import_options($job);
    $siteId = geoflow_required_site_id($db);
    $targetId = (int) ($options['target_title_library_id'] ?? 0);
    if ($targetId > 0) {
        if (!geoflow_record_belongs_to_site($db, 'title_libraries', $targetId, $siteId)) {
            throw new RuntimeException('目标标题库不属于当前站点');
        }
        return $targetId;
    }

    $name = create_import_library_name($job, 'URL采集标题');
    $hasSiteId = geoflow_table_has_site_column($db, 'title_libraries');
    $stmt = $db->prepare("
        INSERT INTO title_libraries (" . ($hasSiteId ? "site_id, " : "") . "name, title_count, generation_type, keyword_library_id, created_at, updated_at)
        VALUES (" . ($hasSiteId ? "?, " : "") . "?, 0, 'manual', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $values = [$name, $keywordLibraryId];
    if ($hasSiteId) {
        array_unshift($values, $siteId);
    }
    $stmt->execute($values);
    return db_last_insert_id($db, 'title_libraries');
}

function ensure_image_library(PDO $db, array $job): int {
    $options = get_url_import_options($job);
    $siteId = geoflow_required_site_id($db);
    $targetId = (int) ($options['target_image_library_id'] ?? 0);
    if ($targetId > 0) {
        if (!geoflow_record_belongs_to_site($db, 'image_libraries', $targetId, $siteId)) {
            throw new RuntimeException('目标图片库不属于当前站点');
        }
        return $targetId;
    }

    $name = create_import_library_name($job, 'URL采集图片');
    $hasSiteId = geoflow_table_has_site_column($db, 'image_libraries');
    $stmt = $db->prepare("INSERT INTO image_libraries (" . ($hasSiteId ? "site_id, " : "") . "name, description, image_count, created_at, updated_at) VALUES (" . ($hasSiteId ? "?, " : "") . "?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $values = [$name, '来自 URL 智能采集'];
    if ($hasSiteId) {
        array_unshift($values, $siteId);
    }
    $stmt->execute($values);
    return db_last_insert_id($db, 'image_libraries');
}

function upsert_knowledge_base_from_import(PDO $db, array $job, array $result): int {
    $options = get_url_import_options($job);
    $siteId = geoflow_required_site_id($db);
    $targetId = (int) ($options['target_knowledge_base_id'] ?? 0);
    $preview = $result['knowledge_preview'] ?? [];
    $title = trim((string) ($preview['title'] ?? $job['page_title'] ?? 'URL采集知识'));
    $content = trim((string) ($preview['content'] ?? ''));
    $description = trim((string) ($result['summary'] ?? ''));
    $wordCount = mb_strlen(strip_tags($content));
    $startedTransaction = false;

    try {
        if ($targetId > 0) {
            if (!geoflow_record_belongs_to_site($db, 'knowledge_bases', $targetId, $siteId)) {
                throw new RuntimeException('目标知识库不属于当前站点');
            }

            $stmt = $db->prepare("SELECT content FROM knowledge_bases WHERE id = ?" . geoflow_site_scope_sql('knowledge_bases'));
            $stmt->execute([$targetId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $mergedContent = trim((string) ($existing['content'] ?? ''));
                if ($content !== '' && !str_contains($mergedContent, $content)) {
                    $mergedContent = trim($mergedContent . "\n\n---\n\n" . $content);
                }
                $startedTransaction = !$db->inTransaction();
                if ($startedTransaction) {
                    $db->beginTransaction();
                }
                $update = $db->prepare("
                    UPDATE knowledge_bases
                    SET description = ?, content = ?, word_count = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?" . geoflow_site_scope_sql('knowledge_bases') . "
                ");
                $update->execute([$description, $mergedContent, mb_strlen(strip_tags($mergedContent)), $targetId]);
                knowledge_retrieval_sync_chunks($db, $targetId, $mergedContent);
                if ($startedTransaction) {
                    $db->commit();
                }
                return $targetId;
            }
        }

        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }
        $hasSiteId = geoflow_table_has_site_column($db, 'knowledge_bases');
        $stmt = $db->prepare("
            INSERT INTO knowledge_bases (" . ($hasSiteId ? "site_id, " : "") . "name, description, content, file_type, word_count, created_at, updated_at)
            VALUES (" . ($hasSiteId ? "?, " : "") . "?, ?, ?, 'markdown', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $values = [$title, $description, $content, $wordCount];
        if ($hasSiteId) {
            array_unshift($values, $siteId);
        }
        $stmt->execute($values);
        $knowledgeBaseId = db_last_insert_id($db, 'knowledge_bases');
        knowledge_retrieval_sync_chunks($db, $knowledgeBaseId, $content);
        if ($startedTransaction) {
            $db->commit();
        }
        return $knowledgeBaseId;
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function import_keywords_from_result(PDO $db, int $libraryId, array $keywords): int {
    $inserted = 0;
    $insertStmt = $db->prepare("
        INSERT INTO keywords (library_id, keyword, created_at)
        SELECT ?, ?, CURRENT_TIMESTAMP
        WHERE NOT EXISTS (
            SELECT 1 FROM keywords WHERE library_id = ? AND keyword = ?
        )
    ");
    foreach ($keywords as $keyword) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            continue;
        }
        $insertStmt->execute([$libraryId, $keyword, $libraryId, $keyword]);
        $inserted += $insertStmt->rowCount() > 0 ? 1 : 0;
    }

    $countStmt = $db->prepare("UPDATE keyword_libraries SET keyword_count = (SELECT COUNT(*) FROM keywords WHERE library_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $countStmt->execute([$libraryId, $libraryId]);
    return $inserted;
}

function import_titles_from_result(PDO $db, int $libraryId, array $titles, array $keywords): int {
    $inserted = 0;
    $primaryKeyword = trim((string) ($keywords[0] ?? ''));
    $insertStmt = $db->prepare("INSERT INTO titles (library_id, title, keyword, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
    foreach ($titles as $title) {
        $title = trim((string) $title);
        if ($title === '') {
            continue;
        }
        $insertStmt->execute([$libraryId, $title, $primaryKeyword]);
        $inserted += $insertStmt->rowCount() > 0 ? 1 : 0;
    }

    $countStmt = $db->prepare("UPDATE title_libraries SET title_count = (SELECT COUNT(*) FROM titles WHERE library_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $countStmt->execute([$libraryId, $libraryId]);
    return $inserted;
}

function import_images_from_result(PDO $db, int $libraryId, array $images): int {
    $uploadDir = dirname(__DIR__, 2) . '/uploads/images/url-import/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $inserted = 0;
    $insertStmt = $db->prepare("
        INSERT INTO images (library_id, original_name, file_name, file_path, file_size, mime_type, created_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    foreach (array_slice($images, 0, 3) as $image) {
        $source = trim((string) ($image['source'] ?? ''));
        if ($source === '') {
            continue;
        }

        $downloaded = download_import_image($source);
        if (!$downloaded) {
            continue;
        }

        $extension = match ($downloaded['mime_type']) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
        $fileName = uniqid('urlimg_', true) . '.' . $extension;
        $absolutePath = $uploadDir . $fileName;
        $relativePath = 'uploads/images/url-import/' . $fileName;
        file_put_contents($absolutePath, $downloaded['binary']);

        $originalName = trim((string) ($image['label'] ?? 'URL采集图片'));
        $insertStmt->execute([
            $libraryId,
            $originalName,
            $fileName,
            $relativePath,
            filesize($absolutePath) ?: strlen($downloaded['binary']),
            $downloaded['mime_type'] ?: 'image/jpeg',
        ]);
        $inserted += $insertStmt->rowCount() > 0 ? 1 : 0;
    }

    $countStmt = $db->prepare("UPDATE image_libraries SET image_count = (SELECT COUNT(*) FROM images WHERE library_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $countStmt->execute([$libraryId, $libraryId]);
    return $inserted;
}

function commit_url_import_job(PDO $db, int $jobId): array {
    $job = get_url_import_job($db, $jobId);
    if (!$job) {
        throw new RuntimeException('采集任务不存在');
    }
    if (($job['status'] ?? '') !== 'completed') {
        throw new RuntimeException('仅已完成的采集任务可以入库');
    }

    $result = json_decode($job['result_json'] ?? '{}', true);
    if (!is_array($result) || empty($result)) {
        throw new RuntimeException('采集结果为空，无法入库');
    }

    if (!empty($result['import_result']['imported_at'])) {
        return $result['import_result'];
    }

    $options = get_url_import_options($job);
    $importSummary = [
        'knowledge_base_id' => null,
        'keyword_library_id' => null,
        'inserted_keywords' => 0,
        'title_library_id' => null,
        'inserted_titles' => 0,
        'image_library_id' => null,
        'inserted_images' => 0,
        'imported_at' => date('Y-m-d H:i:s'),
    ];

    $db->beginTransaction();
    try {
        if (!empty($options['import_knowledge'])) {
            $importSummary['knowledge_base_id'] = upsert_knowledge_base_from_import($db, $job, $result);
        }

        if (!empty($options['import_keywords']) && !empty($result['keywords'])) {
            $importSummary['keyword_library_id'] = ensure_keyword_library($db, $job);
            $importSummary['inserted_keywords'] = import_keywords_from_result($db, $importSummary['keyword_library_id'], $result['keywords']);
        }

        if (!empty($options['import_titles']) && !empty($result['titles'])) {
            $importSummary['title_library_id'] = ensure_title_library($db, $job, $importSummary['keyword_library_id']);
            $importSummary['inserted_titles'] = import_titles_from_result($db, $importSummary['title_library_id'], $result['titles'], $result['keywords'] ?? []);
        }

        if (!empty($options['import_images']) && !empty($result['images'])) {
            $importSummary['image_library_id'] = ensure_image_library($db, $job);
            $importSummary['inserted_images'] = import_images_from_result($db, $importSummary['image_library_id'], $result['images']);
        }

        $result['import_result'] = $importSummary;
        update_url_import_job($db, $jobId, [
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
        add_url_import_log($db, $jobId, '已完成素材入库：知识库/关键词库/标题库/图片库映射已更新', 'info');
        $db->commit();
        return $importSummary;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function run_url_import_pipeline(PDO $db, int $job_id): ?array {
    $job = get_url_import_job($db, $job_id);
    if (!$job) {
        return null;
    }

    if (!in_array($job['status'], ['queued', 'running'], true)) {
        return $job;
    }

    try {
        set_url_import_step($db, $job_id, 'fetch', '已接收 URL，开始抓取页面 HTML');
        $fetched = fetch_import_html($job['normalized_url']);
        $effectiveUrl = $fetched['effective_url'];
        $effectiveDomain = strtolower(parse_url($effectiveUrl, PHP_URL_HOST) ?? ($job['source_domain'] ?? ''));
        update_url_import_job($db, $job_id, [
            'normalized_url' => normalize_import_url($effectiveUrl) ?: $job['normalized_url'],
            'source_domain' => $effectiveDomain,
        ]);

        set_url_import_step($db, $job_id, 'extract', '页面抓取完成，开始提取正文与标题结构');
        $dom = create_import_dom($fetched['html']);
        $xpath = new DOMXPath($dom);
        remove_import_noise($xpath);
        $meta = extract_import_meta($xpath);
        $contentNode = select_import_content_node($xpath);
        if (!$contentNode) {
            throw new RuntimeException('未找到可用的正文内容区域');
        }

        $paragraphs = extract_import_paragraphs($contentNode);
        if (empty($paragraphs)) {
            throw new RuntimeException('正文提取结果为空');
        }

        update_url_import_job($db, $job_id, [
            'page_title' => $meta['title'] ?: ($job['page_title'] ?: '页面内容'),
        ]);

        set_url_import_step($db, $job_id, 'images', '正文提取完成，开始分析正文主体图片');
        $options = json_decode($job['options_json'] ?? '{}', true);
        $images = !empty($options['import_images']) ? extract_import_images($contentNode, $effectiveUrl) : [];

        set_url_import_step($db, $job_id, 'ai_clean', '正文提取完成，开始清洗段落与摘要');
        $cleanParagraphs = array_values(array_filter(array_map('normalize_import_text', $paragraphs)));

        set_url_import_step($db, $job_id, 'keywords', '开始生成关键词预览');
        $keywords = !empty($options['import_keywords']) ? extract_import_keywords(array_merge([$meta['description']], $cleanParagraphs), $meta['title']) : [];

        set_url_import_step($db, $job_id, 'titles', '开始生成标题建议');
        $titles = !empty($options['import_titles']) ? generate_import_titles($meta['title'], $keywords) : [];

        set_url_import_step($db, $job_id, 'knowledge', '开始整理知识库预览');
        $result = build_real_import_result(
            array_merge($job, ['source_domain' => $effectiveDomain]),
            $meta,
            $cleanParagraphs,
            $keywords,
            $titles,
            $images
        );

        update_url_import_job($db, $job_id, [
            'status' => 'completed',
            'current_step' => 'completed',
            'progress_percent' => url_import_step_definitions()['completed']['progress'] ?? 100,
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        add_url_import_log($db, $job_id, '真实页面解析已完成，可查看正文摘要、关键词、标题与图片预览', 'info');
    } catch (Throwable $e) {
        update_url_import_job($db, $job_id, [
            'status' => 'failed',
            'current_step' => 'failed',
            'progress_percent' => url_import_step_definitions()['failed']['progress'] ?? 100,
            'error_message' => $e->getMessage(),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        add_url_import_log($db, $job_id, '采集失败：' . $e->getMessage(), 'error');
    }

    return get_url_import_job($db, $job_id);
}

function get_url_import_logs(PDO $db, $job_id, $limit = 50) {
    $stmt = $db->prepare("
        SELECT id, level, message, created_at
        FROM url_import_job_logs
        WHERE job_id = ?
        ORDER BY id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $job_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
