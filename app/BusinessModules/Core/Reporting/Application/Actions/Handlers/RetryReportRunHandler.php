<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

final readonly class RetryReportRunHandler implements RetryReportRunAction
{
    public function __construct(private ReportRunCoordinator $coordinator)
    {
    }

    public function handle(ReportExecutionContext $context, string $runId, IdempotencyKey $idempotencyKey): ReportRun
    {
        return $this->coordinator->retry($context, $runId, $idempotencyKey);
    }
}
