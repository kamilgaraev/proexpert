<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\TimeTracking\Reporting\Infrastructure\DatabaseProjectLaborCostAdapter;

final readonly class ProjectLaborCostBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';

    public const RENDERER_VERSION = '1.0.0';

    public const MANIFEST_ORDINAL = 21;

    public function __construct(private ProjectLaborCostCandidateContract $contract) {}

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
            ProjectLaborCostCandidateContract::CODE,
            'reports.catalog.project_labor_cost',
            ReportCatalogGroup::TEAM,
            'workforce',
            'approved_entry_employee_day',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(ProjectLaborCostCandidateContract::CODE, false, false);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return [
            'code' => ProjectLaborCostCandidateContract::CODE,
            'title_key' => 'reports.catalog.project_labor_cost',
            'catalog_group' => 'team',
            'category' => 'workforce',
            'grain' => 'approved_entry_employee_day',
            'wave' => 1,
            'source_module' => 'time-tracking',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => DatabaseProjectLaborCostAdapter::FORMULA_VERSION,
                'source_schema' => DatabaseProjectLaborCostAdapter::SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => ProjectLaborCostCandidateContract::FORMULA_HASH,
                'source' => ProjectLaborCostCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['time_tracking.view'],
                'export' => ['time_tracking.reports.export'],
                'sensitive' => ['time_tracking.cost.view'],
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
