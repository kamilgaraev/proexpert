<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

interface RetryReportExportAction
{
    public function handle(ReportExecutionContext $context, string $exportId, IdempotencyKey $idempotencyKey): ReportExport;
}
