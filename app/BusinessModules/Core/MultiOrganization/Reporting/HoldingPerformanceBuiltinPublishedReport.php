<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class HoldingPerformanceBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 2;

    public function __construct(private HoldingPerformanceCandidateContract $contract) {}

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
            HoldingPerformanceCandidateContract::CODE,
            'reports.catalog.holding_performance',
            ReportCatalogGroup::PORTFOLIO,
            'portfolio',
            'organization_project_currency_period_basis',
            2,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(HoldingPerformanceCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => HoldingPerformanceCandidateContract::CODE,
            'title_key' => 'reports.catalog.holding_performance',
            'catalog_group' => 'portfolio',
            'category' => 'portfolio',
            'grain' => 'organization_project_currency_period_basis',
            'wave' => 2,
            'source_module' => 'multi-organization',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => HoldingPerformanceCandidateContract::FORMULA_VERSION,
                'source_schema' => HoldingPerformanceCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => HoldingPerformanceCandidateContract::FORMULA_HASH,
                'source' => HoldingPerformanceCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['multi-organization.reports.kpi'],
                'export' => ['multi-organization.reports.export'],
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
