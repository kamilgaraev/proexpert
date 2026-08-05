<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class ContractorScorecardBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 27;

    public function __construct(private ContractorScorecardCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();
        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);

        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(self::code(), 'reports.catalog.contractor_scorecard', ReportCatalogGroup::PARTNERS_CUSTOMERS, 'partners', 'contractor_category_cohort', 2, self::MANIFEST_ORDINAL);
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(self::code(), false, false);
    }

    public function document(): array
    {
        return [
            'code' => self::code(), 'title_key' => 'reports.catalog.contractor_scorecard',
            'catalog_group' => 'partners_customers', 'category' => 'partners', 'grain' => 'contractor_category_cohort',
            'wave' => 2, 'source_module' => 'contractor-portal', 'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(), 'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(), 'formats' => $this->contract->formats(),
            'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => ContractorScorecardCandidateContract::FORMULA_VERSION, 'source_schema' => ContractorScorecardCandidateContract::SOURCE_SCHEMA_VERSION, 'renderer' => self::RENDERER_VERSION],
            'semantic_fingerprints' => ['formula' => ContractorScorecardCandidateContract::FORMULA_HASH, 'source' => ContractorScorecardCandidateContract::SOURCE_HASH],
            'permissions' => ['view' => ['contractor_marketplace.profile.view'], 'export' => ['contractor_marketplace.reports.export'], 'sensitive' => [], 'audit' => []],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }

    private static function code(): string
    {
        return ContractorScorecardCandidateContract::CODE;
    }
}
