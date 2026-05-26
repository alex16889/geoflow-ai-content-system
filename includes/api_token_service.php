<?php
/**
 * API Token 服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class ApiTokenService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function createToken(string $name, array $scopes, ?int $adminId, ?string $expiresAt = null): array {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException('validation_failed', 'Token 名称不能为空', 422, [
                'field_errors' => ['name' => 'Token 名称不能为空']
            ]);
        }

        $scopes = $this->normalizeScopes($scopes);
        if (empty($scopes)) {
            throw new ApiException('validation_failed', '至少选择一个 scope', 422, [
                'field_errors' => ['scopes' => '至少选择一个 scope']
            ]);
        }

        $token = 'gf_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $expiresAt = $this->normalizeExpiresAt($expiresAt);
        $adminId = $this->normalizeCreatorAdminId($adminId);

        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (
                name, token_hash, scopes, status, created_by_admin_id, last_used_at, expires_at, created_at, updated_at
            ) VALUES (?, ?, ?::jsonb, 'active', ?, NULL, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $name,
            $tokenHash,
            json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $adminId,
            $expiresAt
        ]);

        $id = db_last_insert_id($this->db, 'api_tokens');
        return [
            'token' => $token,
            'record' => $this->getTokenById($id)
        ];
    }

    public function revokeToken(int $tokenId): void {
        $stmt = $this->db->prepare("
            UPDATE api_tokens
            SET status = 'revoked',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$tokenId]);
        if ($stmt->rowCount() !== 1) {
            throw new ApiException('token_not_found', 'Token 不存在', 404);
        }
    }

    public function listTokens(): array {
        $rows = $this->db->query("
            SELECT at.*, a.username AS created_by_username
            FROM api_tokens at
            LEFT JOIN admins a ON a.id = at.created_by_admin_id
            ORDER BY at.created_at DESC, at.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            return $this->hydrateTokenRow($row);
        }, $rows);
    }

    public function getTokenById(int $tokenId): ?array {
        $stmt = $this->db->prepare("
            SELECT at.*, a.username AS created_by_username
            FROM api_tokens at
            LEFT JOIN admins a ON a.id = at.created_by_admin_id
            WHERE at.id = ?
            LIMIT 1
        ");
        $stmt->execute([$tokenId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateTokenRow($row) : null;
    }

    public function getActiveTokenByPlaintext(string $plainToken): ?array {
        $stmt = $this->db->prepare("
            SELECT at.*, a.username AS created_by_username
            FROM api_tokens at
            LEFT JOIN admins a ON a.id = at.created_by_admin_id
            WHERE at.token_hash = ?
              AND at.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([hash('sha256', $plainToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $token = $this->hydrateTokenRow($row);
        $expiresAt = $token['expires_at'] ?? null;
        if ($expiresAt && strtotime((string) $expiresAt) < time()) {
            return null;
        }

        return $token;
    }

    public function touchToken(int $tokenId): void {
        $stmt = $this->db->prepare("
            UPDATE api_tokens
            SET last_used_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$tokenId]);
    }

    public function tokenHasScope(array $token, string $scope): bool {
        $scopes = $token['scopes'] ?? [];
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function resolveAuditAdminId(?int $preferredAdminId): int {
        if ($preferredAdminId !== null && $preferredAdminId > 0) {
            $stmt = $this->db->prepare("SELECT id FROM admins WHERE id = ? LIMIT 1");
            $stmt->execute([$preferredAdminId]);
            $adminId = (int) $stmt->fetchColumn();
            if ($adminId > 0) {
                return $adminId;
            }
        }

        $fallback = (int) $this->db->query("SELECT id FROM admins ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($fallback <= 0) {
            throw new ApiException('admin_not_found', '系统中不存在可用的管理员账号', 500);
        }

        return $fallback;
    }

    public function getAvailableScopes(): array {
        return [
            'catalog:read',
            'tasks:read',
            'tasks:write',
            'jobs:read',
            'articles:read',
            'articles:write',
            'articles:publish'
        ];
    }

    private function normalizeScopes(array $scopes): array {
        $allowed = $this->getAvailableScopes();
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '' && in_array($scope, $allowed, true)) {
                $normalized[] = $scope;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function normalizeExpiresAt(?string $expiresAt): ?string {
        $expiresAt = $expiresAt !== null ? trim($expiresAt) : null;
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            throw new ApiException('validation_failed', '过期时间格式无效', 422, [
                'field_errors' => ['expires_at' => '过期时间格式无效']
            ]);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeCreatorAdminId(?int $adminId): ?int {
        if ($adminId === null || $adminId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([$adminId]);
        $value = (int) $stmt->fetchColumn();
        return $value > 0 ? $value : null;
    }

    private function hydrateTokenRow(array $row): array {
        $row['scopes'] = json_decode((string) ($row['scopes'] ?? '[]'), true);
        if (!is_array($row['scopes'])) {
            $row['scopes'] = [];
        }
        return $row;
    }
}
