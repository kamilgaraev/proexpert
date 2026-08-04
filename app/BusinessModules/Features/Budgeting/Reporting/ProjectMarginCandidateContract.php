<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginSourceSnapshotRequest;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginCalculator;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotMaterializer;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ProjectMarginCandidateContract
{
    public const CODE = 'project_margin';

    public const FORMULA_VERSION = 'margin-v1';

    public const FORMULA_HASH = '9daf19a225a89d3990becd0654b5965eebfb79306f0acf31c0aba1fcd849ccb4';

    public const SOURCE_HASH = 'a887a07672f66a2d382091b1468f6efc339bbec4d62cda88fabfe9a1007fbc45';

    public function __construct(
        public string $formulaVersion = self::FORMULA_VERSION,
        public string $formulaHash = self::FORMULA_HASH,
        public string $sourceSchemaVersion = ProjectMarginSourceSnapshotMaterializer::SCHEMA_VERSION,
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
            ['id' => 'contract_id', 'required' => false],
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
            'actual',
            'currency',
            'drill',
            'forecast',
            'group',
            'plan',
            'problem_flags',
            'quality_status',
            'risk_flags',
            'row_key',
            'source_rows_count',
            'source_types',
            'variance',
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
        return ProjectMarginSourceSnapshotMaterializer::DRILL_COLUMN_ID;
    }

    public function assertRuntimeMatches(): void
    {
        if ($this->formulaVersion !== self::FORMULA_VERSION
            || $this->sourceSchemaVersion !== ProjectMarginSourceSnapshotMaterializer::SCHEMA_VERSION
            || ! hash_equals($this->formulaHash, self::classHash(ProjectMarginCalculator::class))
            || ! hash_equals($this->sourceHash, self::classHash(ProjectMarginSourceSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('project_margin_candidate_contract_drift');
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
            || $definition->permissionPolicy->viewPermissions !== ['budgeting.project_margin.view']
            || $definition->permissionPolicy->exportPermissions !== ['budgeting.project_margin.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('project_margin_candidate_definition_invalid');
        }
    }

    public function assertSnapshotRequest(ReportScope $scope, array $filters, BudgetingReportSourceClose $close): void
    {
        $allowed = array_column($this->filters(), 'id');
        unset($allowed[array_search('close_id', $allowed, true)]);
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw new InvalidArgumentException('project_margin_candidate_filters_invalid');
        }

        foreach ($this->filters() as $filter) {
            if ($filter['required'] && $filter['id'] !== 'close_id' && ! array_key_exists($filter['id'], $filters)) {
                throw new InvalidArgumentException('project_margin_candidate_filters_invalid');
            }
        }

        if ($close->reportCode !== self::CODE || $close->formulaVersion !== $this->formulaVersion) {
            throw new InvalidArgumentException('project_margin_candidate_formula_version_invalid');
        }

        $groupBy = $filters['group_by'] ?? null;
        if (! is_array($groupBy)
            || ! array_is_list($groupBy)
            || $groupBy === []
            || count(array_filter($groupBy, 'is_string')) !== count($groupBy)
            || $groupBy !== array_values(array_unique($groupBy))
            || array_diff($groupBy, ProjectMarginReportFilters::ALLOWED_GROUP_BY) !== []
            || ! in_array(ProjectMarginReportFilters::GROUP_CURRENCY, $groupBy, true)) {
            throw new InvalidArgumentException('project_margin_candidate_grouping_invalid');
        }

        new ProjectMarginSourceSnapshotRequest(
            $scope,
            $filters,
            $close->closeId,
            $close->identity,
            new DateTimeImmutable('2026-01-31T00:00:00+00:00'),
            null,
        );
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if ($sort->field !== 'row_key' || $sort->direction !== ReportSortDirection::ASC) {
            throw new InvalidArgumentException('project_margin_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('project_margin_candidate_source_unreadable');
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
