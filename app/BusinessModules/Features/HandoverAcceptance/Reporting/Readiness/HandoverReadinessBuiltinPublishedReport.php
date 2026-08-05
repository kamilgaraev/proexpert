<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class HandoverReadinessBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0'; public const RENDERER_VERSION = '1.0.0'; public const MANIFEST_ORDINAL = 26;
    public function __construct(private HandoverReadinessCandidateContract $contract) {}
    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches(); $definition = (new ReportDefinitionFactory)->fromManifest($this->document()); $this->contract->assertDefinition($definition); return new PublishedReportDefinition($definition);
    }
    public function metadata(): ReportCatalogMetadata { return new ReportCatalogMetadata(self::code(), 'reports.catalog.handover_readiness', ReportCatalogGroup::PROJECTS, 'handover', 'gate_location_package', 3, self::MANIFEST_ORDINAL); }
    public function scheduling(): ReportSchedulingCapability { return new ReportSchedulingCapability(self::code(), false, false); }
    public function document(): array
    {
        return ['code' => self::code(), 'title_key' => 'reports.catalog.handover_readiness', 'catalog_group' => 'projects', 'category' => 'handover', 'grain' => 'gate_location_package', 'wave' => 3, 'source_module' => 'reports', 'core_access_mode' => 'reporting_workspace', 'filters' => $this->contract->filters(), 'columns' => $this->contract->columns(), 'sorts' => $this->contract->sorts(), 'formats' => $this->contract->formats(), 'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => HandoverReadinessCandidateContract::FORMULA_VERSION, 'source_schema' => HandoverReadinessCandidateContract::SOURCE_SCHEMA_VERSION, 'renderer' => self::RENDERER_VERSION], 'semantic_fingerprints' => ['formula' => HandoverReadinessCandidateContract::FORMULA_HASH, 'source' => HandoverReadinessCandidateContract::SOURCE_HASH], 'permissions' => ['view' => ['reports.project_readiness.view'], 'export' => ['reports.project_readiness.export'], 'sensitive' => [], 'audit' => []], 'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'], 'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false]];
    }
    private static function code(): string { return HandoverReadinessCandidateContract::CODE; }
}
