<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class ProjectEvmControlBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 5;

    public function __construct(private ProjectEvmControlCandidateContract $contract) {}

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
            ProjectEvmControlCandidateContract::CODE,
            'reports.catalog.project_evm_control',
            ReportCatalogGroup::PROJECTS,
            'control',
            'task_baseline_status_date',
            2,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(ProjectEvmControlCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => ProjectEvmControlCandidateContract::CODE,
            'title_key' => 'reports.catalog.project_evm_control',
            'catalog_group' => 'projects',
            'category' => 'control',
            'grain' => 'task_baseline_status_date',
            'wave' => 2,
            'source_module' => 'budgeting',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => ProjectEvmControlCandidateContract::FORMULA_VERSION,
                'source_schema' => ProjectEvmControlCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => ProjectEvmControlCandidateContract::FORMULA_HASH,
                'source' => ProjectEvmControlCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['reports.project_control.view'],
                'export' => ['reports.project_control.export'],
                'sensitive' => ['budgeting.wip_forecast.view_sensitive_costs'],
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
