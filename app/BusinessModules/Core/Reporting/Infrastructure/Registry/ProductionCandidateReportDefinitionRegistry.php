<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Registry;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class ProductionCandidateReportDefinitionRegistry implements CandidateReportDefinitionRegistry
{
    private array $candidates;

    public function __construct()
    {
        $this->candidates = [];
        foreach ([
            'quality_defect_flow' => [
                'formula' => 'quality_defect_flow_v1',
                'permissions' => ['quality-control.defects.view', 'quality-control.reports.export', [], ['quality-control.defects.view']],
                'filters' => ['project_id', 'contractor_id', 'schedule_task_id', 'severity', 'status', 'cohort_from', 'cohort_to', 'period_from', 'period_to'],
                'columns' => ['row_key', 'project_id', 'contractor_id', 'schedule_task_id', 'quality_defect_id', 'cohort_date', 'event_version', 'severity', 'status', 'created', 'reopened', 'closed', 'closing', 'cycle_days', 'due_date', 'evidence_refs'],
                'sorts' => ['cohort_date', 'due_date', 'severity', 'status', 'row_key'],
                'sensitive' => [],
                'audit' => ['evidence_refs'],
            ],
            'safety_incident_actions' => [
                'formula' => 'safety_incident_actions_v1',
                'permissions' => ['safety-management.view', 'safety-management.reports.export', [], ['safety-management.view']],
                'filters' => ['project_id', 'safety_site_id', 'contractor_id', 'subject_type', 'category', 'severity', 'status', 'owner_user_id', 'due_from', 'due_to', 'period_from', 'period_to'],
                'columns' => ['row_key', 'project_id', 'safety_site_id', 'contractor_id', 'subject_type', 'subject_id', 'event_date', 'event_version', 'category', 'severity', 'status', 'owner_user_id', 'due_date', 'created', 'reopened', 'closed', 'closure_verified', 'closure_days', 'evidence_id'],
                'sorts' => ['event_date', 'due_date', 'severity', 'status', 'row_key'],
                'sensitive' => [],
                'audit' => ['evidence_id'],
            ],
            'workforce_admission' => [
                'formula' => 'workforce_admission_v1',
                'permissions' => ['safety-management.view', 'safety-management.reports.export', ['safety-management.medical.view'], ['safety-management.view']],
                'filters' => ['project_id', 'safety_site_id', 'employee_id', 'workforce_assignment_id', 'requirement_code', 'requirement_type', 'status', 'mandatory', 'blocked', 'verified'],
                'columns' => ['row_key', 'snapshot_date', 'project_id', 'safety_site_id', 'workforce_assignment_id', 'employee_id', 'requirement_code', 'requirement_type', 'status', 'mandatory', 'blocked', 'verified', 'valid_until', 'evidence_id', 'medical_details'],
                'sorts' => ['snapshot_date', 'status', 'valid_until', 'employee_id', 'row_key'],
                'sensitive' => ['evidence_id', 'medical_details'],
                'audit' => [],
            ],
        ] as $code => $contract) {
            $columns = array_map(
                static fn (string $id): array => ['id' => $id],
                $contract['columns'],
            );
            $identity = [
                'code' => $code,
                'contract' => 'report_contract_v1',
                'formula' => $contract['formula'],
                'source_schema' => $contract['formula'],
                'renderer' => 'report_renderer_v1',
                'filters' => $contract['filters'],
                'columns' => $columns,
                'sorts' => $contract['sorts'],
                'formats' => ['csv', 'xlsx'],
                'permissions' => $contract['permissions'],
                'snapshot_classification' => ReportSnapshotClassification::OFFICIAL->value,
                'output_classification' => [
                    'data' => ReportDataClassification::STANDARD->value,
                    'sensitive' => $contract['sensitive'],
                    'audit' => $contract['audit'],
                    'provenance_audit' => true,
                ],
                'supports_subscriptions' => true,
            ];
            $definition = new ReportDefinition(
                code: $code,
                definitionHash: new Sha256Hash(hash('sha256', CanonicalJson::encode($identity))),
                contractVersion: 'report_contract_v1',
                formulaVersion: $contract['formula'],
                sourceSchemaVersion: $contract['formula'],
                rendererVersion: 'report_renderer_v1',
                filters: array_map(static fn (string $id): array => ['id' => $id], $contract['filters']),
                columns: $columns,
                sorts: array_map(static fn (string $id): array => ['id' => $id], $contract['sorts']),
                formats: ['csv', 'xlsx'],
                permissionPolicy: new ReportPermissionPolicy(
                    [$contract['permissions'][0]],
                    [$contract['permissions'][1]],
                    $contract['permissions'][2],
                    $contract['permissions'][3],
                ),
                snapshotClassification: ReportSnapshotClassification::OFFICIAL,
                outputClassification: new ReportOutputClassification(
                    ReportDataClassification::STANDARD,
                    $contract['sensitive'],
                    $contract['audit'],
                    false,
                    false,
                    true,
                ),
                publicationReadiness: ReportPublicationReadiness::CANDIDATE,
                supportsSubscriptions: true,
            );
            $this->candidates[$code] = new CandidateReportDefinition($definition);
        }
    }

    public function candidate(string $code): CandidateReportDefinition
    {
        return $this->candidates[$code]
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function candidateCodes(): array
    {
        return array_keys($this->candidates);
    }
}
