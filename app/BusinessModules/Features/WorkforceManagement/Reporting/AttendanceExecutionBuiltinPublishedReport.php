<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;

final readonly class AttendanceExecutionBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';

    public const RENDERER_VERSION = '1.0.0';

    public const MANIFEST_ORDINAL = 20;

    public function __construct(private AttendanceExecutionCandidateContract $contract) {}

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
            self::code(),
            'reports.catalog.attendance_execution',
            ReportCatalogGroup::TEAM,
            'workforce',
            'employee_shift_day',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(self::code(), false, false);
    }

    public function document(): array
    {
        return [
            'code' => self::code(),
            'title_key' => 'reports.catalog.attendance_execution',
            'catalog_group' => 'team',
            'category' => 'workforce',
            'grain' => 'employee_shift_day',
            'wave' => 1,
            'source_module' => 'workforce-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => DatabaseWorkforceReportAdapter::ATTENDANCE_FORMULA,
                'source_schema' => DatabaseWorkforceReportAdapter::SCHEMA_VERSION,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => AttendanceExecutionCandidateContract::FORMULA_HASH,
                'source' => AttendanceExecutionCandidateContract::SOURCE_HASH,
            ],
            'permissions' => [
                'view' => ['workforce.view'],
                'export' => ['workforce.reports.export'],
                'sensitive' => ['workforce.audit.view'],
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

    private static function code(): string
    {
        return AttendanceExecutionCandidateContract::CODE;
    }
}
