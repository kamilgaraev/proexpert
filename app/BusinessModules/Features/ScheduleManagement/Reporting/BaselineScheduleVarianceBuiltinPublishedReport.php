<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class BaselineScheduleVarianceBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';

    public const RENDERER_VERSION = '1.0.0';

    public const MANIFEST_ORDINAL = 6;

    public function __construct(private BaselineScheduleVarianceCandidateContract $contract) {}

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
            BaselineScheduleVarianceCandidateContract::CODE,
            'reports.catalog.baseline_schedule_variance',
            ReportCatalogGroup::PROJECTS,
            'schedule',
            'task_baseline_as_of',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(BaselineScheduleVarianceCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => BaselineScheduleVarianceCandidateContract::CODE,
            'title_key' => 'reports.catalog.baseline_schedule_variance',
            'catalog_group' => 'projects',
            'category' => 'schedule',
            'grain' => 'task_baseline_as_of',
            'wave' => 1,
            'source_module' => 'schedule-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => BaselineScheduleVarianceCandidateContract::FORMULA_VERSION,
                'source_schema' => BaselineScheduleVarianceCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => BaselineScheduleVarianceCandidateContract::FORMULA_HASH,
                'source' => BaselineScheduleVarianceCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['schedule.view'],
                'export' => ['schedule.reports.export'],
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
