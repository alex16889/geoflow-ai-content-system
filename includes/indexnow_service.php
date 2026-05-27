<?php
/**
 * Changed-URL queue and IndexNow submission.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class IndexNowService {
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public static function isSubmittableBaseUrl(string $baseUrl): bool {
        $host = strtolower(trim((string) parse_url($baseUrl, PHP_URL_HOST)));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.') && !str_ends_with($host, '.local');
    }

    public static function publicBaseUrlForSite(array $site): string {
        $domain = geoflow_normalize_domain_input((string) ($site['primary_domain'] ?? ''));
        if ($domain === '') {
            return '';
        }

        return 'https://' . $domain;
    }

    public static function isEnabled(): bool {
        return function_exists('get_setting') && get_setting('indexnow_enabled', '0') === '1';
    }

    public static function key(): string {
        return function_exists('get_setting') ? trim((string) get_setting('indexnow_key', '')) : '';
    }

    public static function keyFileName(): string {
        $key = self::key();
        return $key !== '' ? $key . '.txt' : '';
    }

    public static function isValidKey(string $key): bool {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,128}$/', $key);
    }

    public static function queueUrl(PDO $db, int $siteId, string $url, array $context = []): void {
        if ($siteId <= 0 || $url === '' || !geoflow_db_table_exists($db, 'url_indexing_queue')) {
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO url_indexing_queue (site_id, provider, url, status, context_json, queued_at, updated_at)
            VALUES (?, 'indexnow', ?, 'pending', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (site_id, provider, url)
            DO UPDATE SET
                status = CASE
                    WHEN url_indexing_queue.status IN ('submitted', 'failed') THEN 'pending'
                    ELSE url_indexing_queue.status
                END,
                attempts = CASE
                    WHEN url_indexing_queue.status IN ('submitted', 'failed') THEN 0
                    ELSE url_indexing_queue.attempts
                END,
                last_error = CASE
                    WHEN url_indexing_queue.status IN ('submitted', 'failed') THEN ''
                    ELSE url_indexing_queue.last_error
                END,
                context_json = EXCLUDED.context_json,
                queued_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$siteId, $url, json_encode($context, JSON_UNESCAPED_UNICODE)]);
    }

    public static function queueArticle(PDO $db, int $articleId): void {
        $stmt = $db->prepare("
            SELECT a.id, a.slug, a.site_id, s.primary_domain
            FROM articles a
            LEFT JOIN sites s ON s.id = a.site_id
            WHERE a.id = ?
              AND a.status = 'published'
              AND a.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$article || empty($article['slug'])) {
            return;
        }

        $baseUrl = self::publicBaseUrlForSite(['primary_domain' => (string) ($article['primary_domain'] ?? '')]);
        if ($baseUrl === '') {
            $baseUrl = geo_absolute_url('/');
        }

        self::queueUrl($db, (int) $article['site_id'], rtrim($baseUrl, '/') . '/article/' . ltrim((string) $article['slug'], '/'), [
            'type' => 'article',
            'article_id' => $articleId,
        ]);
    }

    public static function serveKeyIfMatched(string $path): bool {
        $key = self::key();
        if ($key === '' || !self::isValidKey($key)) {
            return false;
        }

        if (trim($path, '/') !== $key . '.txt') {
            return false;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo $key;
        return true;
    }

    public static function submitPending(PDO $db, int $siteId, int $limit = 100): array {
        $limit = max(1, min(100, $limit));
        $site = geoflow_find_site_by_id($db, $siteId);
        if (!$site) {
            return ['submitted' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'site_not_found'];
        }

        $baseUrl = self::publicBaseUrlForSite($site);
        $key = self::key();
        if (!self::isEnabled() || $key === '' || !self::isValidKey($key) || !self::isSubmittableBaseUrl($baseUrl)) {
            return ['submitted' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'indexnow_not_configured'];
        }

        $stmt = $db->prepare("
            SELECT id, url
            FROM url_indexing_queue
            WHERE site_id = ?
              AND provider = 'indexnow'
              AND status IN ('pending', 'failed')
              AND attempts < 5
            ORDER BY queued_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return ['submitted' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'empty'];
        }

        $urls = array_values(array_map(static fn(array $row): string => (string) $row['url'], $rows));
        $ids = array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => rtrim($baseUrl, '/') . '/' . $key . '.txt',
            'urlList' => $urls,
        ];

        $httpCode = self::postPayload($payload);
        $status = in_array($httpCode, [200, 202], true) ? 'submitted' : 'failed';
        $error = $status === 'submitted' ? '' : 'IndexNow HTTP ' . $httpCode;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $db->prepare("
            UPDATE url_indexing_queue
            SET status = ?,
                attempts = attempts + 1,
                last_error = ?,
                submitted_at = CASE WHEN ? = 'submitted' THEN CURRENT_TIMESTAMP ELSE submitted_at END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN ($placeholders)
        ");
        $update->execute(array_merge([$status, $error, $status], $ids));

        return [
            'submitted' => $status === 'submitted' ? count($ids) : 0,
            'failed' => $status === 'failed' ? count($ids) : 0,
            'skipped' => 0,
            'message' => $status,
            'http_code' => $httpCode,
        ];
    }

    private static function postPayload(array $payload): int {
        $ch = curl_init(self::ENDPOINT);
        if ($ch === false) {
            return 0;
        }

        apply_ai_curl_request_defaults($ch, 20, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode;
    }
}
