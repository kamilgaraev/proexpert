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
            'quality_defect_flow' => ['formula' => 'quality_defect_flow_v1', 'sensitive' => []],
            'safety_incident_actions' => ['formula' => 'safety_incident_actions_v1', 'sensitive' => []],
            'workforce_admission' => ['formula' => 'workforce_admission_v1', 'sensitive' => ['evidence_id', 'medical_details']],
        ] as $code => $contract) {
            $columns = array_map(
                static fn (string $id): array => ['id' => $id],
                $code === 'workforce_admission'
                    ? ['row_key', 'project_id', 'safety_site_id', 'employee_id', 'status', 'evidence_id', 'medical_details']
                    : ['row_key', 'project_id', 'status'],
            );
            $identity = [
                'code' => $code,
                'contract' => 'report_contract_v1',
                'formula' => $contract['formula'],
                'columns' => $columns,
            ];
            $definition = new ReportDefinition(
                code: $code,
                definitionHash: new Sha256Hash(hash('sha256', CanonicalJson::encode($identity))),
                contractVersion: 'report_contract_v1',
                formulaVersion: $contract['formula'],
                sourceSchemaVersion: $contract['formula'],
                rendererVersion: 'report_renderer_v1',
                filters: [['id' => 'project_id'], ['id' => 'period_from'], ['id' => 'period_to']],
                columns: $columns,
                sorts: [['id' => 'row_key']],
                formats: ['csv', 'xlsx', 'pdf'],
                permissionPolicy: new ReportPermissionPolicy(
                    ['reports.view'],
                    ['reports.export'],
                    $contract['sensitive'] === [] ? [] : ['safety-management.view-sensitive'],
                    ['reports.audit'],
                ),
                snapshotClassification: ReportSnapshotClassification::OFFICIAL,
                outputClassification: new ReportOutputClassification(
                    ReportDataClassification::STANDARD,
                    $contract['sensitive'],
                    [],
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
