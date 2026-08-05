<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class IntercompanyContractFlowBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 3;

    public function __construct(private IntercompanyContractFlowCandidateContract $contract) {}

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
            IntercompanyContractFlowCandidateContract::CODE,
            'reports.catalog.intercompany_contract_flows',
            ReportCatalogGroup::FINANCE,
            'finance',
            'allocation_counterparty_period',
            2,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(IntercompanyContractFlowCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => IntercompanyContractFlowCandidateContract::CODE,
            'title_key' => 'reports.catalog.intercompany_contract_flows',
            'catalog_group' => 'finance',
            'category' => 'finance',
            'grain' => 'allocation_counterparty_period',
            'wave' => 2,
            'source_module' => 'multi-organization',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => IntercompanyContractFlowCandidateContract::FORMULA_VERSION,
                'source_schema' => IntercompanyContractFlowCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => IntercompanyContractFlowCandidateContract::FORMULA_HASH,
                'source' => IntercompanyContractFlowCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['multi-organization.reports.financial'],
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
