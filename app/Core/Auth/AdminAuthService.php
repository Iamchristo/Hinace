<?php

declare(strict_types=1);

namespace App\Core\Auth;

use PDO;

/**
 * Deliberately separate from AuthService/users: admin staff accounts are
 * never customers, get a short idle timeout, and (in production) mandatory
 * 2FA on every login - see PROJECT_PLAN.md section 1.5.
 */
final class AdminAuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $sessionLifetimeMinutes = 15,
    ) {
    }

    public function attemptLogin(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        if ($admin === false || !password_verify($password, $admin['password_hash'])) {
            return null;
        }

        if ($admin['status'] !== 'active') {
            throw new \RuntimeException('This admin account is disabled.');
        }

        return $admin;
    }

    public function createSession(int $adminUserId, ?string $ipAddress, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_sessions (admin_user_id, token_hash, ip_address, user_agent, expires_at)
             VALUES (:admin_user_id, :token_hash, :ip, :ua, DATE_ADD(NOW(), INTERVAL :minutes MINUTE))'
        );
        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'token_hash' => $tokenHash,
            'ip' => $ipAddress,
            'ua' => $userAgent,
            'minutes' => $this->sessionLifetimeMinutes,
        ]);

        $this->pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $adminUserId]);

        return $token;
    }

    public function validateSession(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        $stmt = $this->pdo->prepare(
            'SELECT a.* FROM admin_sessions s
             JOIN admin_users a ON a.id = s.admin_user_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $admin = $stmt->fetch();

        if ($admin === false || $admin['status'] !== 'active') {
            return null;
        }

        return $admin;
    }

    public function revokeSession(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->pdo->prepare('UPDATE admin_sessions SET revoked_at = NOW() WHERE token_hash = :token_hash')
            ->execute(['token_hash' => $tokenHash]);
    }

    public function permissionsFor(int $adminUserId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.key FROM admin_permissions p
             JOIN admin_role_permissions rp ON rp.permission_id = p.id
             JOIN admin_user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.admin_user_id = :admin_user_id'
        );
        $stmt->execute(['admin_user_id' => $adminUserId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
