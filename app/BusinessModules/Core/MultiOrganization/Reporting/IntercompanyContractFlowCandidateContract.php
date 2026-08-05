<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointBatch;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\IntercompanyFlowAggregate;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\IntercompanyFlowMetricRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationCheckpointSourceAssembler;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowFormula;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\CurrencyCode;
use InvalidArgumentException;
use ReflectionClass;

final readonly class IntercompanyContractFlowCandidateContract
{
    public const CODE = 'intercompany_contract_flows';
    public const FORMULA_VERSION = 'intercompany_contract_flow.v1';
    public const SOURCE_SCHEMA_VERSION = 'holding_allocation_facts.v2';
    public const FORMULA_HASH = '335648ea8becc17ed3d2543deacb02a7c218c8e5546647fc9e94cd3497e57282';
    public const SOURCE_HASH = '428f859e7a352ad3c86d62ed2852921c6facbae38348b79807616a8955fea7a3';

    public function filters(): array
    {
        return [
            ['id' => 'project_ids', 'required' => false],
            ['id' => 'organization_ids', 'required' => false],
            ['id' => 'counterparty_ids', 'required' => false],
            ['id' => 'work_type_categories', 'required' => false],
            ['id' => 'contract_ids', 'required' => false],
            ['id' => 'currencies', 'required' => false],
            ['id' => 'period_from', 'required' => false],
            ['id' => 'period_to', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key',
            'project_id',
            'allocation_id',
            'counterparty_organization_id',
            'currency',
            'period_start',
            'internal_minor',
            'external_minor',
            'unclassified_minor',
            'total_minor',
            'internal_share',
            'external_share',
            'unclassified_share',
            'linked_spread_minor',
            'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'period_start', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'project_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'counterparty_organization_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'currency', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'total_minor', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'linked_spread_minor', 'direction' => ReportSortDirection::DESC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classesHash([
            IntercompanyContractFlowFormula::class,
            IntercompanyFlowMetricRow::class,
            IntercompanyFlowAggregate::class,
            CurrencyCode::class,
        ])) || ! hash_equals(self::SOURCE_HASH, self::classesHash([
            HoldingAllocationCheckpointSourceAssembler::class,
            HoldingAllocationCheckpointSource::class,
            HoldingAllocationCheckpointBatch::class,
            HoldingAllocationFactProjector::class,
            IntercompanyContractFlowSnapshotMaterializer::class,
        ]))) {
            throw new InvalidArgumentException('intercompany_contract_flow_candidate_contract_drift');
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
            || $definition->permissionPolicy->viewPermissions !== ['multi-organization.reports.financial']
            || $definition->permissionPolicy->exportPermissions !== ['multi-organization.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('intercompany_contract_flow_candidate_definition_invalid');
        }
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('intercompany_contract_flow_candidate_source_unreadable');
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
