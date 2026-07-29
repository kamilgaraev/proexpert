<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use InvalidArgumentException;

final readonly class WorkforceCapacityProvider implements ReportDataProvider
{
    public function __construct(
        private WorkforceReportProjectionService $projection,
        private WorkforceReportQueryService $queryService,
    ) {
    }

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        if ($query->definition->code !== 'workforce_capacity') {
            throw new InvalidArgumentException('workforce_capacity_definition_invalid');
        }
        $progress->advance(10);
        $snapshot = $this->projection->materializeCapacity($context->scope, $query);
        $progress->advance(100);

        return $snapshot;
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        return $this->queryService->result($context, $snapshot);
    }
}
