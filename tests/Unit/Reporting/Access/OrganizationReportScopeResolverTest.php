<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\OrganizationReportScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use App\Services\Logging\LoggingService;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class OrganizationReportScopeResolverTest extends TestCase
{
    public function test_resolves_only_server_supplied_scope(): void
    {
        $authorization = $this->authorization();

        $scope = (new OrganizationReportScopeResolver)->resolve($this->actor(), $authorization);

        self::assertSame(7, $scope->organizationId);
        self::assertSame([7, 9], $scope->holdingOrganizationIds);
        self::assertSame([101], $scope->projectIds);
        self::assertSame(
            [['kind' => 'task', 'id' => 501, 'project_id' => 101]],
            array_map(static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(), $scope->resources),
        );
        self::assertSame('Europe/Moscow', $scope->timezone->getName());
    }

    public function test_rejects_scope_without_current_organization_in_holding_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_scope_holding_organization_missing');

        (new OrganizationReportScopeResolver)->resolve(
            $this->actor(),
            new AuthorizationDecisionContext('queue', 7, [9], [], [], new DateTimeZone('UTC'), 'corr', null),
        );
    }

    public function test_factory_builds_context_only_from_atomic_current_authorization(): void
    {
        $definition = (new \Tests\Support\Reporting\ReportDefinitionBuilder)->payload();
        $authorization = new CurrentReportAuthorization(
            $this->actor(['reports.view']),
            $this->authorization(),
            new ReportVisibility(true, false, false, false, false, false, false),
            new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, null),
        );

        $context = (new ReportExecutionContextFactory)->fromCurrentAuthorization($authorization);

        self::assertSame(41, $context->actor->id);
        self::assertTrue($context->visibility->canView);
        self::assertSame($this->authorization()->toAuthorizationArray(), $context->authorization->toAuthorizationArray());
    }

    public function test_http_facts_ignore_every_client_and_ambient_authority_field(): void
    {
        $request = Request::create(
            '/api/v1/admin/reports?organization_id=999',
            'POST',
            ['organization_id' => 998, 'user_id' => 997, 'permission' => 'reports.manage'],
        );
        $request->headers->set('X-Organization-Id', '996');
        $request->setUserResolver(static fn (?string $guard = null): object => new class
        {
            public function getAuthIdentifier(): int
            {
                return 41;
            }
        });
        $request->setRouteResolver(static fn (): object => new class
        {
            public function getName(): string
            {
                return 'admin.reports.catalog';
            }
        });
        $request->attributes->add([
            'current_organization_id' => 7,
            'holding_organization_ids' => [7, 9],
            'allowed_project_ids' => [101],
            'resources' => [['kind' => 'forged', 'id' => 501, 'project_id' => 101]],
            'organization_timezone' => 'Europe/Moscow',
        ]);
        $facts = (new ReportExecutionContextFactory)->httpFacts($request);

        self::assertSame(['actor_id' => 41, 'organization_id' => 7], $facts);
    }

    public function test_http_facts_reject_missing_authenticated_actor(): void
    {
        $request = Request::create('/api/v1/admin/reports');
        $request->setUserResolver(static fn (): null => null);
        $request->attributes->set('current_organization_id', 7);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_http_authorization_facts_invalid');

        (new ReportExecutionContextFactory)->httpFacts($request);
    }

    public function test_http_facts_reject_missing_server_organization(): void
    {
        $request = $this->validHttpRequest();
        $request->attributes->remove('current_organization_id');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_http_authorization_facts_invalid');

        (new ReportExecutionContextFactory)->httpFacts($request);
    }

    public function test_authorization_bridge_forwards_queue_context_without_http_request(): void
    {
        $previousContainer = Container::getInstance();
        $container = new Container;
        $container->bind('request', static function (): never {
            throw new \RuntimeException('ambient_request_was_resolved');
        });
        Container::setInstance($container);
        $service = new class($this->createMock(RoleScanner::class), $this->createMock(PermissionResolver::class), $this->createMock(LoggingService::class)) extends AuthorizationService
        {
            public function getUserRoles(User $user, ?AuthorizationContext $context = null): Collection
            {
                return collect();
            }

            protected function resolveAuthContext(?array $context): ?AuthorizationContext
            {
                return null;
            }
        };
        $user = new User;
        $user->id = 41;

        try {
            self::assertFalse($service->canInContext($user, 'reports.view', $this->authorization()));
        } finally {
            Container::setInstance($previousContainer);
        }
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
            [new ReportScopedResource('task', 501, 101)],
            new DateTimeZone('Europe/Moscow'),
            'corr-server',
            null,
        );
    }

    private function validHttpRequest(): Request
    {
        $request = Request::create('/api/v1/admin/reports');
        $request->setUserResolver(static fn (): object => new class
        {
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
}
