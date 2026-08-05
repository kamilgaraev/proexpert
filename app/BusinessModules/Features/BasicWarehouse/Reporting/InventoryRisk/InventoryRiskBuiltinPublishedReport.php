<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class InventoryRiskBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 18;

    public function __construct(private InventoryRiskCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();
        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);

        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(
            InventoryRiskCandidateContract::CODE,
            'reports.catalog.inventory_risk',
            ReportCatalogGroup::PROCUREMENT_WAREHOUSE,
            'warehouse',
            'material_warehouse_day',
            3,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(InventoryRiskCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => InventoryRiskCandidateContract::CODE,
            'title_key' => 'reports.catalog.inventory_risk',
            'catalog_group' => 'procurement_warehouse',
            'category' => 'warehouse',
            'grain' => 'material_warehouse_day',
            'wave' => 3,
            'source_module' => 'basic-warehouse',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => 'inventory-planning.v1',
                'source_schema' => 'inventory-planning.v1',
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => InventoryRiskCandidateContract::FORMULA_HASH,
                'source' => InventoryRiskCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['warehouse.advanced.view'],
                'export' => ['warehouse.reports.export'],
                'sensitive' => ['warehouse.view_custody'],
                'audit' => [],
            ],
            'readiness' => [
                'source' => 'ready',
                'formula' => 'ready',
                'delivery' => 'verified',
                'publication' => 'published',
            ],
            'capabilities' => [
                'supports_subscriptions' => false,
                'reproducible_scheduled_snapshot' => false,
            ],
        ];
    }
}
