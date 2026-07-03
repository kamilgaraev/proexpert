<?php

declare(strict_types=1);

namespace Tests\Unit\RoleDefinitions;

use App\Helpers\AdminPanelAccessHelper;
use Tests\TestCase;

class AdminPanelAccessHelperTest extends TestCase
{
    public function test_assignable_admin_panel_roles_are_limited_to_real_admin_roles(): void
    {
        $roles = app(AdminPanelAccessHelper::class)->getAdminPanelRoles(null, 'lk', true);

        $this->assertContains('supplier', $roles);
        $this->assertContains('organization_admin', $roles);
        $this->assertNotContains('web_admin', $roles);
        $this->assertNotContains('viewer', $roles);
        $this->assertNotContains('worker', $roles);
        $this->assertNotContains('observer', $roles);
        $this->assertNotContains('project_viewer', $roles);
        $this->assertNotContains('brigade_manager', $roles);
        $this->assertNotContains('brigade_representative', $roles);
    }

    public function test_admin_panel_access_requires_admin_interface_or_permission(): void
    {
        $helper = app(AdminPanelAccessHelper::class);

        $this->assertTrue($helper->canRoleAccessAdminPanel('supplier'));
        $this->assertTrue($helper->canRoleAccessAdminPanel('web_admin'));
        $this->assertFalse($helper->canRoleAccessAdminPanel('viewer'));
        $this->assertFalse($helper->canRoleAccessAdminPanel('worker'));
    }

    public function test_mobile_interface_cannot_create_admin_panel_roles(): void
    {
        $roles = app(AdminPanelAccessHelper::class)->getAdminPanelRoles(null, 'mobile', true);

        $this->assertSame([], $roles);
    }
}
