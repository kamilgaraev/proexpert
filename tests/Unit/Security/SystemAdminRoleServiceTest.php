<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Models\SystemAdmin;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemAdminRoleServiceTest extends TestCase
{
    private SystemAdminRoleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SystemAdminRoleService::class);
        $this->service->clearCache();
    }

    protected function tearDown(): void
    {
        $this->service->clearCache();

        parent::tearDown();
    }

    public function test_loads_roles_from_system_admin_context(): void
    {
        $roles = $this->service->getAllRoles();

        $this->assertTrue($roles->has('super_admin'));
        $this->assertTrue($roles->has('content_manager'));
        $this->assertTrue($roles->has('qa_engineer'));
        $this->assertTrue($roles->has('security_auditor'));
        $this->assertFalse($roles->has('admin_viewer'));
    }

    public function test_empty_role_resolves_to_super_admin(): void
    {
        $admin = new SystemAdmin([
            'role' => '',
        ]);

        $this->assertSame('super_admin', $this->service->resolveRoleSlug($admin));
    }

    public function test_super_admin_wildcard_grants_any_permission(): void
    {
        $admin = new SystemAdmin([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->hasPermission($admin, 'system_admin.anything.view'));
    }

    public function test_prefix_wildcard_grants_nested_permissions_only(): void
    {
        Cache::put('system_admin_roles', collect([
            'blog_prefix_role' => [
                'slug' => 'blog_prefix_role',
                'interface_access' => ['admin'],
                'system_permissions' => [
                    'system_admin.blog.*',
                ],
                'module_permissions' => [],
            ],
        ]), 3600);

        $admin = new SystemAdmin([
            'role' => 'blog_prefix_role',
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->hasPermission($admin, 'system_admin.blog.articles.view'));
        $this->assertTrue($this->service->canAccessInterface($admin, 'admin'));
        $this->assertFalse($this->service->hasPermission($admin, 'system_admin.billing.revenue.view'));
    }

    public function test_unknown_permission_returns_false_for_content_manager(): void
    {
        $admin = new SystemAdmin([
            'role' => 'content_manager',
            'is_active' => true,
        ]);

        $this->assertFalse($this->service->hasPermission($admin, 'system_admin.billing.revenue.view'));
    }

    public function test_unknown_role_has_no_permissions_and_no_interface_access(): void
    {
        $admin = new SystemAdmin([
            'role' => 'unknown_role',
            'is_active' => true,
        ]);

        $this->assertFalse($this->service->hasPermission($admin, 'system_admin.access'));
        $this->assertFalse($this->service->canAccessInterface($admin, 'admin'));
    }

    public function test_malformed_role_definition_file_is_ignored(): void
    {
        $path = config_path('RoleDefinitions/system_admin/__malformed_test_role.json');

        File::put($path, '{"slug": ');
        $this->service->clearCache();

        try {
            $roles = $this->service->getAllRoles();

            $this->assertFalse($roles->has('__malformed_test_role'));
            $this->assertTrue($roles->has('super_admin'));
        } finally {
            File::delete($path);
            $this->service->clearCache();
        }
    }

    public function test_inactive_admin_has_no_permissions(): void
    {
        $admin = new SystemAdmin([
            'role' => 'super_admin',
            'is_active' => false,
        ]);

        $this->assertFalse($this->service->hasPermission($admin, 'system_admin.access'));
    }
}
