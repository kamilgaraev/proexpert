<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportCoreAccessModeTest extends TestCase
{
    public function test_generic_and_source_module_contracts_are_explicit(): void
    {
        $generic = (new ReportDefinitionBuilder)->payload();
        $source = (new ReportDefinitionBuilder)
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx'])
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                ['act_reports.export.excel'],
                [],
                [],
            ))
            ->payload();

        self::assertSame('reports', $generic->sourceModule);
        self::assertSame(ReportCoreAccessMode::REPORTING_WORKSPACE, $generic->coreAccessMode);
        self::assertSame('act-reporting', $source->sourceModule);
        self::assertSame(ReportCoreAccessMode::SOURCE_MODULE_REPORT, $source->coreAccessMode);
    }

    #[DataProvider('invalidContracts')]
    public function test_invalid_source_module_contracts_fail_closed(
        string $sourceModule,
        ReportCoreAccessMode $mode,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_core_access_contract_invalid');

        (new ReportDefinitionBuilder)
            ->sourceModule($sourceModule)
            ->coreAccessMode($mode)
            ->formats($mode === ReportCoreAccessMode::SOURCE_MODULE_REPORT ? ['xlsx'] : ['csv'])
            ->permissionPolicy($mode === ReportCoreAccessMode::SOURCE_MODULE_REPORT
                ? new ReportPermissionPolicy(['act_reports.view'], ['act_reports.export.excel'], [], [])
                : new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []))
            ->payload();
    }

    public static function invalidContracts(): array
    {
        return [
            'workspace cannot use source module' => ['act-reporting', ReportCoreAccessMode::REPORTING_WORKSPACE],
            'source mode cannot use reports' => ['reports', ReportCoreAccessMode::SOURCE_MODULE_REPORT],
            'source mode is allow-listed' => ['finance', ReportCoreAccessMode::SOURCE_MODULE_REPORT],
            'slug is canonical' => ['Act Reports', ReportCoreAccessMode::SOURCE_MODULE_REPORT],
        ];
    }

    public function test_access_contract_participates_in_canonical_authorization_identity(): void
    {
        $generic = (new ReportDefinitionBuilder)->payload();
        $source = (new ReportDefinitionBuilder)
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx'])
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                ['act_reports.export.excel'],
                [],
                [],
            ))
            ->payload();

        self::assertNotSame(
            (new CurrentReportAuthorizationTarget($generic, ReportOperation::VIEW, null))->canonicalFingerprint(),
            (new CurrentReportAuthorizationTarget($source, ReportOperation::VIEW, null))->canonicalFingerprint(),
        );
    }

    public function test_source_export_permission_must_match_each_admitted_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_source_module_permission_policy_invalid');

        (new ReportDefinitionBuilder)
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx', 'pdf'])
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                ['act_reports.export.excel'],
                [],
                [],
            ))
            ->payload();
    }

    public function test_requested_export_format_participates_in_authorization_identity(): void
    {
        $definition = (new ReportDefinitionBuilder)
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx', 'pdf'])
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                ['act_reports.export.excel', 'act_reports.export.pdf'],
                [],
                [],
            ))
            ->payload();

        self::assertNotSame(
            (new CurrentReportAuthorizationTarget($definition, ReportOperation::EXPORT, $this->snapshot($definition), 'xlsx'))
                ->canonicalFingerprint(),
            (new CurrentReportAuthorizationTarget($definition, ReportOperation::EXPORT, $this->snapshot($definition), 'pdf'))
                ->canonicalFingerprint(),
        );
    }

    private function snapshot(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'report',
            'snapshot',
            new ReportScope(7, [7], [], [], new DateTimeZone('UTC')),
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('f', 64)),
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }
}
