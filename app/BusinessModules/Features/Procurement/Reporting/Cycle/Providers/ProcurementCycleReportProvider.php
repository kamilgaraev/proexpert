<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Providers;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleSnapshotMaterializer;

final readonly class ProcurementCycleReportProvider implements ReportDataProvider
{
    public function __construct(private ProcurementCycleSnapshotMaterializer $materializer) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
    {
        return $this->materializer->materialize($context, $query, $progress);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        return $this->materializer->result($context, $snapshot);
    }
}
