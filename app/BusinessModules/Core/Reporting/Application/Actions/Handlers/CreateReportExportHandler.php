<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportCoordinator;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

final readonly class CreateReportExportHandler implements CreateReportExportAction
{
    public function __construct(
        private ReportRunStore $runs,
        private ReportExportCoordinator $coordinator,
    ) {}

    public function handle(
        ReportExecutionContext $context,
        string $runId,
        CreateReportExportData $data,
        IdempotencyKey $key,
    ): ReportExport {
        return $this->coordinator->create(
            $context,
            $this->runs->exportSource($context, $runId),
            $data,
            $key,
        );
    }
}
