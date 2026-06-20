<?php

declare(strict_types=1);

namespace App\Core\Audit;

final class AuditContext
{
    public function __construct(
        public readonly int $adminId,
        public readonly string $actionType,
        public readonly string $targetType,
        public readonly ?int $targetId = null,
        public readonly ?array $beforeState = null,
        public readonly ?string $reason = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
