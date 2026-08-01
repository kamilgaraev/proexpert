<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\CurrentReportAbacEvaluator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportAsyncContextSeed;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelReportRunExecutionContextRehydrator;
use App\Models\Organization;
use App\Models\User;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class LaravelSourceModeAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('REPORT_SOURCE_MODE_AUTHORIZATION_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set REPORT_SOURCE_MODE_AUTHORIZATION_POSTGRES_TESTS=1 to run isolated source-mode authorization tests.',
            );
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_http_binding_reaches_real_final_authorizer_for_allow_and_revocations(): void
    {
        [$actor, $organization] = $this->actorFixture();
        $definition = $this->sourceDefinition();

        $allowedModules = new SequencedReportModuleEntitlement([true, true]);
        $allowedPermissions = new MutableCurrentReportPermissionEvaluator(['act_reports.view']);
        $authorizer = $this->installProductionAuthorizer($allowedModules, $allowedPermissions);
        [$middleware, $orchestrator] = $this->httpBoundary($definition);

        $response = $middleware->handle(
            $this->request($actor, $organization, $definition->code),
            function (Request $request) use ($orchestrator, $definition): Response {
                $authorization = $orchestrator->createRun($request, $definition->code);
                self::assertInstanceOf(
                    LaravelCurrentReportScopeAuthorizer::class,
                    $this->privateValue($orchestrator, 'authorizer'),
                );
                self::assertTrue($authorization['authorization']->visibility->canRun);

                return new Response('', 204);
            },
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(2, $allowedModules->calls);
        self::assertSame($authorizer, $this->privateValue($orchestrator, 'authorizer'));

        $revokedAtFinalDecision = new SequencedReportModuleEntitlement([true, false]);
        $this->installProductionAuthorizer($revokedAtFinalDecision, $allowedPermissions);
        [$middleware, $orchestrator] = $this->httpBoundary($definition);
        $this->assertForbidden(function () use ($middleware, $orchestrator, $actor, $organization, $definition): void {
            $middleware->handle(
                $this->request($actor, $organization, $definition->code),
                function (Request $request) use ($orchestrator, $definition): Response {
                    $orchestrator->createRun($request, $definition->code);

                    return new Response('', 204);
                },
            );
        });
        self::assertSame(2, $revokedAtFinalDecision->calls);

        $exactPermissionRevoked = new MutableCurrentReportPermissionEvaluator([]);
        $modulesAllowed = new SequencedReportModuleEntitlement([true, true]);
        $this->installProductionAuthorizer($modulesAllowed, $exactPermissionRevoked);
        [$middleware, $orchestrator] = $this->httpBoundary($definition);
        $this->assertForbidden(function () use ($middleware, $orchestrator, $actor, $organization, $definition): void {
            $middleware->handle(
                $this->request($actor, $organization, $definition->code),
                function (Request $request) use ($orchestrator, $definition): Response {
                    $orchestrator->createRun($request, $definition->code);

                    return new Response('', 204);
                },
            );
        });
        self::assertSame(2, $modulesAllowed->calls);
        self::assertContains('act_reports.view', $exactPermissionRevoked->evaluatedPermissions);
    }

    public function test_async_binding_reaches_real_final_authorizer_and_rechecks_each_decision(): void
    {
        [$actor, $organization] = $this->actorFixture();
        $definition = $this->sourceDefinition();
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [],
            [],
            new DateTimeZone('UTC'),
        );
        $modules = new MutableReportModuleEntitlement(['act-reporting' => true]);
        $permissions = new MutableCurrentReportPermissionEvaluator(['act_reports.view']);
        $authorizer = $this->installProductionAuthorizer($modules, $permissions);
        $this->app->instance(
            ReportRunAsyncContextSeedReader::class,
            new FixedReportRunAsyncContextSeedReader((int) $actor->id, $scope, $definition),
        );
        $this->app->forgetInstance(ReportRunExecutionContextRehydrator::class);
        $rehydrator = $this->app->make(ReportRunExecutionContextRehydrator::class);

        self::assertInstanceOf(LaravelReportRunExecutionContextRehydrator::class, $rehydrator);
        self::assertSame($authorizer, $this->privateValue($rehydrator, 'authorizer'));
        self::assertTrue($rehydrator->forRun('01J00000000000000000000000')->visibility->canRun);
        self::assertSame(1, $modules->calls['act-reporting'] ?? 0);

        $modules->allowed['act-reporting'] = false;
        $this->assertForbidden(
            static fn () => $rehydrator->forRun('01J00000000000000000000000'),
        );
        self::assertSame(2, $modules->calls['act-reporting'] ?? 0);

        $modules->allowed['act-reporting'] = true;
        $permissions->allowed = [];
        $this->assertForbidden(
            static fn () => $rehydrator->forRun('01J00000000000000000000000'),
        );
        self::assertSame(3, $modules->calls['act-reporting'] ?? 0);
        self::assertContains('act_reports.view', $permissions->evaluatedPermissions);
    }

    private function installProductionAuthorizer(
        ReportModuleEntitlement $modules,
        CurrentReportAbacEvaluator $permissions,
    ): LaravelCurrentReportScopeAuthorizer {
        $this->app->instance(ReportModuleEntitlement::class, $modules);
        $this->app->instance(CurrentReportAbacEvaluator::class, $permissions);
        $this->app->forgetInstance(LaravelCurrentReportScopeAuthorizer::class);
        $this->app->forgetInstance(CurrentReportScopeAuthorizer::class);

        $authorizer = $this->app->make(CurrentReportScopeAuthorizer::class);
        self::assertInstanceOf(LaravelCurrentReportScopeAuthorizer::class, $authorizer);

        return $authorizer;
    }

    /** @return array{AuthorizeReportDefinitionAccess, ReportHttpAuthorizationOrchestrator} */
    private function httpBoundary(ReportDefinition $definition): array
    {
        $this->app->instance(
            ReportHttpAuthorizationTargetResolver::class,
            new FixedSourceModeTargetResolver($definition),
        );
        $this->app->instance(
            ReportAuthorizationSubjectReader::class,
            new UnusedReportAuthorizationSubjectReader,
        );
        $this->app->forgetInstance(ReportHttpAuthorizationOrchestrator::class);

        return [
            $this->app->make(AuthorizeReportDefinitionAccess::class),
            $this->app->make(ReportHttpAuthorizationOrchestrator::class),
        ];
    }

    private function request(User $actor, Organization $organization, string $reportCode): Request
    {
        $request = Request::create("/api/v1/admin/reports/{$reportCode}/runs", 'POST');
        $request->setUserResolver(static fn (?string $guard = null): User => $actor);
        $request->attributes->set('current_organization_id', (int) $organization->id);
        $route = new Route(
            ['POST'],
            'api/v1/admin/reports/{reportCode}/runs',
            static fn (): Response => new Response,
        );
        $route->name('admin.reports.runs.store');
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }

    private function sourceDefinition(): ReportDefinition
    {
        return (new ReportDefinitionBuilder)
            ->code('act_execution')
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
    }

    /** @return array{User, Organization} */
    private function actorFixture(): array
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => 'all',
        ]);

        return [$actor, $organization];
    }

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            self::fail('Revoked source-mode authorization was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame('REPORT_SCOPE_FORBIDDEN', $exception->errorCode->value);
        }
    }

    private function privateValue(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}

final class FixedSourceModeTargetResolver implements ReportHttpAuthorizationTargetResolver
{
    public function __construct(private readonly ReportDefinition $definition) {}

    public function createRun(string $reportCode): CurrentReportAuthorizationTarget
    {
        return new CurrentReportAuthorizationTarget($this->definition, ReportOperation::RUN, null);
    }

    public function run(string $runId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        throw new \LogicException('unexpected');
    }

    public function createExport(string $runId, ?string $format): CurrentReportAuthorizationTarget
    {
        throw new \LogicException('unexpected');
    }

    public function export(string $exportId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        throw new \LogicException('unexpected');
    }

    public function catalog(): array
    {
        return [new CurrentReportAuthorizationTarget($this->definition, ReportOperation::VIEW, null)];
    }
}

final class UnusedReportAuthorizationSubjectReader implements ReportAuthorizationSubjectReader
{
    public function run(string $runId): ReportAuthorizationSubject
    {
        throw new \LogicException('unexpected');
    }

    public function export(string $exportId): ReportAuthorizationSubject
    {
        throw new \LogicException('unexpected');
    }
}

final class SequencedReportModuleEntitlement implements ReportModuleEntitlement
{
    public int $calls = 0;

    /** @param list<bool> $states */
    public function __construct(private readonly array $states) {}

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        $state = $this->states[min($this->calls, count($this->states) - 1)] ?? false;
        $this->calls++;

        return $organizationId > 0 && $moduleSlug === 'act-reporting' && $state;
    }
}

final class MutableReportModuleEntitlement implements ReportModuleEntitlement
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, bool> $allowed */
    public function __construct(public array $allowed) {}

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        $this->calls[$moduleSlug] = ($this->calls[$moduleSlug] ?? 0) + 1;

        return $organizationId > 0 && ($this->allowed[$moduleSlug] ?? false);
    }
}

final class MutableCurrentReportPermissionEvaluator implements CurrentReportAbacEvaluator
{
    /** @var list<string> */
    public array $evaluatedPermissions = [];

    /** @param list<string> $allowed */
    public function __construct(public array $allowed) {}

    public function evaluate(
        int $actorId,
        string $permission,
        CurrentReportAuthorizationFacts $facts,
    ): CurrentReportPermissionDecision {
        $this->evaluatedPermissions[] = $permission;

        return new CurrentReportPermissionDecision(
            $actorId,
            $permission,
            $facts->organizationId,
            $facts->projectId,
            $facts->resource,
            in_array($permission, $this->allowed, true),
        );
    }
}

final readonly class FixedReportRunAsyncContextSeedReader implements ReportRunAsyncContextSeedReader
{
    public function __construct(
        private int $actorId,
        private ReportScope $scope,
        private ReportDefinition $definition,
    ) {}

    public function forRun(string $runId): ReportAsyncContextSeed
    {
        return new ReportAsyncContextSeed(
            'run',
            $runId,
            $this->scope->organizationId,
            $this->actorId,
            $this->scope,
            $this->definition,
            'source-mode-real-authorizer',
        );
    }
}
