<?php
/**
 * API v1 单入口
 */

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database_admin.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/api_response.php';
require_once __DIR__ . '/../../includes/api_request.php';
require_once __DIR__ . '/../../includes/api_token_service.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/api_admin_auth_service.php';
require_once __DIR__ . '/../../includes/catalog_service.php';
require_once __DIR__ . '/../../includes/task_lifecycle_service.php';
require_once __DIR__ . '/../../includes/article_service.php';

$request = new ApiRequest();
$requestId = $request->getRequestId();
$statusCode = 200;
$responsePayload = [];
$routeKey = null;
$shouldStoreIdempotency = false;

try {
    $tokenService = new ApiTokenService($db);
    $auth = new ApiAuth($tokenService);
    $authContext = null;

    $catalogService = new CatalogService($db);
    $taskService = new TaskLifecycleService($db);
    $articleService = new ArticleService($db);
    $adminAuthService = new ApiAdminAuthService($db, $tokenService);

    $segments = $request->getSegments();
    $method = $request->getMethod();
    $body = $request->getBody();

    if ($segments === []) {
        throw new ApiException('not_found', '接口不存在', 404);
    }

    $isAuthLoginRoute = $segments[0] === 'auth' && $method === 'POST' && count($segments) === 2 && $segments[1] === 'login';

    if ($isAuthLoginRoute) {
        $responsePayload = api_build_success_payload($adminAuthService->login(
            trim((string) ($body['username'] ?? '')),
            (string) ($body['password'] ?? ''),
            api_detect_client_ip(),
            trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
        ), $requestId);
        $statusCode = 200;
    } else {
        $authContext = $auth->authenticate($request);
    }

    if ($isAuthLoginRoute) {
        // no-op, handled above
    } elseif ($segments[0] === 'catalog' && $method === 'GET' && count($segments) === 1) {
        $auth->requireScope($authContext, 'catalog:read');
        $responsePayload = api_build_success_payload($catalogService->getCatalog(), $requestId);
        $statusCode = 200;
    } elseif ($segments[0] === 'tasks') {
        if ($method === 'GET' && count($segments) === 1) {
            $auth->requireScope($authContext, 'tasks:read');
            $data = $taskService->listTasks(
                $request->getQueryInt('page', 1),
                $request->getQueryInt('per_page', 20),
                [
                    'status' => $request->getQueryString('status'),
                    'search' => $request->getQueryString('search')
                ]
            );
            $responsePayload = api_build_success_payload($data, $requestId);
            $statusCode = 200;
        } elseif ($method === 'POST' && count($segments) === 1) {
            $auth->requireScope($authContext, 'tasks:write');
            $routeKey = 'POST /tasks';
            $shouldStoreIdempotency = true;
            if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                api_emit_payload($cached['payload'], $cached['status']);
            }
            $responsePayload = api_build_success_payload($taskService->createTask($body), $requestId);
            $statusCode = 201;
        } elseif (count($segments) >= 2 && ctype_digit($segments[1])) {
            $taskId = (int) $segments[1];

            if ($method === 'GET' && count($segments) === 2) {
                $auth->requireScope($authContext, 'tasks:read');
                $responsePayload = api_build_success_payload($taskService->getTask($taskId), $requestId);
                $statusCode = 200;
            } elseif ($method === 'PATCH' && count($segments) === 2) {
                $auth->requireScope($authContext, 'tasks:write');
                $routeKey = 'PATCH /tasks/{id}';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $responsePayload = api_build_success_payload($taskService->updateTask($taskId, $body), $requestId);
                $statusCode = 200;
            } elseif ($method === 'POST' && count($segments) === 3 && $segments[2] === 'start') {
                $auth->requireScope($authContext, 'tasks:write');
                $routeKey = 'POST /tasks/{id}/start';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $enqueueNow = !empty($body['enqueue_now']);
                $responsePayload = api_build_success_payload($taskService->startTask($taskId, $enqueueNow), $requestId);
                $statusCode = 200;
            } elseif ($method === 'POST' && count($segments) === 3 && $segments[2] === 'stop') {
                $auth->requireScope($authContext, 'tasks:write');
                $routeKey = 'POST /tasks/{id}/stop';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $responsePayload = api_build_success_payload($taskService->stopTask($taskId), $requestId);
                $statusCode = 200;
            } elseif ($method === 'POST' && count($segments) === 3 && $segments[2] === 'enqueue') {
                $auth->requireScope($authContext, 'tasks:write');
                $routeKey = 'POST /tasks/{id}/enqueue';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $jobType = trim((string) ($body['job_type'] ?? 'generate_article'));
                $payload = $body;
                unset($payload['job_type']);
                $responsePayload = api_build_success_payload($taskService->enqueueTask($taskId, $jobType, $payload), $requestId);
                $statusCode = 201;
            } elseif ($method === 'GET' && count($segments) === 3 && $segments[2] === 'jobs') {
                $auth->requireScope($authContext, 'tasks:read');
                $responsePayload = api_build_success_payload($taskService->listTaskJobs(
                    $taskId,
                    $request->getQueryString('status', ''),
                    $request->getQueryInt('limit', 20)
                ), $requestId);
                $statusCode = 200;
            } else {
                throw new ApiException('not_found', '接口不存在', 404);
            }
        } else {
            throw new ApiException('not_found', '接口不存在', 404);
        }
    } elseif ($segments[0] === 'jobs' && $method === 'GET' && count($segments) === 2 && ctype_digit($segments[1])) {
        $auth->requireScope($authContext, 'jobs:read');
        $responsePayload = api_build_success_payload($taskService->getJob((int) $segments[1]), $requestId);
        $statusCode = 200;
    } elseif ($segments[0] === 'articles') {
        if ($method === 'GET' && count($segments) === 1) {
            $auth->requireScope($authContext, 'articles:read');
            $responsePayload = api_build_success_payload($articleService->listArticles(
                $request->getQueryInt('page', 1),
                $request->getQueryInt('per_page', 20),
                [
                    'task_id' => $request->getQueryInt('task_id', 0),
                    'status' => $request->getQueryString('status'),
                    'review_status' => $request->getQueryString('review_status'),
                    'author_id' => $request->getQueryInt('author_id', 0),
                    'search' => $request->getQueryString('search')
                ]
            ), $requestId);
            $statusCode = 200;
        } elseif ($method === 'POST' && count($segments) === 1) {
            $auth->requireScope($authContext, 'articles:write');
            $routeKey = 'POST /articles';
            $shouldStoreIdempotency = true;
            if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                api_emit_payload($cached['payload'], $cached['status']);
            }
            $responsePayload = api_build_success_payload($articleService->createArticle($body), $requestId);
            $statusCode = 201;
        } elseif (count($segments) >= 2 && ctype_digit($segments[1])) {
            $articleId = (int) $segments[1];

            if ($method === 'GET' && count($segments) === 2) {
                $auth->requireScope($authContext, 'articles:read');
                $responsePayload = api_build_success_payload($articleService->getArticle($articleId), $requestId);
                $statusCode = 200;
            } elseif ($method === 'PATCH' && count($segments) === 2) {
                $auth->requireScope($authContext, 'articles:write');
                $routeKey = 'PATCH /articles/{id}';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $responsePayload = api_build_success_payload($articleService->updateArticle($articleId, $body), $requestId);
                $statusCode = 200;
            } elseif ($method === 'POST' && count($segments) === 3 && $segments[2] === 'review') {
                $auth->requireScope($authContext, 'articles:publish');
                $routeKey = 'POST /articles/{id}/review';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $responsePayload = api_build_success_payload($articleService->reviewArticle(
                    $articleId,
                    trim((string) ($body['review_status'] ?? '')),
                    trim((string) ($body['review_note'] ?? '')),
                    $authContext->auditAdminId
                ), $requestId);
                $statusCode = 200;
            } elseif ($method === 'POST' && count($segments) === 3 && $segments[2] === 'publish') {
                $auth->requireScope($authContext, 'articles:publish');
                $routeKey = 'POST /articles/{id}/publish';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $responsePayload = api_build_success_payload($articleService->publishArticle($articleId), $requestId);
                $statusCode = 200;
            } elseif ($method === 'POST' && count($segments) === 3 && $segments[2] === 'trash') {
                $auth->requireScope($authContext, 'articles:write');
                $routeKey = 'POST /articles/{id}/trash';
                $shouldStoreIdempotency = true;
                if ($cached = api_handle_idempotency_if_needed($db, $request, $routeKey)) {
                    api_emit_payload($cached['payload'], $cached['status']);
                }
                $responsePayload = api_build_success_payload($articleService->trashArticle($articleId), $requestId);
                $statusCode = 200;
            } else {
                throw new ApiException('not_found', '接口不存在', 404);
            }
        } else {
            throw new ApiException('not_found', '接口不存在', 404);
        }
    } else {
        throw new ApiException('not_found', '接口不存在', 404);
    }

    if ($shouldStoreIdempotency && $routeKey !== null && $request->getIdempotencyKey()) {
        api_store_idempotency_response(
            $db,
            $request->getIdempotencyKey(),
            $routeKey,
            api_request_hash($request->getBody()),
            $responsePayload,
            $statusCode
        );
    }
} catch (ApiException $e) {
    $statusCode = $e->getHttpStatus();
    $responsePayload = api_build_error_payload($e->getErrorCode(), $e->getMessage(), $requestId, $e->getDetails());
} catch (Throwable $e) {
    $statusCode = 500;
    $responsePayload = api_build_error_payload('internal_error', '服务器内部错误', $requestId);
    write_log('API v1 error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'ERROR');
}

api_emit_payload($responsePayload, $statusCode);

function api_detect_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $parts = array_map('trim', explode(',', $candidate));
        foreach ($parts as $part) {
            if ($part !== '') {
                return mb_substr($part, 0, 100);
            }
        }
    }

    return '';
}

function api_handle_idempotency_if_needed(PDO $db, ApiRequest $request, string $routeKey): ?array {
    $idempotencyKey = $request->getIdempotencyKey();
    if ($idempotencyKey === null || $idempotencyKey === '') {
        return null;
    }

    return api_load_idempotency_response(
        $db,
        $idempotencyKey,
        $routeKey,
        api_request_hash($request->getBody())
    );
}
