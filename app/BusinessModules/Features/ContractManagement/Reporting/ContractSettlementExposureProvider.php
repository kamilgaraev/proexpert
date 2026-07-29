<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;

final readonly class ContractSettlementExposureProvider implements ReportDataProvider
{
    public function __construct(
        private ContractSettlementProjectionService $projection,
        private ContractSettlementQueryService $query,
    ) {
    }

    public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
    {
        $snapshot = $this->projection->materialize($context->scope, $query);
        $progress->advance(100);

        return $snapshot;
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        return $this->query->result($context, $snapshot);
    }
}
