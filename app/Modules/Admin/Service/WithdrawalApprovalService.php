<?php

declare(strict_types=1);

namespace App\Modules\Admin\Service;

use App\Core\Audit\AuditContext;
use App\Core\Audit\AuditLogger;
use App\Core\Ledger\LedgerService;
use App\Core\Support\Money;
use PDO;

/**
 * Every withdrawal request must terminate in either approval+payout or a
 * reasoned, appealable rejection - never a silent indefinite hold
 * (PROJECT_PLAN.md section 6/8).
 */
final class WithdrawalApprovalService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function approve(int $adminId, int $withdrawalRequestId, ?string $ipAddress = null): array
    {
        $request = $this->fetchRequest($withdrawalRequestId);
        if ($request['status'] !== 'pending') {
            throw new \RuntimeException("Withdrawal request {$withdrawalRequestId} is not pending.");
        }

        return $this->auditLogger->wrap(
            function () use ($request, $adminId) {
                $transactionId = $this->ledger->withdrawExternal(
                    userId: (int) $request['user_id'],
                    amount: Money::fromString($request['amount']),
                    reference: "withdrawal_request:{$request['id']}",
                    approvedByAdminId: $adminId,
                );

                $this->pdo->prepare(
                    'UPDATE withdrawal_requests
                     SET status = "completed", reviewed_by_admin_id = :admin_id, reviewed_at = NOW(), transaction_id = :transaction_id
                     WHERE id = :id'
                )->execute(['admin_id' => $adminId, 'transaction_id' => $transactionId, 'id' => $request['id']]);

                return $this->fetchRequest((int) $request['id']);
            },
            new AuditContext(
                adminId: $adminId,
                actionType: 'withdrawal.approve',
                targetType: 'withdrawal_request',
                targetId: (int) $request['id'],
                beforeState: $request,
                ipAddress: $ipAddress,
            ),
        );
    }

    public function reject(int $adminId, int $withdrawalRequestId, string $reason, ?string $ipAddress = null): array
    {
        $request = $this->fetchRequest($withdrawalRequestId);
        if ($request['status'] !== 'pending') {
            throw new \RuntimeException("Withdrawal request {$withdrawalRequestId} is not pending.");
        }

        return $this->auditLogger->wrap(
            function () use ($request, $adminId, $reason) {
                $this->pdo->prepare(
                    'UPDATE withdrawal_requests
                     SET status = "rejected", rejection_reason = :reason, reviewed_by_admin_id = :admin_id, reviewed_at = NOW()
                     WHERE id = :id'
                )->execute(['reason' => $reason, 'admin_id' => $adminId, 'id' => $request['id']]);

                return $this->fetchRequest((int) $request['id']);
            },
            new AuditContext(
                adminId: $adminId,
                actionType: 'withdrawal.reject',
                targetType: 'withdrawal_request',
                targetId: (int) $request['id'],
                beforeState: $request,
                reason: $reason,
                ipAddress: $ipAddress,
            ),
        );
    }

    private function fetchRequest(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch();

        if ($request === false) {
            throw new \RuntimeException("Withdrawal request not found: {$id}");
        }

        return $request;
    }
}
