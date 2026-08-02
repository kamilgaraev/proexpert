<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Providers;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionSnapshot;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionSnapshotMaterializer;
use App\Support\Reporting\OwnerProjectionResultFactory;

final readonly class AcceptedProductionReportProvider implements ReportDataProvider
{
    public function __construct(
        private AcceptedProductionSnapshotMaterializer $snapshots,
        private OwnerProjectionResultFactory $results,
    ) {
    }

    public function code(): string
    {
        return 'accepted_production_progress';
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
            AcceptedProductionSnapshot::class,
            'production_acceptance_events',
            ['approved_rate_minor', 'accepted_amount_minor'],
        );
    }
}
