<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Providers;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessSnapshotMaterializer;
use App\Support\Reporting\OwnerProjectionResultFactory;

final readonly class LookaheadReadinessReportProvider implements ReportDataProvider
{
    public function __construct(
        private LookaheadReadinessSnapshotMaterializer $snapshots,
        private OwnerProjectionResultFactory $results,
    ) {
    }

    public function code(): string
    {
        return 'lookahead_readiness';
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
            LookaheadReadinessSnapshot::class,
            'lookahead_events',
        );
    }
}
