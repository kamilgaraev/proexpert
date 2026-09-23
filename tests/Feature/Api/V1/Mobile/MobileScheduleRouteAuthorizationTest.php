<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileScheduleRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_mobile_schedule_mutation_routes_require_edit_permission(): void
    {
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => in_array($route->uri(), [
                'api/v1/mobile/schedule/daily-plan-assignments/{assignment}/fact',
                'api/v1/mobile/schedule/daily-plans/{dailyPlan}/submit',
                'api/v1/mobile/schedule/{schedule_id}/tasks',
                'api/v1/mobile/schedule/tasks/{task}',
                'api/v1/mobile/warehouse/operations/write-off',
            ], true)
        ));

        $this->assertCount(6, $routes);

        foreach ($routes as $route) {
            $expectedPermission = match (true) {
                str_contains($route->uri(), 'daily-plan') => 'authorize:schedule.daily_plan.manage',
                str_contains($route->uri(), 'warehouse') => 'authorize:warehouse.manage_stock',
                $route->methods()[0] === 'GET' => 'authorize:schedule.view',
                default => 'authorize:schedule.edit',
            };
            $this->assertContains($expectedPermission, $route->gatherMiddleware(), $route->uri());
        }
    }
}
