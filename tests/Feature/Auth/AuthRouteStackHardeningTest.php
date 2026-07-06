<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\JwtMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

final class AuthRouteStackHardeningTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_public_auth_entrypoints_use_auth_throttle(): void
    {
        foreach ([
            'api/v1/landing/auth/register',
            'api/v1/landing/auth/login',
            'api/v1/landing/auth/password/email',
            'api/v1/landing/auth/password/reset',
            'api/v1/landing/landingAdminAuth/login',
            'api/v1/brigades/auth/register',
            'api/v1/brigades/auth/login',
            'api/v1/admin/auth/login',
            'api/v1/mobile/auth/login',
            'api/v1/customer/auth/register',
            'api/v1/customer/auth/login',
            'api/v1/customer/auth/forgot-password',
            'api/v1/customer/auth/reset-password',
            'api/v1/customer/auth/email/resend',
        ] as $uri) {
            $route = $this->routeByUri($uri);

            $this->assertNotNull($route, "Route {$uri} is missing.");
            $this->assertContains('throttle:auth', $route->gatherMiddleware(), "{$uri} must use throttle:auth.");
        }
    }

    public function test_refresh_routes_use_jwt_session_and_dashboard_throttle_without_auth_guard(): void
    {
        foreach ([
            'api/v1/landing/auth/refresh' => [
                'jwt' => 'auth.jwt:api_landing',
                'authGuard' => 'auth:api_landing',
            ],
            'api/v1/customer/auth/refresh' => [
                'jwt' => 'auth.jwt:api_landing',
                'authGuard' => 'auth:api_landing',
            ],
            'api/v1/admin/auth/refresh' => [
                'jwt' => 'auth.jwt:api_admin',
                'authGuard' => 'auth:api_admin',
            ],
            'api/v1/mobile/auth/refresh' => [
                'jwt' => 'auth.jwt:api_mobile',
                'authGuard' => 'auth:api_mobile',
            ],
            'api/v1/landing/landingAdminAuth/refresh' => [
                'jwt' => 'auth.jwt:api_landing_admin',
                'authGuard' => 'auth:api_landing_admin',
            ],
        ] as $uri => $expectedMiddleware) {
            $route = $this->routeByUri($uri);

            $this->assertNotNull($route, "Route {$uri} is missing.");
            $middleware = $route->gatherMiddleware();

            $this->assertContains($expectedMiddleware['jwt'], $middleware, "{$uri} must parse JWT.");
            $this->assertContains('auth.session', $middleware, "{$uri} must enforce active auth session.");
            $this->assertContains('throttle:dashboard', $middleware, "{$uri} must use dashboard throttle.");
            $this->assertNotContains(
                $expectedMiddleware['authGuard'],
                $middleware,
                "{$uri} must not run the auth guard before JWT refresh handling."
            );
        }
    }

    public function test_jwt_middleware_recognizes_all_refresh_endpoints(): void
    {
        $middleware = new JwtMiddleware();
        $method = new ReflectionMethod(JwtMiddleware::class, 'isRefreshEndpoint');
        $method->setAccessible(true);

        foreach ([
            'api/v1/landing/auth/refresh',
            'api/v1/customer/auth/refresh',
            'api/v1/admin/auth/refresh',
            'api/v1/mobile/auth/refresh',
            'api/v1/landing/landingAdminAuth/refresh',
        ] as $uri) {
            $request = Request::create($uri, 'POST');

            $this->assertTrue($method->invoke($middleware, $request), "{$uri} must be treated as refresh endpoint.");
        }
    }

    public function test_brigade_protected_routes_use_jwt_and_session_middleware(): void
    {
        foreach ($this->routesStartingWith('api/v1/brigades') as $route) {
            $uri = $route->uri();

            if (in_array($uri, ['api/v1/brigades/auth/register', 'api/v1/brigades/auth/login'], true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:api_brigade', $middleware, "{$uri} must authenticate brigade user.");
            $this->assertContains('auth.jwt:api_brigade', $middleware, "{$uri} must parse JWT.");
            $this->assertContains('auth.session', $middleware, "{$uri} must enforce active auth session.");
        }
    }

    public function test_landing_business_routes_require_verified_email(): void
    {
        foreach ([
            'api/v1/landing/dashboard',
            'api/v1/landing/organization',
            'api/v1/landing/billing/plans',
            'api/v1/landing/modules',
        ] as $uri) {
            $route = $this->routeByUri($uri);

            $this->assertNotNull($route, "Route {$uri} is missing.");
            $this->assertContains('verified', $route->gatherMiddleware(), "{$uri} must require verified email.");
        }
    }

    public function test_rate_limiters_are_not_left_in_load_test_mode(): void
    {
        $provider = new \App\Providers\RouteServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'configureRateLimiting');
        $method->setAccessible(true);
        $method->invoke($provider);

        $apiLimits = RateLimiter::limiter('api')(Request::create('/api/test'));
        $dashboardLimits = RateLimiter::limiter('dashboard')(Request::create('/api/test'));

        $apiMaxAttempts = collect(is_array($apiLimits) ? $apiLimits : [$apiLimits])
            ->map(fn ($limit) => $limit->maxAttempts)
            ->all();
        $dashboardMaxAttempts = collect(is_array($dashboardLimits) ? $dashboardLimits : [$dashboardLimits])
            ->map(fn ($limit) => $limit->maxAttempts)
            ->all();

        $this->assertNotContains(100000, $apiMaxAttempts);
        $this->assertNotContains(50000, $apiMaxAttempts);
        $this->assertNotContains(100000, $dashboardMaxAttempts);
        $this->assertNotContains(50000, $dashboardMaxAttempts);
    }

    public function test_api_routes_moved_from_route_service_provider_stay_registered(): void
    {
        foreach ([
            'api/public/contact',
            'api/v1/holding-api/{slug}',
            'api/v1/blog/articles',
            'api/v1/landing/holding/public/site-data',
            'api/v1/admin/error-tracking',
            'api/v1/admin/estimates/normative-rates',
        ] as $uri) {
            $this->assertNotNull($this->routeByUri($uri), "Route {$uri} is missing.");
        }
    }

    public function test_api_v1_routes_do_not_have_duplicate_method_uri_definitions(): void
    {
        $duplicates = [];

        foreach ($this->routesStartingWith('api/v1') as $route) {
            $key = implode('|', $route->methods()).' '.$route->uri();
            $duplicates[$key] = ($duplicates[$key] ?? 0) + 1;
        }

        $duplicates = array_filter($duplicates, static fn (int $count): bool => $count > 1);

        $this->assertSame([], $duplicates);
    }

    private function routeByUri(string $uri): ?LaravelRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @return list<LaravelRoute>
     */
    private function routesStartingWith(string $prefix): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), $prefix)
        ));
    }
}
