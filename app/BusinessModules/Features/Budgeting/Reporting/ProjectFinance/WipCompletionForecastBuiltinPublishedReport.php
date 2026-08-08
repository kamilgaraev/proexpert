<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class WipCompletionForecastBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 11;

    public function __construct(private WipCompletionForecastCandidateContract $contract) {}

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
            WipCompletionForecastCandidateContract::CODE,
            'reports.catalog.wip_completion_forecast',
            ReportCatalogGroup::FINANCE,
            'finance',
            'forecast_provider_currency',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(WipCompletionForecastCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => WipCompletionForecastCandidateContract::CODE,
            'title_key' => 'reports.catalog.wip_completion_forecast',
            'catalog_group' => 'finance',
            'category' => 'finance',
            'grain' => 'forecast_provider_currency',
            'wave' => 1,
            'source_module' => 'budgeting',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => WipCompletionForecastCandidateContract::FORMULA_VERSION,
                'source_schema' => WipCompletionForecastCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => WipCompletionForecastCandidateContract::FORMULA_HASH,
                'source' => WipCompletionForecastCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['budgeting.wip_forecast.view'],
                'export' => ['budgeting.wip_forecast.export'],
                'sensitive' => ['budgeting.wip_forecast.view_sensitive_costs'],
                'audit' => ['budgeting.wip_forecast.view_audit'],
            ],
            'output_classification' => [
                'default_classification' => 'standard',
                'sensitive_column_ids' => [
                    'bac',
                    'pv',
                    'ev',
                    'ac',
                    'ctc',
                    'eac',
                    'forecast_variance',
                    'cpi',
                ],
                'audit_column_ids' => [],
                'totals_sensitive' => false,
                'totals_audit' => false,
                'provenance_audit' => false,
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
