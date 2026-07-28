<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use DateTimeImmutable;

final readonly class ReportDispatchLease
{
    public function __construct(
        public ReportDispatchIntent $intent,
        public string $leaseToken,
        public DateTimeImmutable $leaseExpiresAt,
    ) {}
}
