<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;

final readonly class GetReportRunHandler implements GetReportRunAction
{
    public function __construct(private ReportRunCoordinator $coordinator)
    {
    }

    public function handle(ReportExecutionContext $context, string $runId): ReportRun
    {
        return $this->coordinator->get($context, $runId);
    }
}
