<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;

final readonly class PayrollReadinessBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';

    public const RENDERER_VERSION = '1.0.0';

    public const MANIFEST_ORDINAL = 22;

    public function __construct(private PayrollReadinessCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();
        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);

        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(PayrollReadinessCandidateContract::CODE, 'reports.catalog.payroll_readiness', ReportCatalogGroup::TEAM, 'workforce', 'period_employee_issue', 1, self::MANIFEST_ORDINAL);
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(PayrollReadinessCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return [
            'code' => PayrollReadinessCandidateContract::CODE,
            'title_key' => 'reports.catalog.payroll_readiness',
            'catalog_group' => 'team', 'category' => 'workforce', 'grain' => 'period_employee_issue', 'wave' => 1,
            'filters' => $this->contract->filters(), 'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(), 'formats' => $this->contract->formats(),
            'versions' => ['contract' => self::CONTRACT_VERSION, 'formula' => DatabasePayrollReadinessAdapter::FORMULA_VERSION, 'source_schema' => DatabasePayrollReadinessAdapter::SCHEMA_VERSION, 'renderer' => self::RENDERER_VERSION],
            'semantic_fingerprints' => ['formula' => PayrollReadinessCandidateContract::FORMULA_HASH, 'source' => PayrollReadinessCandidateContract::SOURCE_HASH],
            'permissions' => ['view' => ['workforce.view'], 'export' => ['workforce.reports.export'], 'sensitive' => [], 'audit' => ['workforce.audit.view']],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => 'published'],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }
}
