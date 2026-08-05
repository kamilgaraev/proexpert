<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class SupplierAwardBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 16;

    public function __construct(private SupplierAwardCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();
        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);

        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(SupplierAwardCandidateContract::CODE, 'reports.catalog.supplier_award_competitiveness', ReportCatalogGroup::PROCUREMENT_WAREHOUSE, 'procurement', 'decision_proposal_currency', 2, self::MANIFEST_ORDINAL);
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(SupplierAwardCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => SupplierAwardCandidateContract::CODE,
            'title_key' => 'reports.catalog.supplier_award_competitiveness',
            'catalog_group' => 'procurement_warehouse', 'category' => 'procurement', 'grain' => 'decision_proposal_currency', 'wave' => 2,
            'source_module' => 'procurement', 'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(), 'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(), 'formats' => $this->contract->formats(),
            'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => 'supplier-award.v1', 'source_schema' => 'supplier-award.v1', 'renderer' => self::RENDERER_VERSION],
            'semantic_fingerprints' => ['formula' => SupplierAwardCandidateContract::FORMULA_HASH, 'source' => SupplierAwardCandidateContract::SOURCE_HASH],
            'permissions' => ['view' => ['procurement.supplier_proposals.view'], 'export' => ['procurement.reports.export'], 'sensitive' => ['procurement.proposal_decisions.view'], 'audit' => []],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }
}
