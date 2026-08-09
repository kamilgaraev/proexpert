<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointBatch;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingContractDimensionSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingPerformanceMetricRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingPerformanceProjectionCoverage;
use App\BusinessModules\Core\MultiOrganization\Reporting\Listeners\ProjectHoldingAllocationFacts;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentEventCoverageCheckpoint;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\AcceptedWorkHoldingFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAcceptedWorkLifecycleRecorder;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationCheckpointSourceAssembler;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationContextResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingContractDimensionResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingHierarchyResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPaymentEventFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceFormula;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceImmutableEventSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceImmutableProjectionSynchronizer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceProjectionCoverageInspector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceSnapshotMaterializer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingReportingSourceCoverage;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\CurrencyCode;
use InvalidArgumentException;
use ReflectionClass;

final readonly class HoldingPerformanceCandidateContract
{
    public const CODE = 'holding_performance';

    public const FORMULA_VERSION = 'holding_performance.v1';

    public const SOURCE_SCHEMA_VERSION = 'holding_allocation_facts.v2';

    public const FORMULA_HASH = '59c4e2d23349656ae8948679fcea4dc59da5be20792b865afb17da2b9a667f20';

    public const SOURCE_HASH = '91af250419c8cfc877973d00116ab698eb3ae39831d65e53fcf34fe5ff527d6b';

    public function filters(): array
    {
        return [
            ['id' => 'organization_ids', 'required' => false],
            ['id' => 'project_ids', 'required' => false],
            ['id' => 'contractor_ids', 'required' => false],
            ['id' => 'contract_statuses', 'required' => false],
            ['id' => 'currencies', 'required' => false],
            ['id' => 'period_from', 'required' => false],
            ['id' => 'period_to', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key',
            'organization_id',
            'contributor_organization_id',
            'project_id',
            'period_start',
            'currency',
            'monetary_basis',
            'contracted_minor',
            'accepted_accrual_minor',
            'cash_minor',
            'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'period_start', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'contributor_organization_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'project_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'currency', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'monetary_basis', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'contracted_minor', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'accepted_accrual_minor', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'cash_minor', 'direction' => ReportSortDirection::DESC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (self::SOURCE_SCHEMA_VERSION !== HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION
            || ! hash_equals(self::FORMULA_HASH, self::classesHash([
                HoldingPerformanceFormula::class,
                HoldingAllocationFact::class,
                HoldingPerformanceMetricRow::class,
                CurrencyCode::class,
            ])) || ! hash_equals(self::SOURCE_HASH, self::classesHash([
                HoldingAllocationCheckpointSourceAssembler::class,
                HoldingAllocationCheckpointSource::class,
                HoldingAllocationCheckpointBatch::class,
                HoldingAllocationFactProjector::class,
                HoldingAllocationFactVersion::class,
                HoldingHierarchyResolver::class,
                HoldingContractDimensionSnapshot::class,
                HoldingContractDimensionResolver::class,
                HoldingAllocationContextResolver::class,
                HoldingReportingSourceCoverage::class,
                HoldingAcceptedWorkEventVersion::class,
                HoldingPaymentEventCoverageCheckpoint::class,
                HoldingPaymentTransactionEventVersion::class,
                AcceptedWorkHoldingFactProducer::class,
                HoldingAcceptedWorkLifecycleRecorder::class,
                HoldingPaymentEventFactProducer::class,
                ProjectHoldingAllocationFacts::class,
                PaymentTransactionStatus::class,
                HoldingPerformanceImmutableEventSource::class,
                HoldingPerformanceImmutableProjectionSynchronizer::class,
                HoldingPerformanceProjectionCoverageInspector::class,
                HoldingPerformanceProjectionCoverage::class,
                HoldingPerformanceSnapshotMaterializer::class,
            ]))) {
            throw new InvalidArgumentException('holding_performance_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'multi-organization'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['multi-organization.reports.kpi']
            || $definition->permissionPolicy->exportPermissions !== ['multi-organization.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('holding_performance_candidate_definition_invalid');
        }
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('holding_performance_candidate_source_unreadable');
            }
        }

        return hash_final($hash);
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(
                CanonicalJson::encode($item),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
            $items,
        );
    }
}
