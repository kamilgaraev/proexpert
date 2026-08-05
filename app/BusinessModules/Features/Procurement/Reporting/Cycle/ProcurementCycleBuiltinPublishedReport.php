<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportAdapter;

final readonly class ProcurementCycleBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';

    public const RENDERER_VERSION = '1.0.0';

    public const MANIFEST_ORDINAL = 15;

    public function __construct(private ProcurementCycleCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();
        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);

        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(ProcurementCycleCandidateContract::CODE, 'reports.catalog.procurement_cycle', ReportCatalogGroup::PROCUREMENT_WAREHOUSE, 'procurement', 'request_line_process', 2, self::MANIFEST_ORDINAL);
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(ProcurementCycleCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => ProcurementCycleCandidateContract::CODE,
            'title_key' => 'reports.catalog.procurement_cycle',
            'catalog_group' => 'procurement_warehouse', 'category' => 'procurement', 'grain' => 'request_line_process', 'wave' => 2,
            'source_module' => 'procurement',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(), 'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(), 'formats' => $this->contract->formats(),
            'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => ProcurementCycleReportAdapter::FORMULA_VERSION, 'source_schema' => ProcurementCycleReportAdapter::SCHEMA_VERSION, 'renderer' => self::RENDERER_VERSION],
            'semantic_fingerprints' => ['formula' => ProcurementCycleCandidateContract::FORMULA_HASH, 'source' => ProcurementCycleCandidateContract::SOURCE_HASH],
            'permissions' => ['view' => ['procurement.dashboard.view'], 'export' => ['procurement.reports.export'], 'sensitive' => [], 'audit' => ['procurement.audit.view']],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }
}
