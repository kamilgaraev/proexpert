<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\BaselineScheduleSnapshot;
use App\Support\Reporting\OwnerProjectionResultFactory;

final readonly class BaselineScheduleVarianceProvider implements ReportDataProvider
{
    public function __construct(
        private BaselineScheduleSnapshotService $snapshots,
        private OwnerProjectionResultFactory $results,
    ) {
    }

    public function code(): string
    {
        return 'baseline_schedule_variance';
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
            BaselineScheduleSnapshot::class,
            'schedule_baseline',
        );
    }
}
