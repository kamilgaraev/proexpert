<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use PHPUnit\Framework\TestCase;

class SubscriptionPackageBundlingTest extends TestCase
{
    public function test_plan_module_sync_cannot_materialize_commercial_packages(): void
    {
        $root = dirname(__DIR__, 3);
        $service = file_get_contents($root.'/app/Services/SubscriptionModuleSyncService.php');
        $model = file_get_contents($root.'/app/Models/OrganizationSubscription.php');

        $this->assertIsString($service);
        $this->assertIsString($model);
        $this->assertStringNotContainsString('OrganizationPackageSubscription', $service);
        $this->assertStringNotContainsString('included_packages', $service);
        $this->assertStringNotContainsString('bundledPackages', $model);
        $this->assertStringNotContainsString('syncPackagesExpiration', $model);
        $this->assertStringNotContainsString('expireBundledPackages', $model);
    }
}
