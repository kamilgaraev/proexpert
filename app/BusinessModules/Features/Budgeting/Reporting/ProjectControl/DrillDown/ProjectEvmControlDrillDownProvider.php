<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DrillDown;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlSnapshot;
use App\Support\Reporting\ImmutableOwnerProjectionReader;

final readonly class ProjectEvmControlDrillDownProvider implements ReportDrillDownProvider
{
    private ImmutableOwnerProjectionReader $reader;

    public function __construct()
    {
        $this->reader = new ImmutableOwnerProjectionReader(
            ProjectControlRow::class,
            ProjectControlSnapshot::class,
            ['wbs_code' => 'wbs_code'],
            ['ac_minor', 'approved_etc_minor', 'cv_minor', 'cpi', 'eac_minor', 'vac_minor', 'tcpi'],
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
        if ($row === null) {
            return new ReportDrillDownResult([], null, []);
        }

        $evidence = [];
        foreach ((array) ($row['source_refs'] ?? []) as $index => $sourceRef) {
            if (!is_array($sourceRef) || !isset($sourceRef['type'], $sourceRef['id'])) {
                continue;
            }
            $type = (string) $sourceRef['type'];
            if (!$context->visibility->canViewSensitive
                && in_array($type, ['actual_cost', 'wip_accrual', 'approved_etc'], true)
            ) {
                continue;
            }
            $evidence[] = [
                'row_key' => 'project_control_evidence:'.$row['row_key'].':'.$index,
                'evidence_type' => $type,
                'evidence_id' => $sourceRef['id'],
                'project_id' => $row['project_id'],
                'task_id' => $row['task_id'],
            ];
        }
        if ($evidence === []) {
            $evidence[] = [
                'row_key' => 'project_control_evidence:'.$row['row_key'],
                'evidence_type' => 'schedule_task',
                'evidence_id' => $row['task_id'],
                'project_id' => $row['project_id'],
                'task_id' => $row['task_id'],
            ];
        }

        return new ReportDrillDownResult($evidence, null, []);
    }
}
