<?php

declare(strict_types=1);

namespace Tests\Unit\KnowledgeHub;

use App\Http\Controllers\Api\V1\KnowledgeAssistantController;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Tests\Support\DatabaseLessTestCase;

final class KnowledgeAssistantRoutesTest extends DatabaseLessTestCase
{
    public function test_each_interface_has_an_authenticated_rate_limited_assistant_route(): void
    {
        $router = $this->app->make(Router::class);

        foreach (['admin' => 'api_admin', 'landing' => 'api_landing', 'mobile' => 'api_mobile'] as $surface => $guard) {
            $route = $router->getRoutes()->match(Request::create('/api/v1/'.$surface.'/knowledge-hub/assistant', 'POST'));

            self::assertSame(KnowledgeAssistantController::class.'@'.$surface, $route->getActionName());
            self::assertContains('auth:'.$guard, $route->middleware());
            self::assertContains('auth.jwt:'.$guard, $route->middleware());
            self::assertContains('organization.context', $route->middleware());
            self::assertContains('throttle:10,1', $route->middleware());
        }
    }
}
