<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationController;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class NotificationRouteContractTest extends TestCase
{
    public function test_landing_and_customer_routes_expose_all_target_scoped_operations(): void
    {
        $operations = [
            'index' => ['GET', 'notifications'],
            'getUnreadCount' => ['GET', 'notifications/unread-count'],
            'unread' => ['GET', 'notifications/unread'],
            'markAllAsRead' => ['POST', 'notifications/mark-all-read'],
            'show' => ['GET', 'notifications/{id}'],
            'markAsRead' => ['PATCH', 'notifications/{id}/read'],
            'markAsUnread' => ['PATCH', 'notifications/{id}/unread'],
            'destroy' => ['DELETE', 'notifications/{id}'],
        ];

        foreach (['api/v1/landing', 'api/v1/customer', 'api/customer'] as $prefix) {
            foreach ($operations as $action => [$method, $suffix]) {
                $route = $this->route($method, $prefix.'/'.$suffix);

                self::assertSame(NotificationController::class.'@'.$action, $route->getActionName());
            }
        }
    }

    public function test_both_customer_aliases_retain_authentication_and_organization_context(): void
    {
        foreach (['api/v1/customer', 'api/customer'] as $prefix) {
            $route = $this->route('GET', $prefix.'/notifications');
            $middleware = $route->gatherMiddleware();

            self::assertContains('auth:api_landing', $middleware);
            self::assertContains('auth.jwt:api_landing', $middleware);
            self::assertContains('verified', $middleware);
            self::assertContains('organization.context', $middleware);
        }
    }

    private function route(string $method, string $uri): LaravelRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        self::fail("Route {$method} {$uri} is not registered.");
    }
}
