<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

class AdminResponseWiringTest extends TestCase
{
    public function test_admin_response_alias_is_registered(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 3) . '/bootstrap/app.php');

        $this->assertIsString($bootstrap);
        $this->assertStringContainsString(
            "'admin.response' => \\App\\Http\\Middleware\\NormalizeAdminResponse::class",
            $bootstrap
        );
    }

    public function test_admin_route_group_uses_admin_response_middleware(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3) . '/routes/api.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString(
            "Route::prefix('v1/admin')->middleware('admin.response')->name('admin.')->group(function () {",
            $routes
        );
    }
}
