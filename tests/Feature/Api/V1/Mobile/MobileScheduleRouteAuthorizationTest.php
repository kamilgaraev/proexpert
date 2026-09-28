<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileScheduleRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_mobile_schedule_routes_authorize_with_project_scope_and_resource_keys(): void
    {
        $expectations = [
            'GET api/v1/mobile/schedule' => 'mobile.project-authorize:schedule.view,project_id',
            'GET api/v1/mobile/schedule/{scheduleId}' => 'mobile.project-authorize:schedule.view,project_schedule,scheduleId',
            'GET api/v1/mobile/schedule/tasks/{task}' => 'mobile.project-authorize:schedule.view,schedule_task,task',
            'POST api/v1/mobile/schedule/{schedule_id}/tasks' => 'mobile.project-authorize:schedule.edit,project_schedule,schedule_id',
            'PATCH api/v1/mobile/schedule/tasks/{task}' => 'mobile.project-authorize:schedule.edit,schedule_task,task',
            'GET api/v1/mobile/schedule/daily-plans' => 'mobile.project-authorize:schedule.view,project_id',
            'PATCH api/v1/mobile/schedule/daily-plan-assignments/{assignment}/fact' => 'mobile.project-authorize:schedule.daily_plan.manage,daily_plan_assignment,assignment',
            'POST api/v1/mobile/schedule/daily-plans/{dailyPlan}/submit' => 'mobile.project-authorize:schedule.daily_plan.manage,daily_plan,dailyPlan',
            'POST api/v1/mobile/schedule/work-constraints/{constraint}/linked-action' => 'mobile.project-authorize:schedule.daily_plan.manage,work_constraint,constraint',
        ];

        $routes = Route::getRoutes()->getRoutes();

        foreach ($expectations as $routeKey => $expectedMiddleware) {
            [$method, $uri] = explode(' ', $routeKey, 2);
            $route = collect($routes)->first(
                static fn (LaravelRoute $candidate): bool => $candidate->uri() === $uri
                    && in_array($method, $candidate->methods(), true)
            );

            $this->assertNotNull($route, $routeKey);
            $this->assertContains($expectedMiddleware, $route->gatherMiddleware(), $routeKey);
        }
    }
}
