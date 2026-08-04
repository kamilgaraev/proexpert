<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceSnapshotRequest;
use App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class BudgetPlanFactCandidateContract
{
    public const CODE = 'budget_plan_fact';

    public const FORMULA_VERSION = '1.0.0';

    public const FORMULA_HASH = 'be8618c2f770e98c295dca6236e421d762d914add6e119a738d9b73630450c90';

    public const SOURCE_HASH = '4fbed53a23a895fb78bc0710e6771a28ca8898f4d42bc648d8f274832c8445a7';

    public function __construct(
        public string $formulaVersion = self::FORMULA_VERSION,
        public string $formulaHash = self::FORMULA_HASH,
        public string $sourceSchemaVersion = PlanFactSourceSnapshotMaterializer::SCHEMA_VERSION,
        public string $sourceHash = self::SOURCE_HASH,
    ) {}

    /** @return list<array{id: string, required: bool}> */
    public function filters(): array
    {
        return [
            ['id' => 'close_id', 'required' => true],
            ['id' => 'organization_id', 'required' => true],
            ['id' => 'period_start', 'required' => true],
            ['id' => 'period_end', 'required' => true],
            ['id' => 'scenario_uuid', 'required' => true],
            ['id' => 'budget_version_uuid', 'required' => true],
            ['id' => 'project_id', 'required' => false],
            ['id' => 'responsibility_center_id', 'required' => false],
            ['id' => 'budget_article_id', 'required' => false],
            ['id' => 'counterparty_id', 'required' => false],
            ['id' => 'currency', 'required' => false],
            ['id' => 'group_by', 'required' => true],
        ];
    }

    /** @return list<array{id: string}> */
    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'actual_amount',
            'committed_amount',
            'currency',
            'drill',
            'forecast_amount',
            'group',
            'plan_amount',
            'risk_level',
            'row_key',
            'variance_amount',
            'variance_percent',
        ]);
    }

    /** @return list<array{id: string, direction: string}> */
    public function sorts(): array
    {
        return [['id' => 'row_key', 'direction' => ReportSortDirection::ASC->value]];
    }

    /** @return list<string> */
    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function drillColumnId(): string
    {
        return PlanFactSourceSnapshotMaterializer::DRILL_COLUMN_ID;
    }

    public function assertRuntimeMatches(): void
    {
        if ($this->formulaVersion !== self::FORMULA_VERSION
            || $this->sourceSchemaVersion !== PlanFactSourceSnapshotMaterializer::SCHEMA_VERSION
            || ! hash_equals($this->formulaHash, self::classHash(PlanFactCalculator::class))
            || ! hash_equals($this->sourceHash, self::classHash(PlanFactSourceSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->formulaVersion !== $this->formulaVersion
            || $definition->sourceSchemaVersion !== $this->sourceSchemaVersion
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['budgeting.plan_fact.view']
            || $definition->permissionPolicy->exportPermissions !== ['budgeting.plan_fact.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_definition_invalid');
        }
    }

    /** @param array<string, mixed> $definition */
    public function assertCandidateManifestDefinition(array $definition): void
    {
        $versions = $definition['versions'] ?? null;
        $permissions = $definition['permissions'] ?? null;
        if (! is_array($versions) || array_is_list($versions)
            || ! is_array($permissions) || array_is_list($permissions)
            || ($definition['code'] ?? null) !== self::CODE
            || ($versions['formula'] ?? null) !== $this->formulaVersion
            || ($versions['source_schema'] ?? null) !== $this->sourceSchemaVersion
            || ($definition['filters'] ?? null) !== self::canonicalItems($this->filters())
            || ($definition['columns'] ?? null) !== self::canonicalItems($this->columns())
            || ($definition['sorts'] ?? null) !== self::canonicalItems($this->sorts())
            || ($definition['formats'] ?? null) !== $this->formats()
            || ($permissions['view'] ?? null) !== ['budgeting.plan_fact.view']
            || ($permissions['export'] ?? null) !== ['budgeting.plan_fact.export']
            || ($permissions['sensitive'] ?? null) !== []
            || ($permissions['audit'] ?? null) !== []) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_definition_invalid');
        }

        $readiness = $definition['readiness'] ?? null;
        if (! is_array($readiness) || array_is_list($readiness)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_definition_invalid');
        }
        ksort($readiness, SORT_STRING);
        if ($readiness !== [
            'delivery' => 'verified',
            'formula' => 'ready',
            'publication' => 'candidate',
            'source' => 'ready',
        ]) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_definition_invalid');
        }
    }

    public function assertSnapshotRequest(
        ReportScope $scope,
        array $filters,
        BudgetingReportSourceClose $close,
    ): void {
        $allowed = array_column($this->filters(), 'id');
        unset($allowed[array_search('close_id', $allowed, true)]);
        $unexpected = array_diff(array_keys($filters), $allowed);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_filters_invalid');
        }

        foreach ($this->filters() as $filter) {
            if ($filter['required'] && $filter['id'] !== 'close_id' && ! array_key_exists($filter['id'], $filters)) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_filters_invalid');
            }
        }

        if ($close->reportCode !== self::CODE || $close->formulaVersion !== $this->formulaVersion) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_formula_version_invalid');
        }

        if (($filters['group_by'] ?? null) !== PlanFactReportFilters::DEFAULT_GROUP_BY) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_grouping_invalid');
        }

        new PlanFactSourceSnapshotRequest(
            $scope,
            $filters,
            $close->closeId,
            $close->identity,
            new \DateTimeImmutable('2026-01-31T00:00:00+00:00'),
            null,
        );
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if ($sort->field !== 'row_key' || $sort->direction !== ReportSortDirection::ASC) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_sort_invalid');
        }
    }

    /** @param list<array<string, mixed>> $rows @param list<array<string, mixed>> $drills */
    public function assertRowsAndDrills(array $rows, array $drills): void
    {
        $columns = array_fill_keys(array_column($this->columns(), 'id'), true);
        $rowKeys = [];
        foreach ($rows as $row) {
            if (! is_array($row)
                || ! is_string($row['row_key'] ?? null)
                || $row['row_key'] === ''
                || array_diff(array_keys($row), array_keys($columns)) !== []
                || array_diff(array_keys($columns), array_keys($row)) !== []
                || isset($rowKeys[$row['row_key']])) {
                throw new InvalidArgumentException('budget_plan_fact_candidate_row_invalid');
            }
            $rowKeys[$row['row_key']] = true;
        }

        foreach ($drills as $drill) {
            if (! is_array($drill)
                || ! isset($rowKeys[$drill['row_key'] ?? ''])
                || ($drill['column_id'] ?? null) !== $this->drillColumnId()
                || ! is_string($drill['source_ref'] ?? null)
                || $drill['source_ref'] === '') {
                throw new InvalidArgumentException('budget_plan_fact_candidate_drill_invalid');
            }
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('budget_plan_fact_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR),
            $items,
        );
    }
}
