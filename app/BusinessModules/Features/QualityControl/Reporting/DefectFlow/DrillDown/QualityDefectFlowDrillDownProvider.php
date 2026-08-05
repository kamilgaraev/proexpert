<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
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
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $cell = $this->cell($request->token, $snapshot);
        $row = QualityDefectFlowRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $cell['row_key'])
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

    private function cell(string $token, ReportSnapshotRef $snapshot): array
    {
        $encoded = explode('.', $token, 2)[0] ?? '';
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload)
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || ! is_string($payload['row_key'] ?? null)
            || ! is_string($payload['column_id'] ?? null)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        return ['row_key' => $payload['row_key'], 'column_id' => $payload['column_id']];
    }
}
