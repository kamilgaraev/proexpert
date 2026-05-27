<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Security\SystemAdminRoleService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Tests\TestCase;

class SystemAdminPanelAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SystemAdminRoleService::class)->clearCache();
    }

    protected function tearDown(): void
    {
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_active_super_admin_can_access_admin_panel(): void
    {
        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->assertTrue($admin->canAccessPanel($this->adminPanel()));
    }

    public function test_inactive_super_admin_cannot_access_admin_panel(): void
    {
        $admin = SystemAdmin::factory()->inactive()->create([
            'role' => 'super_admin',
        ]);

        $this->assertFalse($admin->canAccessPanel($this->adminPanel()));
    }

    public function test_unknown_system_admin_role_cannot_access_admin_panel(): void
    {
        $admin = SystemAdmin::factory()->role('unknown_role')->create();

        $this->assertFalse($admin->canAccessPanel($this->adminPanel()));
    }

    public function test_application_user_is_separate_from_system_admin_panel_user(): void
    {
        $user = User::factory()->create();

        $this->assertNotInstanceOf(SystemAdmin::class, $user);
        $this->assertNotInstanceOf(FilamentUser::class, $user);
        $this->assertFalse(method_exists($user, 'canAccessPanel'));
    }

    private function adminPanel(): Panel
    {
        return Panel::make()->id('admin');
    }
}

