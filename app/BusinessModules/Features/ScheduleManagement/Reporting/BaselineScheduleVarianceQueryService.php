<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\BaselineScheduleSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Models\BaselineScheduleVarianceRecord;
use App\Support\Reporting\ImmutableOwnerProjectionReader;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;

final readonly class BaselineScheduleVarianceQueryService implements ReportRowQuery, ReportDrillDownProvider
{
    private ImmutableOwnerProjectionReader $reader;

    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    public function __construct(?ReportSourceObjectAccessAuthorizer $sourceAccess = null)
    {
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer();
        $this->reader = new ImmutableOwnerProjectionReader(
            BaselineScheduleVarianceRecord::class,
            BaselineScheduleSnapshot::class,
            [
                'variance_days' => 'variance_days',
                'total_float_days' => 'total_float_days',
                'planned_start' => 'planned_start',
                'planned_end' => 'planned_end',
                'task_name' => 'task_name',
                'wbs_code' => 'wbs_code',
                'critical' => 'is_critical',
                'status' => 'status',
            ],
        );
    }

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        return $this->reader->page($context, $snapshot, $sort, $cursor, $limit);
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        return $this->reader->cursor($context, $snapshot, $sort, $chunkSize);
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
        if ($row === null) {
            return new ReportDrillDownResult([], null, []);
        }

        $projectId = (int) $row['project_id'];
        $this->sourceAccess->assertAccessible($context, 'schedule_task', (int) $row['task_id'], $projectId);
        $this->sourceAccess->assertAccessible($context, 'schedule', (int) $row['schedule_id'], $projectId);
        $evidence = [[
            'row_key' => 'schedule_evidence:'.$row['row_key'],
            'schedule_id' => $row['schedule_id'],
            'task_id' => $row['task_id'],
            'baseline_version_id' => $row['baseline_version_id'],
            'evidence_type' => 'schedule_task',
        ]];
        foreach ((array) ($row['dependency_refs'] ?? []) as $dependency) {
            if (!is_array($dependency) || !isset($dependency['dependency_id'])) {
                continue;
            }
            $this->sourceAccess->assertAccessible(
                $context,
                'task_dependency',
                (int) $dependency['dependency_id'],
                $projectId,
            );
            $evidence[] = [
                'row_key' => 'dependency_evidence:'.$row['row_key'].':'.$dependency['dependency_id'],
                'dependency_id' => $dependency['dependency_id'],
                'dependency_type' => $dependency['dependency_type'] ?? null,
                'predecessor_task_id' => $dependency['predecessor_task_id'] ?? null,
                'successor_task_id' => $dependency['successor_task_id'] ?? null,
                'evidence_type' => 'task_dependency',
            ];
        }

        return new ReportDrillDownResult(
            $evidence,
            null,
            [],
        );
    }
}
