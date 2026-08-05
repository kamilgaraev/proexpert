<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class SupplyReliabilityBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 17;

    public function __construct(private SupplyReliabilityCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();
        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);
        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(SupplyReliabilityCandidateContract::CODE, 'reports.catalog.supply_reliability', ReportCatalogGroup::PROCUREMENT_WAREHOUSE, 'procurement', 'purchase_order_line_promise', 3, self::MANIFEST_ORDINAL);
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(SupplyReliabilityCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => SupplyReliabilityCandidateContract::CODE, 'title_key' => 'reports.catalog.supply_reliability',
            'catalog_group' => 'procurement_warehouse', 'category' => 'procurement', 'grain' => 'purchase_order_line_promise', 'wave' => 3,
            'source_module' => 'procurement', 'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(), 'columns' => $this->contract->columns(), 'sorts' => $this->contract->sorts(), 'formats' => $this->contract->formats(),
            'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => 'supply-otif.v1', 'source_schema' => 'supply-otif.v1', 'renderer' => self::RENDERER_VERSION],
            'semantic_fingerprints' => ['formula' => SupplyReliabilityCandidateContract::FORMULA_HASH, 'source' => SupplyReliabilityCandidateContract::SOURCE_HASH],
            'permissions' => ['view' => ['procurement.purchase_orders.view'], 'export' => ['procurement.reports.export'], 'sensitive' => [], 'audit' => []],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }
}
