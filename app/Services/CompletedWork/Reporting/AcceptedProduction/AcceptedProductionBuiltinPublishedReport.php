<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class AcceptedProductionBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 8;

    public function __construct(private AcceptedProductionCandidateContract $contract) {}

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
            AcceptedProductionCandidateContract::CODE,
            'reports.catalog.accepted_production_progress',
            ReportCatalogGroup::PROJECTS,
            'production',
            'accepted_work_day',
            3,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(AcceptedProductionCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => AcceptedProductionCandidateContract::CODE,
            'title_key' => 'reports.catalog.accepted_production_progress',
            'catalog_group' => 'projects',
            'category' => 'production',
            'grain' => 'accepted_work_day',
            'wave' => 3,
            'source_module' => 'contract-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => AcceptedProductionCandidateContract::FORMULA_VERSION,
                'source_schema' => AcceptedProductionCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => AcceptedProductionCandidateContract::FORMULA_HASH,
                'source' => AcceptedProductionCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['reports.production_progress.view'],
                'export' => ['reports.production_progress.export'],
                'sensitive' => ['budgeting.wip_forecast.view_sensitive_costs'],
                'audit' => [],
            ],
            'output_classification' => [
                'default_classification' => 'standard',
                'sensitive_column_ids' => [
                    'accepted_amount_minor',
                    'approved_rate_minor',
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
