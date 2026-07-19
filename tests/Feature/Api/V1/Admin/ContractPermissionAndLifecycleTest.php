<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractLifecycleService;
use App\Services\Contract\ContractService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

final class ContractPermissionAndLifecycleTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_contract_routes_require_canonical_permissions(): void
    {
        $this->assertRoutePermission('GET', 'api/v1/admin/contracts', 'contracts.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/contracts', 'contracts.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/contracts/{contract}', 'contracts.view');
        $this->assertRoutePermission('PUT', 'api/v1/admin/contracts/{contract}', 'contracts.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/contracts/{contract}', 'contracts.delete');
        $this->assertRoutePermission('POST', 'api/v1/admin/contracts/{contract}/activate', 'contracts.edit');
        $this->assertRoutePermission('POST', 'api/v1/admin/contracts/{contract}/archive', 'contracts.archive');

        $this->assertRoutePermission('GET', 'api/v1/admin/projects/{project}/contracts', 'contracts.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/projects/{project}/contracts', 'contracts.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/projects/{project}/contracts/{contract}', 'contracts.view');
        $this->assertRoutePermission('PUT', 'api/v1/admin/projects/{project}/contracts/{contract}', 'contracts.edit');
        $this->assertRoutePermission('DELETE', 'api/v1/admin/projects/{project}/contracts/{contract}', 'contracts.delete');
        $this->assertRoutePermission('POST', 'api/v1/admin/projects/{project}/contracts/{contract}/archive', 'contracts.archive');
    }

    public function test_user_without_create_permission_receives_forbidden_response(): void
    {
        $user = new User;
        $user->id = 15;
        $request = Request::create('/api/v1/admin/contracts', 'POST');
        $request->setUserResolver(static fn (): User => $user);
        $middleware = new AuthorizeMiddleware($this->mockAuthorization(static fn (): bool => false));

        $response = $middleware->handle($request, static fn () => null, 'contracts.create');

        self::assertSame(403, $response->status());
    }

    public function test_lifecycle_service_applies_the_complete_transition_matrix(): void
    {
        $actor = new User;
        $actor->id = 42;
        $service = app(ContractLifecycleService::class);

        foreach ([
            ['draft', 'activate', 'active'],
            ['draft', 'archive', 'archived'],
            ['active', 'suspend', 'on_hold'],
            ['active', 'complete', 'completed'],
            ['active', 'terminate', 'terminated'],
            ['on_hold', 'resume', 'active'],
            ['on_hold', 'terminate', 'terminated'],
            ['completed', 'archive', 'archived'],
            ['terminated', 'archive', 'archived'],
        ] as [$from, $action, $to]) {
            $contract = $this->contractWithStatus($from);

            $transitioned = $service->transition($contract, $action, $actor, 'Основание перехода');

            self::assertSame($to, $transitioned->status->value);
        }
    }

    public function test_invalid_transition_is_reported_as_conflict(): void
    {
        $actor = new User;
        $actor->id = 42;
        $contract = $this->contractWithStatus('draft', false);

        try {
            app(ContractLifecycleService::class)->transition($contract, 'complete', $actor, null);
            self::fail('Недопустимый переход должен завершаться конфликтом.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }

        self::assertSame('draft', $contract->status->value);
    }

    public function test_archive_action_archives_draft_and_rejects_repeated_archive(): void
    {
        $actor = new User;
        $actor->id = 42;
        $contract = $this->contractWithStatus('draft');
        $service = app(ContractLifecycleService::class);

        $service->transition($contract, 'archive', $actor, 'Документ перенесен в юридический архив');
        self::assertSame('archived', $contract->status->value);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);
        $service->transition($contract, 'archive', $actor, null);
    }

    public function test_legacy_delete_is_safe_and_returns_conflict(): void
    {
        $controller = new ContractController(
            \Mockery::mock(ContractService::class),
            \Mockery::mock(OfficialFormsExportService::class),
            new ContractLifecycleService
        );

        $response = $controller->destroy(123, Request::create('/api/v1/admin/contracts/123', 'DELETE'));

        self::assertSame(409, $response->status());
    }

    private function contractWithStatus(string $status, bool $expectsSave = true): Contract
    {
        $contract = \Mockery::mock(Contract::class)->makePartial();
        if ($expectsSave) {
            $contract->shouldReceive('save')->once()->andReturnTrue();
        }
        $contract->setRawAttributes([
            'id' => 10,
            'status' => $status,
        ]);

        return $contract;
    }

    private function mockAuthorization(callable $can): AuthorizationService
    {
        return $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($can): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, ?array $context = null): bool => $can($permission)
            );
        });
    }

    private function assertRoutePermission(string $method, string $uri, string $permission): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(
            static fn (LaravelRoute $route): bool => $route->uri() === $uri && in_array($method, $route->methods(), true)
        );

        self::assertNotEmpty($routes, "Маршрут {$method} {$uri} не найден.");

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $hasPermissionMiddleware = collect($middleware)->contains(
                static fn (string $value): bool => str_starts_with($value, "authorize:{$permission}")
                    || str_starts_with($value, AuthorizeMiddleware::class.":{$permission}")
            );

            self::assertTrue(
                $hasPermissionMiddleware,
                "Маршрут {$method} {$uri} не защищен правом {$permission}. Стек: ".implode(', ', $middleware)
            );
        }
    }
}
