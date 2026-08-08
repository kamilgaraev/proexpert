<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Support\ScopedReportSourceGuard;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowRow;

final readonly class QualityDefectFlowDrillDownProvider implements ReportDrillDownProvider, ReportDrillDownTokenColumns
{
    public function drillDownTokenColumns(): array
    {
        return ['drill' => 'evidence_refs'];
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $row = QualityDefectFlowRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $input->cell->rowKey)
            ->first();
        if (! $row instanceof QualityDefectFlowRow) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        ScopedReportSourceGuard::assertAccessible($context, (int) $row->project_id, [
            new ReportScopedResource('quality_defect', (int) $row->quality_defect_id, (int) $row->project_id),
            ...($row->schedule_task_id === null ? [] : [
                new ReportScopedResource('schedule_task', (int) $row->schedule_task_id, (int) $row->project_id),
                new ReportScopedResource('task', (int) $row->schedule_task_id, (int) $row->project_id),
            ]),
            ...($row->contractor_id === null ? [] : [
                new ReportScopedResource('contractor', (int) $row->contractor_id, (int) $row->project_id),
            ]),
        ]);
        if ($context->visibility->canViewAudit) {
            foreach (($row->evidence_refs ?? []) as $evidence) {
                if (is_array($evidence) && isset($evidence['id'])) {
                    ScopedReportSourceGuard::assertExactAccessible(
                        $context,
                        new ReportScopedResource(
                            ($evidence['type'] ?? null) === 'status_comment' ? 'status_comment' : 'quality_defect_photo',
                            (int) $evidence['id'],
                            (int) $row->project_id,
                        ),
                    );
                }
            }
        }

        return new ReportDrillDownResult(
            rows: [[
                'row_key' => (string) $row->row_key,
                'quality_defect_id' => (int) $row->quality_defect_id,
                'event_version' => (int) $row->event_version,
                'status' => (string) $row->status,
                'cycle_days' => $row->cycle_days,
                'evidence_refs' => $context->visibility->canViewAudit ? ($row->evidence_refs ?? []) : [],
            ]],
            nextCursor: null,
            resourceLinks: [
                new ReportResourceLink(
                    resourceType: 'quality_defect',
                    resourceId: 'defect_'.(int) $row->quality_defect_id,
                    routeName: 'admin.quality_control.defects.show',
                    params: ['id' => (int) $row->quality_defect_id],
                    availability: 'available',
                ),
            ],
        );
    }

}
