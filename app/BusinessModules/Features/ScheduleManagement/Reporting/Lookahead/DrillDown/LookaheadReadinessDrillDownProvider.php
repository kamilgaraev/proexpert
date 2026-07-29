<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessRow;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessSnapshot;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;

final readonly class LookaheadReadinessDrillDownProvider implements ReportDrillDownProvider
{
    private ImmutableOwnerProjectionReader $reader;

    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    public function __construct(?ReportSourceObjectAccessAuthorizer $sourceAccess = null)
    {
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer();
        $this->reader = new ImmutableOwnerProjectionReader(
            LookaheadReadinessRow::class,
            LookaheadReadinessSnapshot::class,
            ['planned_start_date' => 'planned_start_date'],
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
            $projectId = (int) $row['project_id'];
            $this->sourceAccess->assertAccessible(
                $context,
                'schedule_task',
                (int) $row['task_id'],
                $projectId,
            );
            $rows[] = [
                'row_key' => 'lookahead_task:'.$row['row_key'],
                'project_id' => $row['project_id'],
                'schedule_id' => $row['schedule_id'],
                'task_id' => $row['task_id'],
                'blocking_constraint_ids' => $row['blocking_constraint_ids'],
            ];
            if (($row['constraint_id'] ?? null) !== null) {
                $this->sourceAccess->assertAccessible(
                    $context,
                    'work_constraint',
                    (int) $row['constraint_id'],
                    $projectId,
                );
                $rows[] = [
                    'row_key' => 'work_constraint:'.$row['constraint_id'],
                    'project_id' => $row['project_id'],
                    'constraint_id' => $row['constraint_id'],
                    'constraint_type' => $row['constraint_type'],
                    'constraint_status' => $row['constraint_status'],
                ];
            }
            if (($row['linked_resource_id'] ?? null) !== null) {
                $this->sourceAccess->assertAccessible(
                    $context,
                    (string) $row['linked_resource_type'],
                    (int) $row['linked_resource_id'],
                    $projectId,
                );
                $rows[] = [
                    'row_key' => $row['linked_resource_type'].':'.$row['linked_resource_id'],
                    'project_id' => $row['project_id'],
                    'resource_type' => $row['linked_resource_type'],
                    'resource_id' => $row['linked_resource_id'],
                ];
            }
        }

        return new ReportDrillDownResult($rows, null, []);
    }
}
