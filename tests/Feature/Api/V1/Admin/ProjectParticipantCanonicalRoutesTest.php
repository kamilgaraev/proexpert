<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProjectParticipantCanonicalRoutesTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_no_dead_put_participant_route_without_controller_action(): void
    {
        $dead = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => $route->uri() === 'api/v1/admin/projects/{project}/participants/{organization}'
                && in_array('PUT', $route->methods(), true)
        ));

        self::assertSame([], $dead, 'PUT participants/{organization} ведёт в несуществующий метод update');
    }

    public function test_activity_changes_use_post_only(): void
    {
        foreach (['activate', 'deactivate'] as $action) {
            $uri = "api/v1/admin/projects/{project}/participants/{organization}/{$action}";
            $methods = [];

            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->uri() === $uri) {
                    $methods = array_values(array_diff($route->methods(), ['HEAD']));
                }
            }

            self::assertSame(['POST'], $methods, $uri);
        }
    }
}
