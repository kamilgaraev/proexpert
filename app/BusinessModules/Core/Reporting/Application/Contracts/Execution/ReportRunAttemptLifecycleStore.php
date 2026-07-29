<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;

interface ReportRunAttemptLifecycleStore
{
    public function claimOrRenew(string $runId, string $envelopeUuid, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): bool;

    public function failLeased(string $runId, string $envelopeUuid, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): bool;
}
