<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Tests\Support\DatabaseLessTestCase;

final class AssistantApiRoutesTest extends DatabaseLessTestCase
{
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
            $this->getJson('/api/v1/ai-assistant/'.$endpoint)->assertUnauthorized();
        }
    }
}
