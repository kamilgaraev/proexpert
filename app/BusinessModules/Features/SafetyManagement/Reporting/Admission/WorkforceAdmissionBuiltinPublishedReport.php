<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class WorkforceAdmissionBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 25;

    public function __construct(private WorkforceAdmissionCandidateContract $contract) {}

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
            WorkforceAdmissionCandidateContract::CODE,
            'reports.catalog.workforce_admission',
            ReportCatalogGroup::QUALITY_SAFETY,
            'quality_safety',
            'person_site_requirement_day',
            2,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(WorkforceAdmissionCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => WorkforceAdmissionCandidateContract::CODE,
            'title_key' => 'reports.catalog.workforce_admission',
            'catalog_group' => 'quality_safety',
            'category' => 'quality_safety',
            'grain' => 'person_site_requirement_day',
            'wave' => 2,
            'source_module' => 'safety-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => WorkforceAdmissionCandidateContract::FORMULA_VERSION,
                'source_schema' => WorkforceAdmissionCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => WorkforceAdmissionCandidateContract::FORMULA_HASH,
                'source' => WorkforceAdmissionCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['safety-management.view'],
                'export' => ['safety-management.reports.export'],
                'sensitive' => ['safety-management.medical.view'],
                'audit' => ['safety-management.view'],
            ],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }
}
