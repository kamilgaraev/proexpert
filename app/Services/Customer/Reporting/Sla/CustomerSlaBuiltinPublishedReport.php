<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class CustomerSlaBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';
    public const RENDERER_VERSION = '1.0.0';
    public const MANIFEST_ORDINAL = 28;

    public function __construct(private CustomerSlaCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();
        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);

        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(self::code(), 'reports.catalog.customer_sla', ReportCatalogGroup::PARTNERS_CUSTOMERS, 'customers', 'request_event_customer', 3, self::MANIFEST_ORDINAL);
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(self::code(), false, false);
    }

    public function document(): array
    {
        return [
            'code' => self::code(), 'title_key' => 'reports.catalog.customer_sla',
            'catalog_group' => 'partners_customers', 'category' => 'customers',
            'grain' => 'request_event_customer', 'wave' => 3, 'source_module' => 'reports',
            'core_access_mode' => 'reporting_workspace', 'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(), 'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => CustomerSlaCandidateContract::FORMULA_VERSION, 'source_schema' => CustomerSlaCandidateContract::SOURCE_SCHEMA_VERSION, 'renderer' => self::RENDERER_VERSION],
            'semantic_fingerprints' => ['formula' => CustomerSlaCandidateContract::FORMULA_HASH, 'source' => CustomerSlaCandidateContract::SOURCE_HASH],
            'permissions' => ['view' => ['customer.sla_report.view'], 'export' => ['customer.sla_report.export'], 'sensitive' => [], 'audit' => []],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }

    private static function code(): string
    {
        return CustomerSlaCandidateContract::CODE;
    }
}
