<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\CurrencyCode;
use App\Services\ActReport\ActReportAccessService;
use App\Services\ActReport\ActReportFileService;
use App\Services\ActReport\ActReportWorkflowService;
use App\Services\Acting\ActingActWizardService;
use App\Services\Acting\ActingQuantityReservationService;
use App\Services\Acting\ActingQuantityStatus;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionHistoryBoundary;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionMetric;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionUniverseStream;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ApprovedAcceptanceRate;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ProductionAcceptanceFact;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownSource;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\EloquentAcceptedProductionDrillDownSource;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventReducer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventUniverse;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFilterValidator;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFormula;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionHistoryBoundaryResolver;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionQuantity;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionSnapshotMaterializer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ApprovedAcceptanceRateResolver;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceCoverageGapRecorder;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventIdentity;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventRecorder;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceOwnerVersionWriter;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceRecognitionGrain;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceReversalSource;
use App\Services\Contract\ContractPerformanceActService;
use InvalidArgumentException;
use ReflectionClass;

final readonly class AcceptedProductionCandidateContract
{
    public const CODE = 'accepted_production_progress';
    public const FORMULA_VERSION = 'accepted_production.v1';
    public const SOURCE_SCHEMA_VERSION = 'production_acceptance_events_v2';
    public const FORMULA_HASH = '839ea0b2787a0d73872bf5f7a63292437abaae05abb108ae92731abe3264f06b';
    public const SOURCE_HASH = 'f6f4f0259f1836978d5f929b91e26e9866b3d138113a98fbff32d710256c2ca5';

    public function filters(): array
    {
        return [
            ['id' => 'period_from', 'required' => true],
            ['id' => 'period_to', 'required' => true],
            ['id' => 'work_ids', 'required' => false],
            ['id' => 'act_ids', 'required' => false],
            ['id' => 'contractor_ids', 'required' => false],
            ['id' => 'unit_codes', 'required' => false],
            ['id' => 'zones', 'required' => false],
            ['id' => 'statuses', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key',
            'recognized_on',
            'project_id',
            'wbs_code',
            'work_id',
            'performance_act_id',
            'source_line_type',
            'source_line_id',
            'planned_quantity',
            'reported_quantity',
            'accepted_quantity',
            'accepted_plan_variance',
            'reported_accepted_variance',
            'completion_ratio',
            'unit_dimension',
            'unit_code',
            'currency',
            'approved_rate_minor',
            'accepted_amount_minor',
            'event_status',
            'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'recognized_on', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'project_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'work_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'performance_act_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'source_line_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'unit_dimension', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'unit_code', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'accepted_quantity', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'accepted_amount_minor', 'direction' => ReportSortDirection::DESC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classesHash([
            AcceptedProductionFormula::class,
            AcceptedProductionQuantity::class,
            ProductionAcceptanceFact::class,
            AcceptedProductionMetric::class,
            AcceptedProductionEventReducer::class,
            CurrencyCode::class,
        ])) || ! hash_equals(self::SOURCE_HASH, self::classesHash([
            AcceptedProductionHistoryBoundary::class,
            AcceptedProductionHistoryBoundaryResolver::class,
            AcceptedProductionFilterValidator::class,
            AcceptedProductionDrillDownProvider::class,
            AcceptedProductionDrillDownSource::class,
            EloquentAcceptedProductionDrillDownSource::class,
            ActReportAccessService::class,
            ActReportFileService::class,
            ActReportWorkflowService::class,
            ActingActWizardService::class,
            ActingQuantityReservationService::class,
            ActingQuantityStatus::class,
            ContractPerformanceActService::class,
            AcceptedProductionEventUniverse::class,
            AcceptedProductionUniverseStream::class,
            AcceptedProductionEventReducer::class,
            AcceptedProductionLineageFilter::class,
            AcceptedProductionSnapshotMaterializer::class,
            AcceptedProductionQuantity::class,
            ApprovedAcceptanceRate::class,
            ApprovedAcceptanceRateResolver::class,
            ProductionAcceptanceCoverageGapRecorder::class,
            ProductionAcceptanceEventIdentity::class,
            ProductionAcceptanceEventRecorder::class,
            ProductionAcceptanceOwnerVersionWriter::class,
            ProductionAcceptanceRecognitionGrain::class,
            ProductionAcceptanceReversalSource::class,
        ]))) {
            throw new InvalidArgumentException('accepted_production_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'contract-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['reports.production_progress.view']
            || $definition->permissionPolicy->exportPermissions !== ['reports.production_progress.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['budgeting.wip_forecast.view_sensitive_costs']
            || $definition->permissionPolicy->auditPermissions !== []
            || $definition->outputClassification->defaultClassification !== ReportDataClassification::STANDARD
            || $definition->outputClassification->sensitiveColumnIds !== [
                'accepted_amount_minor',
                'approved_rate_minor',
            ]
            || $definition->outputClassification->auditColumnIds !== []
            || $definition->outputClassification->totalsSensitive
            || $definition->outputClassification->totalsAudit
            || $definition->outputClassification->provenanceAudit) {
            throw new InvalidArgumentException('accepted_production_candidate_definition_invalid');
        }
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('accepted_production_candidate_source_unreadable');
            }
        }

        return hash_final($hash);
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR),
            $items,
        );
    }
}
