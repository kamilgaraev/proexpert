<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class FilamentPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SystemAdminRoleService::class)->clearCache();
    }

    protected function tearDown(): void
    {
        Auth::guard('system_admin')->logout();
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_permission_registry_values_are_unique_system_admin_permissions(): void
    {
        $permissions = FilamentPermission::all();

        $this->assertNotEmpty($permissions);
        $this->assertCount(count(array_unique($permissions)), $permissions);

        foreach ($permissions as $permission) {
            $this->assertStringStartsWith('system_admin.', $permission);
        }
    }

    public function test_owner_role_can_resolve_every_registered_permission(): void
    {
        $admin = new SystemAdmin([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        foreach (FilamentPermission::all() as $permission) {
            $this->assertTrue(
                $admin->hasSystemPermission($permission),
                "super_admin must allow {$permission}",
            );
        }
    }

    public function test_system_admin_access_returns_current_system_admin_user(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        $this->assertTrue(SystemAdminAccess::user()->is($admin));
    }

    public function test_system_admin_access_rejects_non_system_admin_guard_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->assertNull(SystemAdminAccess::user());
        $this->assertFalse(SystemAdminAccess::can(FilamentPermission::DASHBOARD_VIEW));
    }

    public function test_system_admin_access_checks_single_any_and_all_permissions(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'content_manager',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        $this->assertTrue(SystemAdminAccess::can(FilamentPermission::BLOG_ARTICLES_VIEW));
        $this->assertFalse(SystemAdminAccess::can(FilamentPermission::BILLING_REVENUE_VIEW));
        $this->assertTrue(SystemAdminAccess::canAny([
            FilamentPermission::BILLING_REVENUE_VIEW,
            FilamentPermission::BLOG_ARTICLES_VIEW,
        ]));
        $this->assertFalse(SystemAdminAccess::canAll([
            FilamentPermission::BLOG_ARTICLES_VIEW,
            FilamentPermission::BILLING_REVENUE_VIEW,
        ]));
        $this->assertTrue(SystemAdminAccess::canAll([
            FilamentPermission::BLOG_ARTICLES_VIEW,
            FilamentPermission::BLOG_MEDIA_VIEW,
        ]));
    }

    public function test_system_admin_access_denies_empty_permission_sets(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        $this->assertFalse(SystemAdminAccess::canAny([]));
        $this->assertFalse(SystemAdminAccess::canAll([]));
    }
}

