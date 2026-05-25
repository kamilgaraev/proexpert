<?php

declare(strict_types=1);

namespace Tests\Unit\RoleDefinitions;

use PHPUnit\Framework\TestCase;

class LkBillingPermissionsTest extends TestCase
{
    public function test_owner_has_billing_view_and_manage_permissions(): void
    {
        $role = $this->loadRole('organization_owner');

        $this->assertContains('billing.view', $role['system_permissions']);
        $this->assertContains('billing.manage', $role['system_permissions']);
    }

    public function test_accountant_has_billing_view_without_subscription_management(): void
    {
        $role = $this->loadRole('accountant');

        $this->assertContains('billing.view', $role['system_permissions']);
        $this->assertNotContains('billing.manage', $role['system_permissions']);
    }

    private function loadRole(string $slug): array
    {
        $path = dirname(__DIR__, 3) . "/config/RoleDefinitions/lk/{$slug}.json";
        $json = file_get_contents($path);

        $this->assertIsString($json);

        $role = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($role);

        return $role;
    }
}
