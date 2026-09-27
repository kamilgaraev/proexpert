<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BudgetEstimates\Http\Middleware\EnsureBudgetEstimatesActive;
use App\Modules\Core\AccessController;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ConstructionJournalRouteEntitlementTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_all_mobile_construction_journal_routes_require_active_budget_estimates_module(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => preg_match(
                '#^api/v1/mobile/(construction-journals|journal-entries|construction-journal-exports)(/|$)#',
                $route->uri(),
            ) === 1,
        ));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertContains('budget-estimates.active', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_active_module_middleware_denies_inactive_and_allows_active_mobile_journal_requests(): void
    {
        $middleware = app(EnsureBudgetEstimatesActive::class);
        $request = Request::create('/api/v1/mobile/construction-journals');
        $request->attributes->set('current_organization_id', 42);

        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->once()->with(42, 'budget-estimates')->andReturnFalse();
        });

        $denied = $middleware->handle($request, static fn (): Response => new Response('passed'));

        $this->assertSame(403, $denied->getStatusCode());

        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->once()->with(42, 'budget-estimates')->andReturnTrue();
        });

        $allowed = $middleware->handle($request, static fn (): Response => new Response('passed'));

        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertSame('passed', $allowed->getContent());
    }
}
