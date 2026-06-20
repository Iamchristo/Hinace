<?php

declare(strict_types=1);

namespace App\Core\Auth;

use PDO;

/**
 * One identity, one session, shared across all 3 dashboards. Module access
 * (which dashboards/ledgers a session can touch) is an authorization check
 * per request, not a separate login - see ModuleAccessMiddleware.
 */
final class AuthService
{
    private const SESSION_TOKEN_BYTES = 32;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $sessionLifetimeMinutes = 120,
    ) {
    }

    public function register(string $email, string $password, ?string $referredByCode = null): int
    {
        $existing = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $existing->execute(['email' => $email]);
        if ($existing->fetch() !== false) {
            throw new \RuntimeException('An account with this email already exists.');
        }

        $referredByUserId = null;
        if ($referredByCode !== null) {
            $ref = $this->pdo->prepare('SELECT id FROM users WHERE referral_code = :code');
            $ref->execute(['code' => $referredByCode]);
            $row = $ref->fetch();
            $referredByUserId = $row !== false ? (int) $row['id'] : null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, referral_code, referred_by_user_id)
             VALUES (:email, :password_hash, :referral_code, :referred_by)'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'referral_code' => $this->generateReferralCode(),
            'referred_by' => $referredByUserId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function attemptLogin(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        if ($user['status'] !== 'active') {
            throw new \RuntimeException('This account is not active.');
        }

        return $user;
    }

    /**
     * Creates a DB-backed session row and returns the plaintext token to set
     * in the session cookie. Only the hash is stored, so a DB leak doesn't
     * leak usable session tokens.
     */
    public function createSession(int $userId, ?string $ipAddress, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(self::SESSION_TOKEN_BYTES));
        $tokenHash = hash('sha256', $token);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at)
             VALUES (:user_id, :token_hash, :ip, :ua, DATE_ADD(NOW(), INTERVAL :minutes MINUTE))'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'ip' => $ipAddress,
            'ua' => $userAgent,
            'minutes' => $this->sessionLifetimeMinutes,
        ]);

        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $userId]);

        return $token;
    }

    /**
     * Validates a session token and returns the associated user, or null if
     * the session is missing, revoked, or expired. Revocation here is what
     * makes admin "force logout"/suspend take effect immediately rather than
     * waiting for a cookie to expire on its own.
     */
    public function validateSession(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $user = $stmt->fetch();

        if ($user === false || $user['status'] !== 'active') {
            return null;
        }

        return $user;
    }

    public function revokeSession(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE token_hash = :token_hash')
            ->execute(['token_hash' => $tokenHash]);
    }

    public function revokeAllSessionsForUser(int $userId): void
    {
        $this->pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = :user_id AND revoked_at IS NULL')
            ->execute(['user_id' => $userId]);
    }

    private function generateReferralCode(): string
    {
        return strtoupper(bin2hex(random_bytes(5)));
    }
}
