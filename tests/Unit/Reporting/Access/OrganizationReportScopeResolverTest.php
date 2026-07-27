<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\OrganizationReportScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Logging\Context\RequestContext;
use DateTimeZone;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class OrganizationReportScopeResolverTest extends TestCase
{
    public function test_resolves_only_server_supplied_scope(): void
    {
        $authorization = $this->authorization();

        $scope = (new OrganizationReportScopeResolver())->resolve($this->actor(), $authorization);

        self::assertSame(7, $scope->organizationId);
        self::assertSame([7, 9], $scope->holdingOrganizationIds);
        self::assertSame([101], $scope->projectIds);
        self::assertSame([501], $scope->resourceIds);
        self::assertSame('Europe/Moscow', $scope->timezone->getName());
    }

    public function test_rejects_scope_without_current_organization_in_holding_set(): void
    {
        $this->expectScopeForbidden();

        (new OrganizationReportScopeResolver())->resolve(
            $this->actor(),
            new AuthorizationDecisionContext('queue', 7, [9], [], [], new DateTimeZone('UTC'), 'corr', null),
        );
    }

    public function test_factory_reloads_active_actor_and_derives_global_visibility(): void
    {
        $loader = $this->loader($this->actor([
            'reports.download',
            'reports.export',
            'reports.manage',
            'reports.run',
            'reports.view',
        ]));
        $factory = new ReportExecutionContextFactory($loader, new OrganizationReportScopeResolver(), $this->requestContext());

        $context = $factory->create(41, $this->authorization());

        self::assertSame(41, $context->actor->id);
        self::assertTrue($context->visibility->canView);
        self::assertTrue($context->visibility->canRun);
        self::assertTrue($context->visibility->canExport);
        self::assertTrue($context->visibility->canDownload);
        self::assertTrue($context->visibility->canManage);
    }

    public function test_factory_converts_inactive_or_missing_actor_to_scope_forbidden(): void
    {
        $loader = new class implements ReportActorLoader {
            public function loadActive(int $actorId): ReportActor
            {
                throw new \RuntimeException('actor_not_active');
            }
        };
        $factory = new ReportExecutionContextFactory($loader, new OrganizationReportScopeResolver(), $this->requestContext());
        $this->expectScopeForbidden();

        $factory->create(41, $this->authorization());
    }

    public function test_from_http_ignores_client_scope_fields_and_keeps_only_route_metadata(): void
    {
        $request = Request::create(
            '/api/v1/admin/reports?organization_id=999',
            'POST',
            ['organization_id' => 998, 'user_id' => 997, 'permission' => 'reports.manage'],
        );
        $request->headers->set('X-Organization-Id', '996');
        $request->setUserResolver(static fn (?string $guard = null): object => new class {
            public function getAuthIdentifier(): int
            {
                return 41;
            }
        });
        $request->setRouteResolver(static fn (): object => new class {
            public function getName(): string
            {
                return 'admin.reports.catalog';
            }
        });
        $request->attributes->add([
            'current_organization_id' => 7,
            'holding_organization_ids' => [7, 9],
            'allowed_project_ids' => [101],
            'allowed_resource_ids' => [501],
            'organization_timezone' => 'Europe/Moscow',
        ]);
        $factory = new ReportExecutionContextFactory($this->loader($this->actor()), new OrganizationReportScopeResolver(), $this->requestContext());

        $context = $factory->fromHttp($request);

        self::assertSame(7, $context->scope->organizationId);
        self::assertSame(41, $context->actor->id);
        self::assertSame(['route' => 'admin.reports.catalog'], $context->authorization->transportMetadata);
        self::assertSame('corr-server', $context->correlationId());
    }

    public function test_from_http_rejects_missing_authenticated_actor(): void
    {
        $request = Request::create('/api/v1/admin/reports');
        $request->setUserResolver(static fn (): null => null);
        $request->attributes->set('current_organization_id', 7);
        $factory = new ReportExecutionContextFactory($this->loader($this->actor()), new OrganizationReportScopeResolver(), $this->requestContext());
        $this->expectScopeForbidden();

        $factory->fromHttp($request);
    }

    public function test_from_http_rejects_missing_server_organization(): void
    {
        $request = $this->validHttpRequest();
        $request->attributes->remove('current_organization_id');
        $factory = new ReportExecutionContextFactory($this->loader($this->actor()), new OrganizationReportScopeResolver(), $this->requestContext());
        $this->expectScopeForbidden();

        $factory->fromHttp($request);
    }

    public function test_from_http_rejects_invalid_server_timezone(): void
    {
        $request = $this->validHttpRequest();
        $request->attributes->set('organization_timezone', 'Not/A_Timezone');
        $factory = new ReportExecutionContextFactory($this->loader($this->actor()), new OrganizationReportScopeResolver(), $this->requestContext());
        $this->expectScopeForbidden();

        $factory->fromHttp($request);
    }

    public function test_authorization_bridge_forwards_queue_context_without_http_request(): void
    {
        $service = new class extends AuthorizationService {
            public ?array $receivedContext = null;

            public function __construct()
            {
            }

            protected function checkPermission(User $user, string $permission, ?array $context = null): bool
            {
                $this->receivedContext = $context;

                return $permission === 'reports.view';
            }
        };

        self::assertTrue($service->canInContext(new User(), 'reports.view', $this->authorization()));
        self::assertSame([
            'channel' => 'queue',
            'organization_id' => 7,
            'project_ids' => [101],
            'resource_ids' => [501],
        ], $service->receivedContext);
    }

    private function actor(array $permissions = ['reports.view']): ReportActor
    {
        return new ReportActor(41, 'active', $permissions);
    }

    private function authorization(): AuthorizationDecisionContext
    {
        return new AuthorizationDecisionContext(
            'queue',
            7,
            [7, 9],
            [101],
            [501],
            new DateTimeZone('Europe/Moscow'),
            'corr-server',
            null,
        );
    }

    private function loader(ReportActor $actor): ReportActorLoader
    {
        return new class($actor) implements ReportActorLoader {
            public function __construct(private readonly ReportActor $actor)
            {
            }

            public function loadActive(int $actorId): ReportActor
            {
                return new ReportActor($actorId, $this->actor->status, $this->actor->permissionSlugs);
            }
        };
    }

    private function requestContext(): RequestContext
    {
        return new class extends RequestContext {
            public function __construct()
            {
            }

            public function getCorrelationId(): string
            {
                return 'corr-server';
            }
        };
    }

    private function validHttpRequest(): Request
    {
        $request = Request::create('/api/v1/admin/reports');
        $request->setUserResolver(static fn (): object => new class {
            public function getAuthIdentifier(): int
            {
                return 41;
            }
        });
        $request->attributes->add([
            'current_organization_id' => 7,
            'holding_organization_ids' => [7],
            'organization_timezone' => 'UTC',
        ]);

        return $request;
    }

    private function expectScopeForbidden(): void
    {
        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage(ReportErrorCode::REPORT_SCOPE_FORBIDDEN->value);
    }
}
