<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Support\ScopedReportSourceGuard;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionRow;

final readonly class WorkforceAdmissionDrillDownProvider implements ReportDrillDownProvider
{
    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $rowKey = $this->rowKey($request->token, $snapshot);
        $row = SafetyAdmissionRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $rowKey)
            ->first();
        if (! $row instanceof SafetyAdmissionRow) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }
        ScopedReportSourceGuard::assertAccessible($context, (int) $row->project_id, [
            new ReportScopedResource('workforce_assignment', (int) $row->workforce_assignment_id, (int) $row->project_id),
            new ReportScopedResource('workforce_employee', (int) $row->employee_id, (int) $row->project_id),
            new ReportScopedResource('safety_site', (int) $row->safety_site_id, (int) $row->project_id),
            new ReportScopedResource('workforce_assignment_site', (int) $row->site_assignment_id, (int) $row->project_id),
            new ReportScopedResource('workforce_snapshot_evidence', (int) $row->id, (int) $row->project_id),
        ]);

        $medical = $row->requirement_type === 'medical_exam';
        $canViewMedical = $context->visibility->canViewSensitive;
        $status = (string) $row->status;
        if ($medical && ! $canViewMedical) {
            $status = (bool) $row->blocked
                ? 'blocked'
                : (in_array($status, ['expired', 'missing'], true) ? $status : 'valid');
        }
        $route = match ($row->requirement_type) {
            'training' => 'admin.safety_management.training_records.index',
            'medical_exam' => 'admin.safety_management.medical_exams.index',
            'ppe' => 'admin.safety_management.ppe_issues.index',
            default => 'admin.safety_management.employee_requirements.index',
        };

        $values = [
            'row_key' => (string) $row->row_key,
            'employee_id' => (int) $row->employee_id,
            'requirement_code' => (string) $row->requirement_code,
            'requirement_type' => (string) $row->requirement_type,
            'status' => $status,
            'blocked' => (bool) $row->blocked,
            'valid_until' => $row->valid_until?->toDateString(),
        ];
        if (! $medical) {
            $values['evidence_id'] = $row->evidence_id;
        } elseif ($canViewMedical) {
            $values['evidence_id'] = $row->evidence_id;
            $values['medical_details'] = $row->medical_details;
        }

        $links = [];
        if ($row->evidence_id !== null
            && $row->evidence_version_id !== null
            && $row->evidence_hash !== null
            && (bool) $row->verified) {
            $links[] = new ReportResourceLink(
                'safety_requirement',
                'requirement_'.(int) $row->id,
                $route,
                ['id' => (int) $row->evidence_id],
                $medical && ! $canViewMedical ? 'forbidden' : 'available',
            );
        }

        return new ReportDrillDownResult(
            [$values],
            null,
            $links,
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
