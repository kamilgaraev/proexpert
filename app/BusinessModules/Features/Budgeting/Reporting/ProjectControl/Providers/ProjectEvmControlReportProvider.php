<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Providers;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectControlCoreSnapshotFactory;
use App\Support\Reporting\OwnerProjectionResultFactory;

final readonly class ProjectEvmControlReportProvider implements ReportDataProvider
{
    public function __construct(
        private ProjectControlCoreSnapshotFactory $snapshots,
        private OwnerProjectionResultFactory $results,
    ) {
    }

    public function code(): string
    {
        return 'project_evm_control';
    }

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $progress->advance(10);
        $snapshot = $this->snapshots->materialize($context->scope, $query);
        $progress->advance(100);

        return $snapshot;
    }

    public function result(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): ReportResult {
        return $this->results->make(
            $context,
            $snapshot,
            ProjectControlSnapshot::class,
            'project_control',
            ['ac_minor', 'approved_etc_minor', 'cv_minor', 'cpi', 'eac_minor', 'vac_minor', 'tcpi'],
        );
    }
}
