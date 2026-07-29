<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Actions\Handlers;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportCoordinator;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

final readonly class RetryReportExportHandler implements RetryReportExportAction
{
    public function __construct(
        private ReportExportStore $exports,
        private ReportRunStore $runs,
        private ReportExportCoordinator $coordinator,
    ) {}

    public function handle(
        ReportExecutionContext $context,
        string $exportId,
        IdempotencyKey $key,
    ): ReportExport {
        $export = $this->exports->get($context, $exportId);
        $source = $this->runs->exportSource($context, $export->runId);
        if (! in_array(
            $export->status,
            [ReportExportStatus::FAILED, ReportExportStatus::CANCELLED, ReportExportStatus::EXPIRED],
            true,
        )) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_NOT_READY);
        }

        return $this->coordinator->create(
            $context,
            $source,
            new CreateReportExportData(
                $export->format,
                $export->columns,
                $export->sort,
                $export->locale,
                $export->timezone,
            ),
            $key,
        );
    }
}
