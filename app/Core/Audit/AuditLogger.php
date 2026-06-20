<?php

declare(strict_types=1);

namespace App\Core\Audit;

use PDO;

/**
 * Every admin controller action that mutates state must go through wrap().
 * The audit row is written in the same DB transaction as the mutation, so
 * it is impossible for an action to succeed without being logged: if the
 * audit insert fails, the mutation rolls back too, and vice versa.
 */
final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param callable $mutate Performs the admin action and returns the
     *   resulting "after" state as an array (or null), which is stored in
     *   audit_log.after_state and also returned to the caller.
     */
    public function wrap(callable $mutate, AuditContext $context): ?array
    {
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $afterState = $mutate();
            $this->record($context, $afterState);

            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }

            return $afterState;
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function record(AuditContext $context, ?array $afterState): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log
                (admin_id, action_type, target_type, target_id, before_state, after_state, reason, ip_address, user_agent)
             VALUES
                (:admin_id, :action_type, :target_type, :target_id, :before_state, :after_state, :reason, :ip_address, :user_agent)'
        );
        $stmt->execute([
            'admin_id' => $context->adminId,
            'action_type' => $context->actionType,
            'target_type' => $context->targetType,
            'target_id' => $context->targetId,
            'before_state' => $context->beforeState !== null ? json_encode($context->beforeState) : null,
            'after_state' => $afterState !== null ? json_encode($afterState) : null,
            'reason' => $context->reason,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
        ]);
    }

    public function recordAccess(int $adminId, string $targetType, ?int $targetId, ?string $ipAddress): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_access_log (admin_id, target_type, target_id, ip_address)
             VALUES (:admin_id, :target_type, :target_id, :ip_address)'
        );
        $stmt->execute([
            'admin_id' => $adminId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $ipAddress,
        ]);
    }
}
