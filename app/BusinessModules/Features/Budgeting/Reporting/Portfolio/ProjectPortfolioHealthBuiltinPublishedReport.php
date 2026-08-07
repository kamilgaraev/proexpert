<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class ProjectPortfolioHealthBuiltinPublishedReport
{
    public function __construct(private ProjectPortfolioHealthCandidateContract $contract) {}
    public function definition(): PublishedReportDefinition { $this->contract->assertRuntimeMatches(); return new PublishedReportDefinition((new ReportDefinitionFactory)->fromManifest($this->document())); }
    public function metadata(): ReportCatalogMetadata { return new ReportCatalogMetadata(ProjectPortfolioHealthCandidateContract::CODE, 'reports.catalog.project_portfolio_health', ReportCatalogGroup::PORTFOLIO, 'portfolio', 'project_currency_as_of', 1, 1); }
    public function scheduling(): ReportSchedulingCapability { return new ReportSchedulingCapability(ProjectPortfolioHealthCandidateContract::CODE, false, false); }
    public function document(): array { return ['code' => ProjectPortfolioHealthCandidateContract::CODE, 'title_key' => 'reports.catalog.project_portfolio_health', 'catalog_group' => 'portfolio', 'category' => 'portfolio', 'grain' => 'project_currency_as_of', 'wave' => 1, 'source_module' => 'budgeting', 'core_access_mode' => 'source_module_report', 'filters' => $this->contract->filters(), 'columns' => $this->contract->columns(), 'sorts' => $this->contract->sorts(), 'formats' => $this->contract->formats(), 'versions' => ['contract' => '1.0.0', 'formula' => ProjectPortfolioHealthCandidateContract::FORMULA_VERSION, 'source_schema' => ProjectPortfolioHealthCandidateContract::SOURCE_SCHEMA_VERSION, 'renderer' => '1.0.0'], 'semantic_fingerprints' => ['formula' => ProjectPortfolioHealthCandidateContract::FORMULA_HASH, 'source' => ProjectPortfolioHealthCandidateContract::SOURCE_HASH], 'permissions' => ['view' => ['budgeting.portfolio_dashboard.view'], 'export' => ['budgeting.portfolio_dashboard.export'], 'sensitive' => [], 'audit' => []], 'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'], 'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false]]; }
}
