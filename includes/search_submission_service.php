<?php
/**
 * Multi-provider changed-URL submission orchestration.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/indexnow_service.php';

class SearchSubmissionService {
    private const PROVIDER_INDEXNOW = 'indexnow';
    private const PROVIDER_BING = 'bing';
    private const PROVIDER_BAIDU = 'baidu';
    private const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
    private const BING_BATCH_ENDPOINT = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlBatch';

    public static function enabledProviderCodes(?array $settings = null): array {
        $settings = $settings ?? self::currentSettings();
        $providers = [];

        if (($settings['indexnow_enabled'] ?? '0') === '1' && IndexNowService::isValidKey(trim((string) ($settings['indexnow_key'] ?? '')))) {
            $providers[] = self::PROVIDER_INDEXNOW;
        }

        if (($settings['bing_url_submission_enabled'] ?? '0') === '1' && trim((string) ($settings['bing_url_submission_api_key'] ?? '')) !== '') {
            $providers[] = self::PROVIDER_BING;
        }

        if (($settings['baidu_url_submission_enabled'] ?? '0') === '1' && self::isValidBaiduEndpoint((string) ($settings['baidu_url_submission_endpoint'] ?? ''))) {
            $providers[] = self::PROVIDER_BAIDU;
        }

        return $providers;
    }

    public static function queueableProviderCodes(array $settings, string $baseUrl): array {
        if (!IndexNowService::isSubmittableBaseUrl($baseUrl)) {
            return [];
        }

        return self::enabledProviderCodes($settings);
    }

    public static function isValidBaiduEndpoint(string $endpoint): bool {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return false;
        }

        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $path = (string) parse_url($endpoint, PHP_URL_PATH);
        $query = (string) parse_url($endpoint, PHP_URL_QUERY);
        parse_str($query, $params);

        return $host === 'data.zz.baidu.com'
            && rtrim($path, '/') === '/urls'
            && !empty($params['site'])
            && !empty($params['token']);
    }

    public static function sitemapUrlForSite(array $site): string {
        $baseUrl = IndexNowService::publicBaseUrlForSite($site);
        if ($baseUrl === '') {
            $baseUrl = geo_absolute_url('/');
        }

        return rtrim($baseUrl, '/') . '/sitemap.xml';
    }

    public static function robotsUrlForSite(array $site): string {
        $baseUrl = IndexNowService::publicBaseUrlForSite($site);
        if ($baseUrl === '') {
            $baseUrl = geo_absolute_url('/');
        }

        return rtrim($baseUrl, '/') . '/robots.txt';
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

        $siteId = (int) ($article['site_id'] ?? 0);
        $baseUrl = IndexNowService::publicBaseUrlForSite(['primary_domain' => (string) ($article['primary_domain'] ?? '')]);
        if ($siteId <= 0 || !IndexNowService::isSubmittableBaseUrl($baseUrl)) {
            return;
        }

        $settings = self::settingsForSite($db, $siteId);
        $providers = self::queueableProviderCodes($settings, $baseUrl);
        if (empty($providers)) {
            return;
        }

        $url = rtrim($baseUrl, '/') . '/article/' . ltrim((string) $article['slug'], '/');
        foreach ($providers as $provider) {
            self::queueUrl($db, $siteId, $provider, $url, [
                'type' => 'article',
                'article_id' => $articleId,
            ]);
        }
    }

    public static function queueUrl(PDO $db, int $siteId, string $provider, string $url, array $context = []): void {
        if ($siteId <= 0 || $url === '' || !self::isSupportedProvider($provider) || !geoflow_db_table_exists($db, 'url_indexing_queue')) {
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO url_indexing_queue (site_id, provider, url, status, context_json, queued_at, updated_at)
            VALUES (?, ?, ?, 'pending', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
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
        $stmt->execute([$siteId, $provider, $url, json_encode($context, JSON_UNESCAPED_UNICODE)]);
    }

    public static function submitPending(PDO $db, int $siteId, int $limit = 100): array {
        $site = geoflow_find_site_by_id($db, $siteId);
        if (!$site) {
            return ['submitted' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'site_not_found', 'providers' => []];
        }

        $settings = self::settingsForSite($db, $siteId);
        $providers = self::enabledProviderCodes($settings);
        $summary = ['submitted' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'ok', 'providers' => []];

        foreach ($providers as $provider) {
            $result = self::submitProviderPending($db, $site, $settings, $provider, $limit);
            $summary['submitted'] += (int) ($result['submitted'] ?? 0);
            $summary['failed'] += (int) ($result['failed'] ?? 0);
            $summary['skipped'] += (int) ($result['skipped'] ?? 0);
            $summary['providers'][$provider] = $result;
        }

        if (empty($providers)) {
            $summary['message'] = 'no_enabled_providers';
        }

        return $summary;
    }

    private static function submitProviderPending(PDO $db, array $site, array $settings, string $provider, int $limit): array {
        $limit = max(1, min(500, $limit));
        $siteId = (int) ($site['id'] ?? 0);
        $baseUrl = IndexNowService::publicBaseUrlForSite($site);
        if (!IndexNowService::isSubmittableBaseUrl($baseUrl)) {
            return ['submitted' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'public_domain_required'];
        }

        $rows = self::fetchPendingRows($db, $siteId, $provider, $limit);
        if (empty($rows)) {
            return ['submitted' => 0, 'failed' => 0, 'skipped' => 0, 'message' => 'empty'];
        }

        $urls = array_values(array_map(static fn(array $row): string => (string) $row['url'], $rows));
        $ids = array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        $response = match ($provider) {
            self::PROVIDER_INDEXNOW => self::submitIndexNow($baseUrl, trim((string) ($settings['indexnow_key'] ?? '')), $urls),
            self::PROVIDER_BING => self::submitBing($baseUrl, trim((string) ($settings['bing_url_submission_api_key'] ?? '')), $urls),
            self::PROVIDER_BAIDU => self::submitBaidu(trim((string) ($settings['baidu_url_submission_endpoint'] ?? '')), $urls),
            default => ['ok' => false, 'http_code' => 0, 'message' => 'unsupported_provider'],
        };

        $status = !empty($response['ok']) ? 'submitted' : 'failed';
        $message = (string) ($response['message'] ?? $status);
        self::updateRows($db, $ids, $status, $message);

        return [
            'submitted' => $status === 'submitted' ? count($ids) : 0,
            'failed' => $status === 'failed' ? count($ids) : 0,
            'skipped' => 0,
            'message' => $message,
            'http_code' => (int) ($response['http_code'] ?? 0),
        ];
    }

    private static function fetchPendingRows(PDO $db, int $siteId, string $provider, int $limit): array {
        $stmt = $db->prepare("
            SELECT id, url
            FROM url_indexing_queue
            WHERE site_id = ?
              AND provider = ?
              AND status IN ('pending', 'failed')
              AND attempts < 5
            ORDER BY queued_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
        $stmt->bindValue(2, $provider);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function submitIndexNow(string $baseUrl, string $key, array $urls): array {
        $payload = [
            'host' => (string) parse_url($baseUrl, PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => rtrim($baseUrl, '/') . '/' . $key . '.txt',
            'urlList' => $urls,
        ];

        $response = self::postJson(self::INDEXNOW_ENDPOINT, $payload, 20);
        $response['ok'] = in_array((int) $response['http_code'], [200, 202], true);
        return $response;
    }

    private static function submitBing(string $baseUrl, string $apiKey, array $urls): array {
        $endpoint = self::BING_BATCH_ENDPOINT . '?apikey=' . rawurlencode($apiKey);
        $response = self::postJson($endpoint, [
            'siteUrl' => rtrim($baseUrl, '/'),
            'urlList' => array_slice($urls, 0, 500),
        ], 20);
        $response['ok'] = (int) $response['http_code'] === 200;
        return $response;
    }

    private static function submitBaidu(string $endpoint, array $urls): array {
        if (!self::isValidBaiduEndpoint($endpoint)) {
            return ['ok' => false, 'http_code' => 0, 'message' => 'invalid_baidu_endpoint'];
        }

        $response = self::postPlainText($endpoint, implode("\n", $urls), 20);
        $body = json_decode((string) ($response['body'] ?? ''), true);
        $hasError = is_array($body) && array_key_exists('error', $body);
        $response['ok'] = (int) $response['http_code'] === 200 && !$hasError;
        if ($hasError) {
            $response['message'] = 'Baidu error ' . (string) ($body['error'] ?? '') . ': ' . (string) ($body['message'] ?? '');
        }
        return $response;
    }

    private static function postJson(string $endpoint, array $payload, int $timeout): array {
        return self::post($endpoint, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ['Content-Type: application/json; charset=utf-8'], $timeout);
    }

    private static function postPlainText(string $endpoint, string $body, int $timeout): array {
        return self::post($endpoint, $body, ['Content-Type: text/plain; charset=utf-8'], $timeout);
    }

    private static function post(string $endpoint, string $body, array $headers, int $timeout): array {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['ok' => false, 'http_code' => 0, 'message' => 'curl_init_failed', 'body' => ''];
        }

        apply_ai_curl_request_defaults($ch, $timeout, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => false,
            'http_code' => $httpCode,
            'message' => $curlError !== '' ? $curlError : 'HTTP ' . $httpCode,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    private static function updateRows(PDO $db, array $ids, string $status, string $message): void {
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            UPDATE url_indexing_queue
            SET status = ?,
                attempts = attempts + 1,
                last_error = ?,
                submitted_at = CASE WHEN ? = 'submitted' THEN CURRENT_TIMESTAMP ELSE submitted_at END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$status, $status === 'submitted' ? '' : $message, $status], $ids));
    }

    private static function settingsForSite(PDO $db, int $siteId): array {
        $previousRuntimeSiteId = geoflow_runtime_site_id();
        geoflow_set_runtime_site_id($siteId);
        try {
            return self::currentSettings();
        } finally {
            if ($previousRuntimeSiteId > 0) {
                geoflow_set_runtime_site_id($previousRuntimeSiteId);
            } else {
                geoflow_clear_runtime_site_id();
            }
        }
    }

    private static function currentSettings(): array {
        $defaults = [
            'indexnow_enabled' => '0',
            'indexnow_key' => '',
            'bing_url_submission_enabled' => '0',
            'bing_url_submission_api_key' => '',
            'baidu_url_submission_enabled' => '0',
            'baidu_url_submission_endpoint' => '',
        ];

        if (!function_exists('get_setting')) {
            return $defaults;
        }

        foreach ($defaults as $key => $default) {
            $defaults[$key] = (string) get_setting($key, $default);
        }

        return $defaults;
    }

    private static function isSupportedProvider(string $provider): bool {
        return in_array($provider, [self::PROVIDER_INDEXNOW, self::PROVIDER_BING, self::PROVIDER_BAIDU], true);
    }
}
