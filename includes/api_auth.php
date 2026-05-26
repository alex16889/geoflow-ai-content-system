<?php
/**
 * API 鉴权
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class ApiAuthContext {
    public array $token;
    public int $auditAdminId;

    public function __construct(array $token, int $auditAdminId) {
        $this->token = $token;
        $this->auditAdminId = $auditAdminId;
    }
}

class ApiAuth {
    private ApiTokenService $tokenService;

    public function __construct(ApiTokenService $tokenService) {
        $this->tokenService = $tokenService;
    }

    public function authenticate(ApiRequest $request): ApiAuthContext {
        $authorization = $request->getHeader('Authorization');
        if ($authorization === null || $authorization === '') {
            throw new ApiException('unauthorized', '缺少 Authorization 头', 401);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', 'Authorization 格式无效', 401);
        }

        $tokenValue = trim($matches[1]);
        if ($tokenValue === '') {
            throw new ApiException('unauthorized', 'Token 不能为空', 401);
        }

        $token = $this->tokenService->getActiveTokenByPlaintext($tokenValue);
        if (!$token) {
            throw new ApiException('unauthorized', 'Token 无效或已过期', 401);
        }

        $this->tokenService->touchToken((int) $token['id']);
        $auditAdminId = $this->tokenService->resolveAuditAdminId(isset($token['created_by_admin_id']) ? (int) $token['created_by_admin_id'] : null);
        return new ApiAuthContext($token, $auditAdminId);
    }

    public function requireScope(ApiAuthContext $context, string $scope): void {
        if (!$this->tokenService->tokenHasScope($context->token, $scope)) {
            throw new ApiException('forbidden', '当前 Token 没有访问此接口的权限', 403, [
                'required_scope' => $scope
            ]);
        }
    }
}
