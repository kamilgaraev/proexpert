<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileSystemRoutesTest extends TestCase
{
    public function test_system_routes_use_mobile_auth_and_distinct_read_and_decision_permissions(): void
    {
        $status = Route::getRoutes()->getByName('api.v1.mobile.system.one-c-exchange.status');
        $history = Route::getRoutes()->getByName('api.v1.mobile.system.one-c-exchange.history');
        $retry = Route::getRoutes()->getByName('api.v1.mobile.system.one-c-exchange.journal.retry');
        $reviews = Route::getRoutes()->getByName('api.v1.mobile.system.access-recertification.reviews.my');
        $decision = Route::getRoutes()->getByName('api.v1.mobile.system.access-recertification.items.decide');
        $rates = Route::getRoutes()->getByName('api.v1.mobile.system.rate-coefficients.current');
        $events = Route::getRoutes()->getByName('api.v1.mobile.system.events.index');

        foreach ([$status, $history, $retry, $reviews, $decision, $rates, $events] as $route) {
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:api_mobile', $middleware);
            $this->assertContains('auth.jwt:api_mobile', $middleware);
            $this->assertContains('organization.context', $middleware);
            $this->assertContains('can:access-mobile-app', $middleware);
        }

        $this->assertContains('authorize:one_c_exchange.view', $status->gatherMiddleware());
        $this->assertContains('authorize:one_c_exchange.history.view', $history->gatherMiddleware());
        $this->assertContains('authorize:one_c_exchange.retry', $retry->gatherMiddleware());
        $this->assertContains('POST', $retry->methods());
        $this->assertContains('authorize:access_recertification.reviews.view', $reviews->gatherMiddleware());
        $this->assertContains('authorize:access_recertification.reviews.decide', $decision->gatherMiddleware());
        $this->assertContains('POST', $decision->methods());
        $this->assertContains('authorize:rate_coefficients.view', $rates->gatherMiddleware());
        $this->assertContains('authorize:system-logs.system.view', $events->gatherMiddleware());
    }
}
