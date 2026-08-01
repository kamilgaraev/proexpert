<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

interface CreateReportRunAction
{
    public function handle(ReportExecutionContext $context, CreateReportRunData $data, IdempotencyKey $key): ReportRun;
}
