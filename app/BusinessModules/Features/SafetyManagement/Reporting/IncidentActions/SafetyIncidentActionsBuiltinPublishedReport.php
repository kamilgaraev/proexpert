<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class SafetyIncidentActionsBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 24;

    public function __construct(private SafetyIncidentActionsCandidateContract $contract) {}

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
            SafetyIncidentActionsCandidateContract::CODE,
            'reports.catalog.safety_incident_actions',
            ReportCatalogGroup::QUALITY_SAFETY,
            'quality_safety',
            'incident_action_site_day',
            2,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(SafetyIncidentActionsCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => SafetyIncidentActionsCandidateContract::CODE,
            'title_key' => 'reports.catalog.safety_incident_actions',
            'catalog_group' => 'quality_safety',
            'category' => 'quality_safety',
            'grain' => 'incident_action_site_day',
            'wave' => 2,
            'source_module' => 'safety-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => SafetyIncidentActionsCandidateContract::FORMULA_VERSION, 'source_schema' => SafetyIncidentActionsCandidateContract::SOURCE_SCHEMA_VERSION, 'renderer' => self::RENDERER_VERSION],
            'semantic_fingerprints' => ['formula' => SafetyIncidentActionsCandidateContract::FORMULA_HASH, 'source' => SafetyIncidentActionsCandidateContract::SOURCE_HASH],
            'permissions' => ['view' => ['safety-management.view'], 'export' => ['safety-management.reports.export'], 'sensitive' => [], 'audit' => ['safety-management.view']],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }
}
