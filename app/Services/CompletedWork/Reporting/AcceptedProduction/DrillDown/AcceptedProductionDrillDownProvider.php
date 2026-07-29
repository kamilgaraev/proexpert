<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionRow;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\AcceptedProductionSnapshot;
use App\Support\Reporting\ImmutableOwnerProjectionReader;

final readonly class AcceptedProductionDrillDownProvider implements ReportDrillDownProvider
{
    private ImmutableOwnerProjectionReader $reader;

    public function __construct()
    {
        $this->reader = new ImmutableOwnerProjectionReader(
            AcceptedProductionRow::class,
            AcceptedProductionSnapshot::class,
            ['recognized_on' => 'recognized_on'],
            ['approved_rate_minor', 'accepted_amount_minor'],
        );
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $row = $this->reader->findRow(
            $context,
            $snapshot,
            $this->reader->rowKeyFromToken($request->token),
        );
        $rows = [];
        if ($row !== null) {
            $rows[] = [
                'row_key' => 'accepted_production:'.$row['row_key'],
                'project_id' => $row['project_id'],
                'performance_act_id' => $row['performance_act_id'],
                'source_line_type' => $row['source_line_type'],
                'source_line_id' => $row['source_line_id'],
                'work_id' => $row['work_id'],
                'accepted_quantity' => $row['accepted_quantity'],
                ...array_intersect_key($row, array_flip(['approved_rate_minor', 'accepted_amount_minor'])),
            ];
            foreach ([
                'performance_act' => $row['performance_act_id'],
                $row['source_line_type'] => $row['source_line_id'],
                'completed_work' => $row['work_id'],
            ] as $type => $id) {
                $rows[] = [
                    'row_key' => $type.':'.$id,
                    'project_id' => $row['project_id'],
                    'resource_type' => $type,
                    'resource_id' => $id,
                ];
            }
        }

        return new ReportDrillDownResult($rows, null, []);
    }
}
