<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Domain\Authorization\Http\Middleware\RoleMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Middleware\ModulePermissionMiddleware;
use App\Modules\Services\ModulePermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class PrometheusAuthorizationMiddlewareTest extends TestCase
{
    public function test_authorize_middleware_does_not_bypass_permissions_for_prometheus_user_agent(): void
    {
        $user = $this->user();
        $request = $this->request($user);
        $authService = Mockery::mock(AuthorizationService::class);
        $authService->shouldReceive('can')
            ->once()
            ->with($user, 'projects.edit', Mockery::type('array'))
            ->andReturnFalse();

        $response = (new AuthorizeMiddleware($authService))->handle(
            $request,
            $this->passThroughCallback(),
            'projects.edit'
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_role_middleware_does_not_bypass_roles_for_prometheus_user_agent(): void
    {
        $user = $this->user();
        $request = $this->request($user);
        $authService = Mockery::mock(AuthorizationService::class);
        $authService->shouldReceive('hasRole')
            ->once()
            ->with($user, 'organization_owner', null)
            ->andReturnFalse();

        $response = (new RoleMiddleware($authService))->handle(
            $request,
            $this->passThroughCallback(),
            'organization_owner'
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_interface_middleware_does_not_bypass_interface_access_for_prometheus_user_agent(): void
    {
        $user = $this->user();
        $request = $this->request($user);
        $authService = Mockery::mock(AuthorizationService::class);
        $authService->shouldReceive('canAccessInterface')
            ->once()
            ->with($user, 'admin', Mockery::any())
            ->andReturnFalse();

        $response = (new InterfaceMiddleware($authService))->handle(
            $request,
            $this->passThroughCallback(),
            'admin'
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_module_permission_middleware_does_not_bypass_permissions_for_prometheus_user_agent(): void
    {
        $user = $this->user();
        Auth::shouldReceive('user')->once()->andReturn($user);

        $permissionService = Mockery::mock(ModulePermissionService::class);
        $permissionService->shouldReceive('userHasPermission')
            ->once()
            ->with($user, 'payments.approve')
            ->andReturnFalse();
        $permissionService->shouldReceive('getPermissionDetails')
            ->once()
            ->with('payments.approve')
            ->andReturn(['provided_by_modules' => ['payments']]);

        $response = (new ModulePermissionMiddleware($permissionService))->handle(
            $this->request($user),
            $this->passThroughCallback(),
            'payments.approve'
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    private function request(User $user): Request
    {
        $request = Request::create('/api/v1/admin/protected', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Prometheus/2.52.0',
        ]);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function user(): User
    {
        return new User([
            'id' => 101,
            'email' => 'security-audit@example.test',
            'current_organization_id' => 202,
        ]);
    }

    private function passThroughCallback(): callable
    {
        return static fn (): Response => new Response('passed', Response::HTTP_OK);
    }
}
