<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown;

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
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentRow;

final readonly class SafetyIncidentDrillDownProvider implements ReportDrillDownProvider, ReportDrillDownTokenColumns
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
        $rowKey = $this->rowKey($request->token, $snapshot);
        $row = SafetyIncidentRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $rowKey)
            ->first();
        if (! $row instanceof SafetyIncidentRow) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        $resource = match ($row->subject_type) {
            'incident' => ['safety_incident', 'admin.safety_management.incidents.index'],
            'violation' => ['safety_violation', 'admin.safety_management.violations.index'],
            default => ['safety_corrective_action', 'admin.safety_management.corrective_actions.index'],
        };
        ScopedReportSourceGuard::assertAccessible($context, (int) $row->project_id, [
            new ReportScopedResource($resource[0], (int) $row->subject_id, (int) $row->project_id),
            ...($row->safety_site_id === null ? [] : [
                new ReportScopedResource('safety_site', (int) $row->safety_site_id, (int) $row->project_id),
            ]),
            ...($row->contractor_id === null ? [] : [
                new ReportScopedResource('contractor', (int) $row->contractor_id, (int) $row->project_id),
            ]),
        ]);
        if ($context->visibility->canViewAudit && $row->evidence_id !== null) {
            ScopedReportSourceGuard::assertExactAccessible(
                $context,
                new ReportScopedResource((string) $row->evidence_type, (int) $row->evidence_id, (int) $row->project_id),
            );
        }

        return new ReportDrillDownResult(
            [[
                'row_key' => (string) $row->row_key,
                'subject_type' => (string) $row->subject_type,
                'subject_id' => (int) $row->subject_id,
                'status' => (string) $row->status,
                'closure_verified' => (bool) $row->closure_verified,
                'evidence_id' => $context->visibility->canViewAudit ? $row->evidence_id : null,
            ]],
            null,
            [new ReportResourceLink(
                $resource[0],
                $resource[0].'_'.(int) $row->subject_id,
                $resource[1],
                ['id' => (int) $row->subject_id],
                'available',
            )],
        );
    }

    private function rowKey(string $token, ReportSnapshotRef $snapshot): string
    {
        $encoded = explode('.', $token, 2)[0] ?? '';
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload)
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || ! is_string($payload['row_key'] ?? null)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        return $payload['row_key'];
    }
}
