<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\RolePermissionNormalizer;
use PHPUnit\Framework\TestCase;

class RolePermissionNormalizerTest extends TestCase
{
    public function test_admin_interface_adds_required_admin_permissions(): void
    {
        $permissions = RolePermissionNormalizer::normalizeSystemPermissions(
            ['profile.view'],
            ['admin']
        );

        $this->assertContains('profile.view', $permissions);
        $this->assertContains('admin.access', $permissions);
        $this->assertContains('admin.view', $permissions);
        $this->assertContains('dashboard.view', $permissions);
    }

    public function test_admin_gate_permissions_are_removed_when_admin_interface_is_removed(): void
    {
        $permissions = RolePermissionNormalizer::normalizeSystemPermissions(
            ['profile.view', 'admin.access', 'admin.view', 'dashboard.view'],
            ['lk']
        );

        $this->assertContains('profile.view', $permissions);
        $this->assertContains('dashboard.view', $permissions);
        $this->assertNotContains('admin.access', $permissions);
        $this->assertNotContains('admin.view', $permissions);
    }
}
