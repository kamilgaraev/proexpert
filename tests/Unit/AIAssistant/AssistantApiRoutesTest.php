<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Http\Middleware\UseJwtCookieForAuthorization;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Models\User;
use App\Services\Auth\JwtCookieService;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Tests\Support\DatabaseLessTestCase;

final class AssistantApiRoutesTest extends DatabaseLessTestCase
{
    public function test_lk_assistant_requests_are_validated_by_the_web_authentication_middleware(): void
    {
        $middleware = $this->app->make(WebInterfaceSecurityMiddleware::class);

        foreach (['rag/status', 'conversations', 'memory', 'credits/balance', 'usage'] as $endpoint) {
            $request = Request::create('/api/v1/ai-assistant/'.$endpoint, 'GET');
            $request->headers->set('Authorization', 'Bearer invalid-web-token');

            $response = $middleware->handle($request, fn () => response('unprotected'));

            self::assertSame(401, $response->getStatusCode());
        }
    }

    public function test_lk_assistant_rejects_a_valid_admin_token(): void
    {
        config([
            'cache.default' => 'array',
            'web_auth.keys.lk' => str_repeat('l', 64),
            'web_auth.keys.admin' => str_repeat('a', 64),
        ]);
        $user = new User;
        $user->id = 10;
        $tokens = $this->app->make(WebAuthTokenService::class);
        $lkToken = $tokens->issue($user, 'lk', (string) Str::uuid(), 1, false);
        self::assertSame('lk', $tokens->parse($lkToken->accessToken, 'lk', 'access')->audience);
        $adminToken = $tokens->issue($user, 'admin', (string) Str::uuid(), 1, false);
        $this->getJson('/api/v1/ai-assistant/usage', [
            'Authorization' => 'Bearer '.$adminToken->accessToken,
        ])->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'http_401');
    }

    public function test_lk_assistant_does_not_promote_a_legacy_cookie_to_bearer_authentication(): void
    {
        $middleware = new UseJwtCookieForAuthorization(new JwtCookieService);
        $request = Request::create('/api/v1/ai-assistant/usage', 'GET');
        $request->cookies->set('prohelper_landing_token', 'legacy-browser-token');

        $middleware->handle($request, function (Request $handledRequest) {
            self::assertNull($handledRequest->bearerToken());

            return response('ok');
        });
    }

    public function test_assistant_routes_use_the_configured_guard_for_each_interface(): void
    {
        $router = $this->app->make(Router::class);

        foreach ([
            'api/v1/ai-assistant' => 'api_landing',
            'api/v1/admin/ai-assistant' => 'api_admin',
            'api/v1/mobile/ai-assistant' => 'api_mobile',
        ] as $prefix => $guard) {
            foreach (['rag/status', 'conversations', 'memory', 'credits/balance', 'usage'] as $endpoint) {
                $route = $router->getRoutes()->match(Request::create('/'.$prefix.'/'.$endpoint, 'GET'));

                self::assertSame('jwt', config('auth.guards.'.$guard.'.driver'));
                self::assertContains('auth:'.$guard, $route->middleware());
                self::assertContains('auth.jwt:'.$guard, $route->middleware());
                self::assertContains('organization.context', $route->middleware());
                self::assertNotContains('auth:api', $route->middleware());
            }
        }
    }

    public function test_lk_assistant_endpoints_require_authentication_instead_of_returning_server_errors(): void
    {
        foreach (['rag/status', 'conversations', 'memory', 'credits/balance', 'usage'] as $endpoint) {
            $this->getJson('/api/v1/ai-assistant/'.$endpoint)
                ->assertUnauthorized()
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'http_401');
        }
    }
}
