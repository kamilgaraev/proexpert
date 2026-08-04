<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\TimeTracking\Reporting\Formulas\ProjectLaborCostFormula;
use App\BusinessModules\Features\TimeTracking\Reporting\Infrastructure\DatabaseProjectLaborCostAdapter;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ProjectLaborCostCandidateContract
{
    public const CODE = 'project_labor_cost';

    public const FORMULA_HASH = 'a03b8a1d007970b82b1f2dbb5a6cb103ef03eef2d73076b683c85bc57a1e0af5';

    public const SOURCE_HASH = '1a467acd7d121686b2321049ebdeefd3835f069ba35d48953c10296ba686b35a';

    /** @return list<array{id: string, required: bool}> */
    public function filters(): array
    {
        return [
            ['id' => 'organization_id', 'required' => true],
            ['id' => 'project_id', 'required' => true],
            ['id' => 'period_from', 'required' => true],
            ['id' => 'period_to', 'required' => true],
            ['id' => 'employee_ids', 'required' => false],
            ['id' => 'contractor_ids', 'required' => false],
            ['id' => 'task_ids', 'required' => false],
            ['id' => 'work_type_ids', 'required' => false],
            ['id' => 'billable', 'required' => false],
            ['id' => 'statuses', 'required' => false],
        ];
    }

    /** @return list<array{id: string}> */
    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'work_date', 'employee_name', 'project_name', 'contractor_name', 'task_name',
            'work_type_name', 'planned_hours', 'hours', 'billable_hours', 'billable_percent',
            'accepted_units', 'accepted_unit', 'rate', 'cost', 'currency', 'variance',
            'cost_per_accepted_unit', 'quality_warnings', 'drill',
        ]);
    }

    /** @return list<array{id: string, direction: string}> */
    public function sorts(): array
    {
        return array_map(
            static fn (string $id): array => [
                'id' => $id,
                'direction' => $id === 'work_date'
                    ? ReportSortDirection::DESC->value
                    : ReportSortDirection::ASC->value,
            ],
            DatabaseProjectLaborCostAdapter::SORTS,
        );
    }

    /** @return list<string> */
    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(ProjectLaborCostFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(DatabaseProjectLaborCostAdapter::class))) {
            throw new InvalidArgumentException('project_labor_cost_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->formulaVersion !== DatabaseProjectLaborCostAdapter::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== DatabaseProjectLaborCostAdapter::SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['time_tracking.view']
            || $definition->permissionPolicy->exportPermissions !== ['time_tracking.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['time_tracking.cost.view']
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('project_labor_cost_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        $allowed = array_column($this->sorts(), 'id');
        if (! in_array($sort->field, $allowed, true)) {
            throw new InvalidArgumentException('project_labor_cost_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('project_labor_cost_candidate_source_unreadable');
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
