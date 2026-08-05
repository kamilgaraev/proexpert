<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class QualityDefectFlowBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 23;

    public function __construct(private QualityDefectFlowCandidateContract $contract) {}

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
            QualityDefectFlowCandidateContract::CODE,
            'reports.catalog.quality_defect_flow',
            ReportCatalogGroup::QUALITY_SAFETY,
            'quality_safety',
            'defect_transition_project',
            2,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(QualityDefectFlowCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => QualityDefectFlowCandidateContract::CODE,
            'title_key' => 'reports.catalog.quality_defect_flow',
            'catalog_group' => 'quality_safety',
            'category' => 'quality_safety',
            'grain' => 'defect_transition_project',
            'wave' => 2,
            'source_module' => 'quality-control',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => QualityDefectFlowCandidateContract::FORMULA_VERSION,
                'source_schema' => QualityDefectFlowCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => QualityDefectFlowCandidateContract::FORMULA_HASH,
                'source' => QualityDefectFlowCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['quality-control.defects.view'],
                'export' => ['quality-control.reports.export'],
                'sensitive' => [],
                'audit' => ['quality-control.defects.view'],
            ],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }
}
