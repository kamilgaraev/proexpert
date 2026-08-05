<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class PortfolioLiquidityBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';

    public const RENDERER_VERSION = '1.0.0';

    public const MANIFEST_ORDINAL = 4;

    public function __construct(private PortfolioLiquidityCandidateContract $contract) {}

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
            PortfolioLiquidityCandidateContract::CODE,
            'reports.catalog.portfolio_liquidity',
            ReportCatalogGroup::PORTFOLIO,
            'finance',
            'day_project_currency_scenario',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(PortfolioLiquidityCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => PortfolioLiquidityCandidateContract::CODE,
            'title_key' => 'reports.catalog.portfolio_liquidity',
            'catalog_group' => 'portfolio',
            'category' => 'finance',
            'grain' => 'day_project_currency_scenario',
            'wave' => 1,
            'source_module' => 'budgeting',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => PortfolioLiquidityCandidateContract::FORMULA_VERSION,
                'source_schema' => PortfolioLiquidityCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => PortfolioLiquidityCandidateContract::FORMULA_HASH,
                'source' => PortfolioLiquidityCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['budgeting.cfo.view'],
                'export' => ['budgeting.cash_gap.export'],
                'sensitive' => [],
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
