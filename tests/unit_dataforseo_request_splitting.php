<?php

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/dataforseo_service.php';

function assert_same_dataforseo_split($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

class DataForSeoRequestSplittingService extends DataForSeoService {
    public array $payloads = [];

    protected function request(string $method, string $path, ?array $payload = null): array {
        $this->payloads[] = $payload;
        $seed = (string) ($payload[0]['keyword'] ?? '');

        return [
            'status_code' => 20000,
            'status_message' => 'Ok.',
            'tasks_error' => 0,
            'cost' => 0.01,
            'tasks' => [[
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'data' => [
                    'keyword' => $seed,
                    'location_code' => 2702,
                    'language_code' => 'zh-CN',
                ],
                'result' => [[
                    'seed_keyword' => $seed,
                    'location_code' => 2702,
                    'language_code' => 'zh-CN',
                    'items' => [[
                        'keyword' => $seed . ' 入口',
                        'keyword_info' => [
                            'search_volume' => 100,
                            'cpc' => 0.12,
                            'competition' => 0.34,
                            'competition_level' => 'LOW',
                            'monthly_searches' => [],
                        ],
                    ]],
                ]],
            ]],
        ];
    }
}

$service = new DataForSeoRequestSplittingService('login', 'password');
$result = $service->fetchKeywordSuggestions(['J9入口导航', 'APP下载'], [
    'location_code' => 2702,
    'language_code' => 'zh-CN',
    'limit' => 10,
]);

assert_same_dataforseo_split(2, count($service->payloads), 'Multiple seeds should be split into one DataForSEO request per seed');
assert_same_dataforseo_split(1, count($service->payloads[0]), 'First DataForSEO request should contain one task only');
assert_same_dataforseo_split(1, count($service->payloads[1]), 'Second DataForSEO request should contain one task only');
assert_same_dataforseo_split('J9入口导航', $service->payloads[0][0]['keyword'], 'First seed should be preserved');
assert_same_dataforseo_split('APP下载', $service->payloads[1][0]['keyword'], 'Second seed should be preserved');
assert_same_dataforseo_split(0.02, $result['cost'], 'Costs should be accumulated across split requests');
assert_same_dataforseo_split(2, count($result['items']), 'Items from split requests should be merged');

echo "unit_dataforseo_request_splitting: ok\n";
