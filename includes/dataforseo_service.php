<?php
/**
 * DataForSEO keyword research integration.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class DataForSeoException extends RuntimeException {}

class DataForSeoService {
    private const BASE_URL = 'https://api.dataforseo.com/v3/';
    private const USER_DATA_ENDPOINT = 'appendix/user_data';
    private const KEYWORD_SUGGESTIONS_ENDPOINT = 'dataforseo_labs/google/keyword_suggestions/live';
    public const DEFAULT_LOCATION_CODE = 2702;
    public const DEFAULT_LANGUAGE_CODE = 'zh-CN';
    private const ABSOLUTE_MAX_LIMIT = 200;
    private const ABSOLUTE_MAX_SEEDS = 10;

    private string $login;
    private string $password;
    private int $timeoutSeconds;
    private int $defaultLocationCode;
    private string $defaultLanguageCode;
    private int $maxKeywordLimit;
    private int $maxSeedCount;

    public function __construct(
        string $login,
        string $password,
        int $timeoutSeconds = 45,
        int $defaultLocationCode = self::DEFAULT_LOCATION_CODE,
        string $defaultLanguageCode = self::DEFAULT_LANGUAGE_CODE,
        int $maxKeywordLimit = 100,
        int $maxSeedCount = 5
    ) {
        $this->login = trim($login);
        $this->password = trim($password);
        $this->timeoutSeconds = max(15, min($timeoutSeconds, 120));
        $this->defaultLocationCode = $defaultLocationCode > 0 ? $defaultLocationCode : self::DEFAULT_LOCATION_CODE;
        $this->defaultLanguageCode = trim($defaultLanguageCode) !== '' ? trim($defaultLanguageCode) : self::DEFAULT_LANGUAGE_CODE;
        $this->maxKeywordLimit = max(1, min($maxKeywordLimit, self::ABSOLUTE_MAX_LIMIT));
        $this->maxSeedCount = max(1, min($maxSeedCount, self::ABSOLUTE_MAX_SEEDS));
    }

    public static function fromEnvironment(): self {
        return new self(
            (string) env_value('DATAFORSEO_LOGIN', ''),
            (string) env_value('DATAFORSEO_PASSWORD', ''),
            env_int('DATAFORSEO_TIMEOUT', 45),
            env_int('DATAFORSEO_DEFAULT_LOCATION_CODE', self::DEFAULT_LOCATION_CODE),
            (string) env_value('DATAFORSEO_DEFAULT_LANGUAGE_CODE', self::DEFAULT_LANGUAGE_CODE),
            env_int('DATAFORSEO_MAX_KEYWORD_LIMIT', 100),
            env_int('DATAFORSEO_MAX_SEED_COUNT', 5)
        );
    }

    public function isConfigured(): bool {
        return $this->login !== '' && $this->password !== '';
    }

    public function publicConfig(): array {
        return [
            'configured' => $this->isConfigured(),
            'login' => $this->login !== '' ? $this->login : '',
            'default_location_code' => $this->defaultLocationCode,
            'default_language_code' => $this->defaultLanguageCode,
            'max_keyword_limit' => $this->maxKeywordLimit,
            'max_seed_count' => $this->maxSeedCount,
        ];
    }

    public function testConnection(): array {
        $response = $this->request('GET', self::USER_DATA_ENDPOINT);
        $result = $response['tasks'][0]['result'][0] ?? [];
        $money = is_array($result) ? ($result['money'] ?? []) : [];

        return [
            'status_code' => (int) ($response['status_code'] ?? 0),
            'status_message' => (string) ($response['status_message'] ?? ''),
            'login' => (string) ($result['login'] ?? $this->login),
            'balance' => isset($money['balance']) ? (float) $money['balance'] : null,
            'total_deposit' => isset($money['total']) ? (float) $money['total'] : null,
            'cost' => isset($response['cost']) ? (float) $response['cost'] : 0.0,
        ];
    }

    public function fetchKeywordSuggestions(array $seedKeywords, array $options = []): array {
        $seeds = $this->normalizeSeeds($seedKeywords);
        if (empty($seeds)) {
            throw new DataForSeoException('请输入至少 1 个种子关键词');
        }
        if (count($seeds) > $this->maxSeedCount) {
            throw new DataForSeoException('单次最多允许 ' . $this->maxSeedCount . ' 个种子关键词');
        }

        $limit = (int) ($options['limit'] ?? 50);
        $limit = max(1, min($limit, $this->maxKeywordLimit));
        $locationCode = (int) ($options['location_code'] ?? $this->defaultLocationCode);
        $locationCode = $locationCode > 0 ? $locationCode : $this->defaultLocationCode;
        $languageCode = trim((string) ($options['language_code'] ?? $this->defaultLanguageCode));
        $languageCode = $languageCode !== '' ? $languageCode : $this->defaultLanguageCode;
        $minSearchVolume = max(0, (int) ($options['min_search_volume'] ?? 0));

        $payload = [];
        foreach ($seeds as $seed) {
            $task = [
                'keyword' => $seed,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
                'include_seed_keyword' => true,
                'include_serp_info' => false,
                'include_clickstream_data' => false,
                'ignore_synonyms' => true,
                'limit' => $limit,
                'order_by' => ['keyword_info.search_volume,desc'],
            ];

            if ($minSearchVolume > 0) {
                $task['filters'] = ['keyword_info.search_volume', '>=', $minSearchVolume];
            }

            $payload[] = $task;
        }

        $response = $this->request('POST', self::KEYWORD_SUGGESTIONS_ENDPOINT, $payload);
        $items = $this->normalizeKeywordSuggestionItems($response);

        return [
            'status_code' => (int) ($response['status_code'] ?? 0),
            'status_message' => (string) ($response['status_message'] ?? ''),
            'cost' => isset($response['cost']) ? (float) $response['cost'] : 0.0,
            'requested_seed_count' => count($seeds),
            'requested_limit' => $limit,
            'items' => $items,
        ];
    }

    private function request(string $method, string $path, ?array $payload = null): array {
        if (!$this->isConfigured()) {
            throw new DataForSeoException('DataForSEO 未配置，请先在服务器环境变量中设置 DATAFORSEO_LOGIN 和 DATAFORSEO_PASSWORD');
        }

        $ch = curl_init(rtrim(self::BASE_URL, '/') . '/' . ltrim($path, '/'));
        if ($ch === false) {
            throw new DataForSeoException('无法初始化 DataForSEO 请求');
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($this->login . ':' . $this->password),
            'Content-Type: application/json',
        ];

        apply_ai_curl_request_defaults($ch, $this->timeoutSeconds, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? [], JSON_UNESCAPED_UNICODE));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new DataForSeoException('DataForSEO 请求失败：' . ($curlError ?: 'unknown curl error'));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new DataForSeoException('DataForSEO 返回了无法解析的响应，HTTP ' . $httpCode);
        }

        $statusCode = (int) ($decoded['status_code'] ?? 0);
        if ($httpCode >= 400 || $statusCode !== 20000 || (int) ($decoded['tasks_error'] ?? 0) > 0) {
            $taskMessage = $decoded['tasks'][0]['status_message'] ?? '';
            $message = (string) ($decoded['status_message'] ?? $taskMessage ?: 'unknown error');
            throw new DataForSeoException('DataForSEO 请求未成功：' . $message);
        }

        return $decoded;
    }

    private function normalizeSeeds(array $seedKeywords): array {
        $seeds = [];
        foreach ($seedKeywords as $seed) {
            $parts = preg_split('/[\r\n,，]+/u', (string) $seed) ?: [];
            foreach ($parts as $part) {
                $keyword = trim($part);
                if ($keyword !== '') {
                    $seeds[mb_strtolower($keyword)] = $keyword;
                }
            }
        }

        return array_values($seeds);
    }

    private function normalizeKeywordSuggestionItems(array $response): array {
        $items = [];
        foreach (($response['tasks'] ?? []) as $task) {
            if (!is_array($task) || (int) ($task['status_code'] ?? 0) !== 20000) {
                continue;
            }

            foreach (($task['result'] ?? []) as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $seedKeyword = (string) ($result['seed_keyword'] ?? ($task['data']['keyword'] ?? ''));
                $locationCode = (int) ($result['location_code'] ?? ($task['data']['location_code'] ?? 0));
                $languageCode = (string) ($result['language_code'] ?? ($task['data']['language_code'] ?? ''));
                $resultItems = is_array($result['items'] ?? null) ? $result['items'] : [];

                foreach ($resultItems as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $normalized = $this->normalizeKeywordItem($item, $seedKeyword, $locationCode, $languageCode);
                    if ($normalized !== null) {
                        $items[mb_strtolower($normalized['keyword'])] = $normalized;
                    }
                }
            }
        }

        return array_values($items);
    }

    private function normalizeKeywordItem(array $item, string $seedKeyword, int $locationCode, string $languageCode): ?array {
        $keyword = trim((string) ($item['keyword'] ?? ''));
        if ($keyword === '') {
            return null;
        }

        $keywordInfo = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
        $competition = $keywordInfo['competition'] ?? null;
        $competitionLevel = (string) ($keywordInfo['competition_level'] ?? '');
        $searchVolume = $keywordInfo['search_volume'] ?? null;
        $cpc = $keywordInfo['cpc'] ?? null;
        $monthlySearches = is_array($keywordInfo['monthly_searches'] ?? null) ? $keywordInfo['monthly_searches'] : [];
        $lastUpdated = trim((string) ($keywordInfo['last_updated_time'] ?? ''));

        return [
            'keyword' => $keyword,
            'source' => 'dataforseo',
            'seed_keyword' => $seedKeyword,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'search_volume' => is_numeric($searchVolume) ? (int) $searchVolume : null,
            'cpc' => is_numeric($cpc) ? round((float) $cpc, 4) : null,
            'competition' => $competitionLevel,
            'competition_index' => is_numeric($competition) ? (int) round(((float) $competition) * 100) : null,
            'monthly_searches_json' => json_encode($monthlySearches, JSON_UNESCAPED_UNICODE),
            'metrics_updated_at' => $lastUpdated !== '' && strtotime($lastUpdated) !== false ? date('Y-m-d H:i:s', strtotime($lastUpdated)) : null,
        ];
    }
}
