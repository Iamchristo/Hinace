<?php

declare(strict_types=1);

namespace App\Modules\Admin\Service;

use App\Core\Audit\AuditContext;
use App\Core\Audit\AuditLogger;
use App\Core\Ledger\WalletService;
use PDO;

/**
 * Compliance-oriented admin actions only: freeze/suspend, never a way to
 * permanently block a user from their own funds (see PROJECT_PLAN.md
 * section 6/8 - withdrawal approval always terminates in payout or a
 * reasoned, appealable rejection).
 */
final class UserComplianceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WalletService $wallets,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function suspendUser(int $adminId, int $userId, string $reason, ?string $ipAddress = null): array
    {
        $before = $this->fetchUser($userId);

        return $this->auditLogger->wrap(
            function () use ($userId) {
                $this->pdo->prepare('UPDATE users SET status = "suspended" WHERE id = :id')->execute(['id' => $userId]);

                return $this->fetchUser($userId);
            },
            new AuditContext(
                adminId: $adminId,
                actionType: 'user.suspend',
                targetType: 'user',
                targetId: $userId,
                beforeState: $before,
                reason: $reason,
                ipAddress: $ipAddress,
            ),
        );
    }

    public function unsuspendUser(int $adminId, int $userId, string $reason, ?string $ipAddress = null): array
    {
        $before = $this->fetchUser($userId);

        return $this->auditLogger->wrap(
            function () use ($userId) {
                $this->pdo->prepare('UPDATE users SET status = "active" WHERE id = :id')->execute(['id' => $userId]);

                return $this->fetchUser($userId);
            },
            new AuditContext(
                adminId: $adminId,
                actionType: 'user.unsuspend',
                targetType: 'user',
                targetId: $userId,
                beforeState: $before,
                reason: $reason,
                ipAddress: $ipAddress,
            ),
        );
    }

    /**
     * Freezes a single ledger (e.g. just the forex sub-ledger of a user
     * under investigation) without touching the other 3. $status is one of
     * 'active', 'frozen_debit' (stop outflow, allow inflow), 'frozen_all'.
     */
    public function freezeLedger(int $adminId, int $userId, string $ledgerType, string $status, string $reason, ?string $ipAddress = null): array
    {
        $wallet = $this->wallets->getOrCreateWallet($userId, $ledgerType);
        $before = $wallet;

        return $this->auditLogger->wrap(
            function () use ($wallet, $status) {
                $this->wallets->setStatus((int) $wallet['id'], $status);

                return $this->wallets->getOrCreateWallet((int) $wallet['user_id'], $wallet['ledger_type'], $wallet['currency']);
            },
            new AuditContext(
                adminId: $adminId,
                actionType: 'ledger.freeze',
                targetType: 'wallet',
                targetId: (int) $wallet['id'],
                beforeState: $before,
                reason: $reason,
                ipAddress: $ipAddress,
            ),
        );
    }

    private function fetchUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, status, kyc_tier FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        return $user;
    }
}
