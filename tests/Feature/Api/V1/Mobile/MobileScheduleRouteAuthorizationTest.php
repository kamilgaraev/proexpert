<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileScheduleRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_daily_plan_mutation_routes_require_manage_permission(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => in_array($route->uri(), [
                'api/v1/mobile/schedule/daily-plan-assignments/{assignment}/fact',
                'api/v1/mobile/schedule/daily-plans/{dailyPlan}/submit',
            ], true)
        ));

        $this->assertCount(2, $routes);

        foreach ($routes as $route) {
            $this->assertContains(
                'authorize:schedule.daily_plan.manage',
                $route->gatherMiddleware(),
                $route->uri()
            );
        }
    }
}
