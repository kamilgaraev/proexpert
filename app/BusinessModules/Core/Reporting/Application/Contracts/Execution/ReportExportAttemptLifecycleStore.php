<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;

interface ReportExportAttemptLifecycleStore
{
    public function claimOrRenew(
        string $exportId,
        string $envelopeUuid,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): bool;

    public function failLeased(
        string $exportId,
        string $envelopeUuid,
        ReportErrorCode $errorCode,
        DateTimeImmutable $occurredAt,
    ): bool;
}
