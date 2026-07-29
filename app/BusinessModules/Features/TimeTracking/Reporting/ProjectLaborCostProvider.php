<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use InvalidArgumentException;

final readonly class ProjectLaborCostProvider implements ReportDataProvider
{
    public function __construct(
        private ProjectLaborCostProjectionService $projection,
        private ProjectLaborCostQueryService $queryService,
    ) {
    }

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        if ($query->definition->code !== 'project_labor_cost') {
            throw new InvalidArgumentException('project_labor_cost_definition_invalid');
        }
        $progress->advance(10);
        $snapshot = $this->projection->materialize($context->scope, $query);
        $progress->advance(100);

        return $snapshot;
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        return $this->queryService->result($context, $snapshot);
    }
}
