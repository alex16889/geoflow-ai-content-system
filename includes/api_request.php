<?php
/**
 * API 请求解析与幂等工具
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class ApiRequest {
    private string $method;
    private string $path;
    private array $query;
    private array $body;
    private string $requestId;
    private ?string $idempotencyKey;

    public function __construct() {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->resolvePath();
        $this->query = $_GET;
        $this->body = $this->parseBody();
        $this->requestId = $this->resolveRequestId();
        $this->idempotencyKey = $this->resolveHeader('X-Idempotency-Key');
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getPath(): string {
        return $this->path;
    }

    public function getSegments(): array {
        $trimmed = trim($this->path, '/');
        if ($trimmed === '') {
            return [];
        }
        return array_values(array_filter(explode('/', $trimmed), static fn($segment) => $segment !== ''));
    }

    public function getQueryString(string $key, string $default = ''): string {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function getQueryInt(string $key, int $default = 0): int {
        $value = $this->query[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBody(): array {
        return $this->body;
    }

    public function getBodyValue(string $key, mixed $default = null): mixed {
        return $this->body[$key] ?? $default;
    }

    public function getRequestId(): string {
        return $this->requestId;
    }

    public function getIdempotencyKey(): ?string {
        return $this->idempotencyKey;
    }

    public function getHeader(string $name): ?string {
        return $this->resolveHeader($name);
    }

    private function resolvePath(): string {
        $path = $_SERVER['API_REQUEST_PATH'] ?? null;
        if (!is_string($path) || $path === '') {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/api/v1';
            $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/api/v1';
            $path = preg_replace('#^/api/v1#', '', $requestPath) ?: '/';
        }

        $path = '/' . ltrim((string) $path, '/');
        return $path === '//' ? '/' : (rtrim($path, '/') ?: '/');
    }

    private function parseBody(): array {
        if (!in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return [];
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new ApiException('invalid_json', '请求体不是有效的 JSON', 400);
            }

            return $decoded;
        }

        return $_POST;
    }

    private function resolveRequestId(): string {
        $header = $this->resolveHeader('X-Request-Id');
        if ($header !== null && $header !== '') {
            return mb_substr($header, 0, 120);
        }

        return 'req_' . bin2hex(random_bytes(8));
    }

    private function resolveHeader(string $name): ?string {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$normalized] ?? null;

        if ($value === null && $name === 'Authorization') {
            $value = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }

        if ($value === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $headerName => $headerValue) {
                if (strcasecmp($headerName, $name) === 0) {
                    $value = $headerValue;
                    break;
                }
            }
        }

        return is_string($value) ? trim($value) : null;
    }
}

function api_normalize_idempotency_payload(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('api_normalize_idempotency_payload', $value);
    }

    ksort($value);
    foreach ($value as $key => $item) {
        $value[$key] = api_normalize_idempotency_payload($item);
    }
    return $value;
}

function api_request_hash(array $body): string {
    $normalized = api_normalize_idempotency_payload($body);
    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function api_load_idempotency_response(PDO $db, string $idempotencyKey, string $routeKey, string $requestHash): ?array {
    $stmt = $db->prepare("
        SELECT request_hash, response_body, response_status
        FROM api_idempotency_keys
        WHERE idempotency_key = ?
          AND route_key = ?
        LIMIT 1
    ");
    $stmt->execute([$idempotencyKey, $routeKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    if (($row['request_hash'] ?? '') !== $requestHash) {
        throw new ApiException('idempotency_conflict', '同一个幂等键对应了不同的请求内容', 409);
    }

    $decoded = json_decode((string) $row['response_body'], true);
    if (!is_array($decoded)) {
        throw new ApiException('idempotency_corrupted', '幂等缓存数据损坏', 500);
    }

    return [
        'status' => (int) $row['response_status'],
        'payload' => $decoded
    ];
}

function api_store_idempotency_response(PDO $db, string $idempotencyKey, string $routeKey, string $requestHash, array $payload, int $status): void {
    $stmt = $db->prepare("
        INSERT INTO api_idempotency_keys (
            idempotency_key, route_key, request_hash, response_body, response_status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT (idempotency_key, route_key) DO UPDATE
        SET request_hash = EXCLUDED.request_hash,
            response_body = EXCLUDED.response_body,
            response_status = EXCLUDED.response_status,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $idempotencyKey,
        $routeKey,
        $requestHash,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $status
    ]);
}
