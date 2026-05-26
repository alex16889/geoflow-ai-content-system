<?php
/**
 * API 管理员登录服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/security.php';

class ApiAdminAuthService {
    public function __construct(
        private PDO $db,
        private ApiTokenService $tokenService
    ) {
    }

    public function login(string $username, string $password, string $ipAddress = '', string $userAgent = ''): array {
        $username = trim($username);
        if ($username === '' || $password === '') {
            $fieldErrors = [];
            if ($username === '') {
                $fieldErrors['username'] = '用户名不能为空';
            }
            if ($password === '') {
                $fieldErrors['password'] = '密码不能为空';
            }
            throw new ApiException('validation_failed', '用户名和密码不能为空', 422, [
                'field_errors' => $fieldErrors
            ]);
        }

        $ipForThrottle = $ipAddress !== '' ? $ipAddress : 'api_cli';
        if (!check_login_attempts($ipForThrottle)) {
            throw new ApiException('too_many_attempts', '登录尝试过多，请稍后再试', 429);
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM admins
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || ($admin['status'] ?? 'active') !== 'active' || !password_verify($password, (string) ($admin['password'] ?? ''))) {
            record_login_attempt($ipForThrottle, false);
            throw new ApiException('invalid_credentials', '用户名或密码错误，或账号已被停用', 401);
        }

        record_login_attempt($ipForThrottle, true);

        $this->db->beginTransaction();
        try {
            $updateStmt = $this->db->prepare("
                UPDATE admins
                SET last_login = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updateStmt->execute([(int) $admin['id']]);

            $tokenResult = $this->tokenService->createToken(
                'CLI Login ' . $admin['username'] . ' ' . date('Y-m-d H:i:s'),
                $this->tokenService->getAvailableScopes(),
                (int) $admin['id']
            );

            log_admin_activity('api:auth:login', [
                'request_method' => 'POST',
                'page' => 'api/v1/auth/login',
                'details' => [
                    'username' => $admin['username'],
                    'role' => $admin['role'] ?? 'admin',
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent
                ]
            ], (int) $admin['id']);

            $this->db->commit();

            return [
                'token' => $tokenResult['token'],
                'expires_at' => $tokenResult['record']['expires_at'] ?? null,
                'admin' => [
                    'id' => (int) $admin['id'],
                    'username' => $admin['username'],
                    'display_name' => $admin['display_name'] ?? '',
                    'role' => $admin['role'] ?? 'admin',
                    'status' => $admin['status'] ?? 'active'
                ]
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
