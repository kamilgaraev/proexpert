<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportExpiredExecutionLease;
use DateTimeImmutable;

interface ReportRunLeaseRecoveryStore
{
    /** @return list<ReportExpiredExecutionLease> */
    public function due(int $limit, DateTimeImmutable $occurredAt): array;

    public function requeue(ReportExpiredExecutionLease $lease, DateTimeImmutable $occurredAt): bool;
}
