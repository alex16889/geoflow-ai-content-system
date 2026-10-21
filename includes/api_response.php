<?php
/**
 * API 响应与异常工具
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/error_response.php';

class ApiException extends RuntimeException {
    private int $httpStatus;
    private string $errorCode;
    private array $details;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400, array $details = []) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }

    public function getErrorCode(): string {
        return $this->errorCode;
    }

    public function getDetails(): array {
        return $this->details;
    }
}

function api_build_success_payload(array $data, string $requestId): array {
    return [
        'success' => true,
        'data' => $data,
        'error' => null,
        'meta' => [
            'request_id' => $requestId,
            'timestamp' => date(DATE_ATOM)
        ]
    ];
}

function api_build_error_payload(string $code, string $message, string $requestId, array $details = []): array {
    $error = [
        'code' => $code,
        'message' => geoflow_redact_public_text($message, '请求处理失败，请稍后重试')
    ];

    if (!empty($details)) {
        $error['details'] = geoflow_redact_public_value($details);
    }

    return [
        'success' => false,
        'data' => null,
        'error' => $error,
        'meta' => [
            'request_id' => $requestId,
            'timestamp' => date(DATE_ATOM)
        ]
    ];
}

function api_emit_payload(array $payload, int $status): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
