<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Mobile\MobileFieldAdminService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

final class FieldAdminRouteAuthorizationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_field_admin_routes_require_their_module_permissions(): void
    {
        $expectedUris = [
            'api/v1/mobile/field-admin/team/projects/{project}/participants',
            'api/v1/mobile/field-admin/team/projects/{project}/participants/available-users',
            'api/v1/mobile/field-admin/team/projects/{project}/participants/{user}',
            'api/v1/mobile/field-admin/personnel/employees',
            'api/v1/mobile/field-admin/personnel/employees/{employee}',
            'api/v1/mobile/field-admin/personnel/absences',
            'api/v1/mobile/field-admin/personnel/orders',
            'api/v1/mobile/field-admin/personnel/attendance',
            'api/v1/mobile/field-admin/personnel/calendar',
        ];
        $routes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => in_array($route->uri(), $expectedUris, true)
        ));

        $this->assertCount(count($expectedUris), $routes);
        foreach ($routes as $route) {
            $expectedPermission = str_contains($route->uri(), '/team/')
                ? (str_contains($route->uri(), 'available-users') || $route->methods()[0] === 'PUT'
                    ? 'authorize:projects.participants.assign'
                    : 'authorize:projects.view')
                : 'authorize:workforce.view';
            $this->assertContains($expectedPermission, $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_projects_edit_alone_does_not_allow_mobile_participant_assignment(): void
    {
        $actor = new User();
        $actor->id = 55;

        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')
            ->once()
            ->with($actor, 'projects.participants.assign', [
                'organization_id' => 12,
                'project_id' => 34,
                'strict_project_scope' => true,
            ])
            ->andReturn(false);
        $authorization->shouldNotReceive('can')
            ->with($actor, 'projects.edit', Mockery::any());
        $this->app->instance(AuthorizationService::class, $authorization);

        $this->expectException(AuthorizationException::class);
        app(MobileFieldAdminService::class)->bindProjectUser($actor, 12, 34, 89);
    }

    public function test_attendance_requires_workforce_view_on_selected_project(): void
    {
        $actor = new User();
        $actor->id = 55;

        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')
            ->once()
            ->with($actor, 'workforce.view', [
                'organization_id' => 12,
                'project_id' => 34,
                'strict_project_scope' => true,
            ])
            ->andReturn(false);
        $this->app->instance(AuthorizationService::class, $authorization);

        $this->expectException(AuthorizationException::class);
        app(MobileFieldAdminService::class)->attendance($actor, 12, [
            'project_id' => 34,
            'work_date' => '2026-09-23',
        ]);
    }
}
