<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_child_organization_user_service_binding_passes_role_scanner(): void
    {
        $provider = file_get_contents(
            dirname(__DIR__, 3) . '/app/Providers/AppServiceProvider.php'
        );

        $this->assertIsString($provider);
        $this->assertStringContainsString(
            '$app->make(\App\Domain\Authorization\Services\RoleScanner::class)',
            $provider
        );
    }
}
