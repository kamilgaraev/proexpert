<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use DateTimeImmutable;

final readonly class ReportAuditIntentLease
{
    public function __construct(
        public string $intentId,
        public string $leaseToken,
        public DateTimeImmutable $leaseExpiresAt,
        public int $attemptCount,
    ) {}
}
