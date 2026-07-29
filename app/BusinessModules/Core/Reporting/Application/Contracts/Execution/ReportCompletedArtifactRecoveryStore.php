<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use DateTimeImmutable;

interface ReportCompletedArtifactRecoveryStore
{
    public function claimExpiredUpload(
        ReportExecutionContext $context,
        string $exportId,
        string $newLeaseToken,
        DateTimeImmutable $newLeaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): ReportExport;
}
