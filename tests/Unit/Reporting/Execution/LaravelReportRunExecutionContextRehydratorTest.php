<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportAsyncContextSeed;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelReportRunExecutionContextRehydrator;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\DeterministicReportModuleEntitlement;
use Tests\Support\Reporting\PolicyBackedCurrentReportAuthorizer;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class LaravelReportRunExecutionContextRehydratorTest extends TestCase
{
    public function test_it_consumes_one_atomic_authorization_and_replaces_only_transport_facts(): void
    {
        $scope = new ReportScope(7, [7], [11], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)->payload();
        $seedReader = new class($scope, $definition) implements ReportRunAsyncContextSeedReader
        {
            public function __construct(private ReportScope $scope, private \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition) {}

            public function forRun(string $runId): ReportAsyncContextSeed
            {
                return new ReportAsyncContextSeed('run', $runId, 7, 17, $this->scope, $this->definition, 'lineage-1');
            }
        };
        $authorizer = new class($scope) implements CurrentReportScopeAuthorizer
        {
            public int $calls = 0;

            public function __construct(private ReportScope $scope) {}

            public function authorizeForOrganization(int $actorId, int $organizationId, DateTimeZone $timezone, CurrentReportAuthorizationTarget $target): CurrentReportAuthorization
            {
                throw new \LogicException('unexpected');
            }

            public function authorizeCatalog(int $actorId, int $organizationId, DateTimeZone $timezone, array $targets): ReportCatalogAuthorization
            {
                throw new \LogicException('unexpected');
            }

            public function authorizeExact(int $actorId, ReportScope $requestedScope, CurrentReportAuthorizationTarget $target): CurrentReportAuthorization
            {
                $this->calls++;

                return new CurrentReportAuthorization(
                    new ReportActor($actorId, 'active', []),
                    new AuthorizationDecisionContext('queue', 7, [7], [11], [], new DateTimeZone('UTC'), 'temporary', null),
                    new ReportVisibility(true, true, false, false, false, false, false),
                    $target,
                );
            }

            public function authorizeExactMany(int $actorId, ReportScope $requestedScope, array $targets): array
            {
                return array_map(
                    fn (CurrentReportAuthorizationTarget $target): CurrentReportAuthorization => $this->authorizeExact(
                        $actorId,
                        $requestedScope,
                        $target,
                    ),
                    $targets,
                );
            }
        };

        $context = (new LaravelReportRunExecutionContextRehydrator($seedReader, $authorizer))
            ->forRun('01J00000000000000000000000');

        self::assertSame(1, $authorizer->calls);
        self::assertSame('queue', $context->authorization->channel);
        self::assertSame(['job' => 'materialize_report_run', 'lineage_id' => 'lineage-1'], $context->authorization->transportMetadata);
        self::assertNotSame('temporary', $context->correlationId());
        self::assertSame($scope->canonicalIdentity(), $context->scope->canonicalIdentity());
    }

    public function test_queue_rehydration_denies_source_report_after_module_revocation(): void
    {
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
        $seeds = new class($scope, $definition) implements ReportRunAsyncContextSeedReader
        {
            public function __construct(
                private readonly ReportScope $scope,
                private readonly \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition,
            ) {}

            public function forRun(string $runId): ReportAsyncContextSeed
            {
                return new ReportAsyncContextSeed(
                    'run',
                    $runId,
                    7,
                    17,
                    $this->scope,
                    $this->definition,
                    'lineage-revoked',
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

        (new LaravelReportRunExecutionContextRehydrator($seeds, $authorizer))
            ->forRun('01J00000000000000000000000');
    }
}
