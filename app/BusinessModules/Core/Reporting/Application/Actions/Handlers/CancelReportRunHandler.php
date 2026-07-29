<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;

final readonly class CancelReportRunHandler implements CancelReportRunAction
{
    public function __construct(private ReportRunCoordinator $coordinator)
    {
    }

    public function handle(ReportExecutionContext $context, string $runId): ReportRun
    {
        return $this->coordinator->cancel($context, $runId);
    }
}
