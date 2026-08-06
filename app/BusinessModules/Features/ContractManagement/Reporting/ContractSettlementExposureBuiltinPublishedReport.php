<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class ContractSettlementExposureBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '3.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 14;

    public function __construct(private ContractSettlementExposureCandidateContract $contract) {}

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
            ContractSettlementExposureCandidateContract::CODE,
            'reports.catalog.contract_settlement_exposure',
            ReportCatalogGroup::FINANCE,
            'finance',
            'allocation_direction_currency_as_of',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(ContractSettlementExposureCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => ContractSettlementExposureCandidateContract::CODE,
            'title_key' => 'reports.catalog.contract_settlement_exposure',
            'catalog_group' => 'finance',
            'category' => 'finance',
            'grain' => 'allocation_direction_currency_as_of',
            'wave' => 1,
            'source_module' => 'contract-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => ContractSettlementExposureCandidateContract::FORMULA_VERSION,
                'source_schema' => ContractSettlementExposureCandidateContract::SOURCE_SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => ContractSettlementExposureCandidateContract::FORMULA_HASH,
                'source' => ContractSettlementExposureCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['contracts.management_report.view'],
                'export' => ['contracts.management_report.export'],
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
