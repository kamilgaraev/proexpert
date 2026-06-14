<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

class HorizonDashboardConfigurationTest extends LaravelTestCase
{
    public function test_horizon_dashboard_uses_web_middleware_without_removed_api_guard(): void
    {
        $middleware = config('horizon.middleware');

        $this->assertSame(['web'], $middleware);
        $this->assertNotContains('auth:api', $middleware);
        $this->assertNotContains('authorize:notifications.view_analytics', $middleware);
    }
}
