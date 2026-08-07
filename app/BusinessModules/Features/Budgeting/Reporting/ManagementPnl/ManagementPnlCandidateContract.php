<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ManagementPnlCandidateContract
{
    public const CODE = 'management_pnl';

    public const FORMULA_VERSION = 'management-pnl.v1';

    public const SOURCE_SCHEMA_VERSION = 'management-pnl-components.v1';

    public const FORMULA_HASH = '859919649bf9f9cc06f64e762f7eb0507e077f89b8092a2c2811b6d9f7a8b8fe';

    public const SOURCE_HASH = 'a1951e63dcd64d3d257d1fb9cdcbe896ee22b917e8f47335c1281731528d396f';

    public function definition(): ReportDefinition
    {
        $this->assertRuntimeMatches();

        return (new ReportDefinitionFactory)->fromManifest($this->document());
    }

    public function filters(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'required' => in_array($id, ['period_from', 'period_to', 'scenarios'], true),
        ], [
            'period_from', 'period_to', 'project_ids', 'responsibility_center_ids',
            'budget_article_ids', 'currencies', 'scenarios',
        ]);
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'period', 'scenario', 'organization_id', 'project_id',
            'responsibility_center_id', 'budget_article_id', 'currency', 'revenue',
            'direct_cost', 'gross_margin', 'operating_expense', 'operating_result',
            'gross_margin_percent', 'policy_version', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'period', 'direction' => 'asc'],
            ['id' => 'project_name', 'direction' => 'asc'],
            ['id' => 'article_name', 'direction' => 'asc'],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function document(string $publication = 'blocked'): array
    {
        if (! in_array($publication, ['blocked', 'published'], true)) {
            throw new InvalidArgumentException('management_pnl_publication_state_invalid');
        }

        return [
            'code' => self::CODE,
            'title_key' => 'reports.catalog.management_pnl',
            'catalog_group' => 'finance',
            'category' => 'finance',
            'grain' => 'organization_article_period_scenario',
            'wave' => 1,
            'source_module' => 'budgeting',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->filters(),
            'columns' => $this->columns(),
            'sorts' => $this->sorts(),
            'formats' => $this->formats(),
            'versions' => ['contract' => '1.0.0', 'formula' => self::FORMULA_VERSION, 'source_schema' => self::SOURCE_SCHEMA_VERSION, 'renderer' => '1.0.0'],
            'semantic_fingerprints' => ['formula' => self::FORMULA_HASH, 'source' => self::SOURCE_HASH],
            'permissions' => ['view' => ['budgeting.management_pnl.view'], 'export' => ['budgeting.management_pnl.export'], 'sensitive' => [], 'audit' => []],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => $publication],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'budgeting'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || array_column($definition->filters, 'id') !== array_column($this->filters(), 'id')
            || array_column($definition->columns, 'id') !== array_column($this->columns(), 'id')
            || $definition->permissionPolicy->viewPermissions !== ['budgeting.management_pnl.view']
            || $definition->permissionPolicy->exportPermissions !== ['budgeting.management_pnl.export']) {
            throw new InvalidArgumentException('management_pnl_candidate_definition_invalid');
        }
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, $this->classHash(ManagementAccountingPolicy::class))
            || ! hash_equals(self::SOURCE_HASH, $this->classHash(ManagementPnlProjectionService::class))) {
            throw new InvalidArgumentException('management_pnl_candidate_contract_drift');
        }
    }

    private function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('management_pnl_candidate_source_unreadable');
        }

        return $hash;
    }
}
