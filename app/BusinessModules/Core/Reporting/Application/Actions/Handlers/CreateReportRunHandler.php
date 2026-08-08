<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentPromptPublisher;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

final readonly class CreateReportRunHandler implements CreateReportRunAction
{
    public function __construct(
        private ReportRunCoordinator $coordinator,
        private ReportDispatchIntentPromptPublisher $promptPublisher,
    ) {
    }

    public function handle(ReportExecutionContext $context, CreateReportRunData $data, IdempotencyKey $key): ReportRun
    {
        $run = $this->coordinator->create($context, $data, $key);
        $this->promptPublisher->publishPending();

        return $run;
    }
}
