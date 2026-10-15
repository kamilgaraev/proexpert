<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Models\Module;
use App\Models\Organization;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Landing\ChildOrganizationUserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class HoldingChildRoleSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $modules = collect([new Module(['slug' => 'multi-organization'])]);
        foreach (['project-management' => 'projects.view', 'contract-management' => 'contracts.view',
            'catalog-management' => 'materials.view', 'reports' => 'reports.view'] as $slug => $permission) {
            $module = Module::query()->updateOrCreate(['slug' => $slug], [
                'name' => $slug, 'version' => '1.0.0', 'type' => 'feature', 'billing_model' => 'free',
                'permissions' => [$permission], 'is_active' => true,
            ]);
            $modules->push($module);
        }
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn($modules);
        $this->app->instance(OrganizationEntitlementService::class, $entitlements);
    }

    public function test_parent_owner_without_child_membership_can_create_direct_and_template_roles(): void
    {
        $parent = AdminApiTestContext::create(organizationAttributes: ['is_holding' => true], roleSlug: 'organization_owner');
        $child = $this->child($parent->organization);
        self::assertFalse($parent->user->organizations()->whereKey($child->id)->exists());

        foreach ([['is_custom' => true, 'name' => 'Child custom role', 'permissions' => ['projects.view']],
            ['is_custom' => true, 'template' => 'observer']] as $index => $roleData) {
            $result = app(ChildOrganizationUserService::class)->createUserWithRoleForParent(
                $parent->organization->id, $child->id,
                ['name' => 'Child member', 'email' => 'child-role-'.$index.'@example.test', 'role_data' => $roleData],
                $parent->user
            );
            $role = OrganizationCustomRole::query()->where('organization_id', $child->id)->latest('id')->firstOrFail();
            self::assertSame($parent->user->id, $role->created_by);
            self::assertSame(['projects.view'], $role->module_permissions['project_management']);
            if ($index === 1) {
                self::assertSame(['contracts.view'], $role->module_permissions['contract_management']);
                self::assertSame(['materials.view'], $role->module_permissions['catalog_management']);
                self::assertSame(['reports.view'], $role->module_permissions['reports']);
            }
            $this->assertDatabaseHas('user_role_assignments', [
                'user_id' => $result['user']['id'], 'custom_role_id' => $role->id,
                'role_type' => UserRoleAssignment::TYPE_CUSTOM, 'is_active' => true,
            ]);
        }
        self::assertFalse($parent->user->organizations()->whereKey($child->id)->exists());
    }

    public function test_parent_member_without_child_management_permission_is_denied(): void
    {
        $parent = AdminApiTestContext::create(organizationAttributes: ['is_holding' => true], roleSlug: 'organization_user');
        $child = $this->child($parent->organization);
        $this->expectException(AuthorizationException::class);
        app(CustomRoleService::class)->createRoleForChildOrganization($child->id, 'Denied', [], null, $parent->user);
    }

    public function test_parent_owner_cannot_create_a_role_in_another_holdings_child(): void
    {
        $parent = AdminApiTestContext::create(organizationAttributes: ['is_holding' => true], roleSlug: 'organization_owner');
        $child = $this->child(Organization::factory()->create(['is_holding' => true]));
        $this->expectException(AuthorizationException::class);
        app(CustomRoleService::class)->createRoleForChildOrganization($child->id, 'Foreign', [], null, $parent->user);
    }

    public function test_inactive_parent_membership_cannot_create_child_roles(): void
    {
        $parent = AdminApiTestContext::create(organizationAttributes: ['is_holding' => true], roleSlug: 'organization_owner');
        $child = $this->child($parent->organization);
        $parent->organization->users()->updateExistingPivot($parent->user->id, ['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        app(CustomRoleService::class)->createRoleForChildOrganization($child->id, 'Inactive', [], null, $parent->user);
    }

    public function test_template_creation_rejects_privileges_outside_the_child_template_set(): void
    {
        $parent = AdminApiTestContext::create(organizationAttributes: ['is_holding' => true], roleSlug: 'organization_owner');
        $child = $this->child($parent->organization);
        $this->expectException(RuntimeException::class);
        app(ChildOrganizationUserService::class)->createRoleFromTemplate(
            $child->id, 'observer', ['permissions' => ['system_admin.users.delete']], $parent->user
        );
    }

    private function child(Organization $parent): Organization
    {
        return Organization::factory()->create(['organization_type' => 'child', 'parent_organization_id' => $parent->id]);
    }
}
