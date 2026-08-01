<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportAsyncContextSeed;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelReportExportExecutionContextRehydrator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\DeterministicReportModuleEntitlement;
use Tests\Support\Reporting\PolicyBackedCurrentReportAuthorizer;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class LaravelReportExportExecutionContextRehydratorTest extends TestCase
{
    public function test_export_queue_rehydration_denies_source_report_after_module_revocation(): void
    {
        $exportId = '01J00000000000000000000001';
        $runId = '01J00000000000000000000000';
        $scope = new ReportScope(7, [7], [], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
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
        $snapshot = new ReportSnapshotRef(
            'report',
            'snapshot',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('f', 64)),
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $seeds = new class($scope, $definition, $runId) implements ReportExportAsyncContextSeedReader
        {
            public function __construct(
                private readonly ReportScope $scope,
                private readonly \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition,
                private readonly string $runId,
            ) {}

            public function forExport(string $exportId): ReportAsyncContextSeed
            {
                return new ReportAsyncContextSeed(
                    'run',
                    $this->runId,
                    7,
                    17,
                    $this->scope,
                    $this->definition,
                    'lineage-export-revoked',
                );
            }
        };
        $subjects = new class($definition, $scope, $snapshot, $runId) implements ReportAuthorizationSubjectReader
        {
            public function __construct(
                private readonly \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition,
                private readonly ReportScope $scope,
                private readonly ReportSnapshotRef $snapshot,
                private readonly string $runId,
            ) {}

            public function run(string $runId): ReportAuthorizationSubject
            {
                throw new \LogicException('unexpected');
            }

            public function export(string $exportId): ReportAuthorizationSubject
            {
                return new ReportAuthorizationSubject(
                    ReportDispatchAggregate::EXPORT,
                    $exportId,
                    $this->definition,
                    $this->scope,
                    $this->snapshot,
                    $this->runId,
                    null,
                    null,
                    'xlsx',
                );
            }
        };
        $authorizer = new PolicyBackedCurrentReportAuthorizer(
            new ReportDefinitionVisibilityResolver(
                new ReportDefinitionModuleAuthorizer(new DeterministicReportModuleEntitlement([], [7])),
            ),
            ['act_reports.view', 'act_reports.export.excel'],
        );

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage('REPORT_SCOPE_FORBIDDEN');

        (new LaravelReportExportExecutionContextRehydrator($seeds, $subjects, $authorizer))
            ->forExport($exportId);
    }
}
