<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\WorkforceCapacityFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use InvalidArgumentException;
use ReflectionClass;

final readonly class WorkforceCapacityCandidateContract
{
    public const CODE = 'workforce_capacity';

    public const FORMULA_HASH = 'ec5f0c7c6f0c55c5cb97ae69587c3ca695da41746bcd51b11b1f477e961a18b3';

    public const SOURCE_HASH = '11adad674c4225207c8c8aa5fa27e6b059b1fa27150d0c098afa20580602303b';

    public function filters(): array
    {
        return [
            ['id' => 'organization_id', 'required' => true],
            ['id' => 'month_from', 'required' => true],
            ['id' => 'month_to', 'required' => true],
            ['id' => 'project_ids', 'required' => false],
            ['id' => 'department_ids', 'required' => false],
            ['id' => 'position_ids', 'required' => false],
            ['id' => 'employment_types', 'required' => false],
            ['id' => 'rate_types', 'required' => false],
            ['id' => 'currencies', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'month', 'department_name', 'position_name', 'project_name',
            'employment_type', 'rate_as_of', 'planned_fte', 'assigned_fte', 'vacancy_fte',
            'overstaffing_fte', 'vacancy_percent', 'planned_capacity_hours', 'capacity_hours',
            'rate_type', 'rate', 'currency', 'period_cost_run_rate', 'quality_warnings', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'direction' => $id === 'month' ? ReportSortDirection::DESC->value : ReportSortDirection::ASC->value,
        ], DatabaseWorkforceReportAdapter::SORTS[self::CODE]);
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(WorkforceCapacityFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(DatabaseWorkforceReportAdapter::class))) {
            throw new InvalidArgumentException('workforce_capacity_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->formulaVersion !== DatabaseWorkforceReportAdapter::CAPACITY_FORMULA
            || $definition->sourceSchemaVersion !== DatabaseWorkforceReportAdapter::SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['workforce.view']
            || $definition->permissionPolicy->exportPermissions !== ['workforce.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['workforce.audit.view']
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('workforce_capacity_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('workforce_capacity_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('workforce_capacity_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR), $items);
    }
}
