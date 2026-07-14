<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use PHPUnit\Framework\TestCase;

class ModulesOverviewControllerTest extends TestCase
{
    public function test_overview_uses_new_package_read_model_without_legacy_plan_fields(): void
    {
        $path = dirname(__DIR__, 5).'/app/Services/Landing/ModulesOverviewService.php';
        $service = file_get_contents($path);

        $this->assertIsString($service);
        $this->assertStringContainsString('PackageService $packageService', $service);
        $this->assertStringNotContainsString('OrganizationSubscription', $service);
        $this->assertStringNotContainsString('current_tier', $service);
        $this->assertStringNotContainsString('is_bundled_with_plan', $service);
        $this->assertStringNotContainsString('included_packages', $service);
    }
}
