<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\PortfolioLiquidityRow;
use InvalidArgumentException;
use ReflectionClass;

final readonly class PortfolioLiquidityCandidateContract
{
    public const CODE = 'portfolio_liquidity';

    public const FORMULA_VERSION = 'budgeting.portfolio-liquidity.v1';

    public const SOURCE_SCHEMA_VERSION = 'portfolio_liquidity_sources_v1';

    public const FORMULA_HASH = 'c74d47950d55c2d8c2f701c05a1c6847b81597375de275c673135c812cbcc09b';

    public const SOURCE_HASH = '5efb77458488c7ad959fa20f86e9ce6ef72f3e500946a3b30e81a4f159273785';

    public function filters(): array
    {
        return [
            ['id' => 'as_of', 'required' => true],
            ['id' => 'horizon_from', 'required' => true],
            ['id' => 'horizon_to', 'required' => true],
            ['id' => 'project_ids', 'required' => false],
            ['id' => 'responsibility_center_ids', 'required' => false],
            ['id' => 'counterparty_ids', 'required' => false],
            ['id' => 'document_ids', 'required' => false],
            ['id' => 'scenarios', 'required' => false],
            ['id' => 'currencies', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'date', 'project', 'scenario', 'currency', 'opening',
            'inflow', 'outflow', 'closing', 'gap', 'quality', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'forecast_date', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'project_name', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'currency', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'scenario', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'opening', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'inflow', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'outflow', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'closing', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'gap', 'direction' => ReportSortDirection::DESC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(PortfolioLiquidityRow::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(BudgetingPortfolioProjectionService::class))) {
            throw new InvalidArgumentException('portfolio_liquidity_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'budgeting'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['budgeting.cfo.view']
            || $definition->permissionPolicy->exportPermissions !== ['budgeting.cash_gap.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('portfolio_liquidity_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('portfolio_liquidity_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('portfolio_liquidity_candidate_source_unreadable');
        }

        return $hash;
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
