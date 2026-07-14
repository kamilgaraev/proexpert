<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use PHPUnit\Framework\TestCase;

class OrganizationPackageControllerTest extends TestCase
{
    public function test_package_api_is_read_only_until_direct_checkout_is_available(): void
    {
        $routes = $this->read('routes/api/v1/landing/modules.php');
        $controller = $this->read('app/Http/Controllers/Api/V1/Landing/OrganizationPackageController.php');
        $service = $this->read('app/Services/Landing/PackageService.php');

        $this->assertStringContainsString("Route::get('/', [OrganizationPackageController::class, 'index'])", $routes);
        $this->assertStringNotContainsString('packages/subscribe', $routes);
        $this->assertStringNotContainsString("'subscribe'", $routes);
        $this->assertStringNotContainsString('function subscribe', $controller);
        $this->assertStringNotContainsString('function unsubscribe', $controller);
        $this->assertStringNotContainsString('debitBalance', $service);
        $this->assertStringNotContainsString('OrganizationModuleActivation', $service);
    }

    public function test_legacy_plan_and_enterprise_routes_are_absent(): void
    {
        $routes = $this->read('routes/api/v1/landing/billing.php');

        $this->assertStringNotContainsString('SubscriptionPlanController', $routes);
        $this->assertStringNotContainsString('OrganizationSubscriptionController', $routes);
        $this->assertStringNotContainsString('EnterpriseConstructorController', $routes);
        $this->assertStringNotContainsString('subscribe', $routes);
        $this->assertStringNotContainsString('change-plan', $routes);
        $this->assertStringNotContainsString('checkout', $routes);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 5).'/'.$relativePath);
        $this->assertIsString($contents);

        return $contents;
    }
}
