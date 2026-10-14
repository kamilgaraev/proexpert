<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CustomRoleSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_eager_assignments_and_deletion_preserve_organization_boundaries(): void
    {
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstRole = $this->customRole($firstOrganization, 'shared_slug');
        $secondRole = $this->customRole($secondOrganization, 'shared_slug');
        $firstAssignment = UserRoleAssignment::assignRole($firstUser, $firstRole->slug,
            AuthorizationContext::getOrganizationContext($firstOrganization->id), UserRoleAssignment::TYPE_CUSTOM);
        $secondAssignment = UserRoleAssignment::assignRole($secondUser, $secondRole->slug,
            AuthorizationContext::getOrganizationContext($secondOrganization->id), UserRoleAssignment::TYPE_CUSTOM);
        $historyConditions = ['working_days' => [0, 1, 2, 3, 4, 5, 6]];
        $condition = $firstAssignment->conditions()->create([
            'condition_type' => 'time', 'condition_data' => $historyConditions, 'is_active' => true,
        ]);

        $roles = OrganizationCustomRole::query()->with('assignments.user')->whereIn('id', [$firstRole->id, $secondRole->id])->get()->keyBy('id');
        self::assertSame([$firstUser->id], $roles[$firstRole->id]->assignments->pluck('user_id')->all());
        self::assertSame([$secondUser->id], $roles[$secondRole->id]->assignments->pluck('user_id')->all());
        $assignments = UserRoleAssignment::query()->with('customRole')->whereIn('id', [$firstAssignment->id, $secondAssignment->id])->get()->keyBy('id');
        self::assertSame($firstRole->id, $assignments[$firstAssignment->id]->customRole->id);
        self::assertSame($secondRole->id, $assignments[$secondAssignment->id]->customRole->id);

        $firstRole->deactivate();
        self::assertFalse($firstAssignment->fresh()->is_active);
        self::assertTrue($secondAssignment->fresh()->is_active);
        $firstRole->delete();
        self::assertNotNull($firstAssignment->fresh());
        self::assertFalse($firstAssignment->fresh()->is_active);
        self::assertNull($firstAssignment->fresh()->custom_role_id);
        self::assertSame($firstRole->slug, $firstAssignment->fresh()->role_slug);
        self::assertSame([$condition->id], $firstAssignment->fresh()->conditions->pluck('id')->all());
        self::assertSame($historyConditions, $condition->fresh()->condition_data);
        self::assertNotNull($secondAssignment->fresh());
    }

    public function test_migration_binds_legacy_assignments_to_roles_in_their_own_organization(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $user = User::factory()->create();
        $firstRole = $this->customRole($first, 'legacy_same_slug');
        $secondRole = $this->customRole($second, 'legacy_same_slug');
        $firstAssignment = UserRoleAssignment::assignRole($user, $firstRole->slug,
            AuthorizationContext::getOrganizationContext($first->id), UserRoleAssignment::TYPE_CUSTOM);
        $secondAssignment = UserRoleAssignment::assignRole($user, $secondRole->slug,
            AuthorizationContext::getOrganizationContext($second->id), UserRoleAssignment::TYPE_CUSTOM);
        $project = \App\Models\Project::factory()->create(['organization_id' => $first->id]);
        $projectAssignment = UserRoleAssignment::assignRole($user, $firstRole->slug,
            AuthorizationContext::getProjectContext($project->id, $first->id), UserRoleAssignment::TYPE_CUSTOM);
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_14_010000_bind_custom_role_assignments.php';
        $migration->down();
        $migration->up();
        self::assertSame($firstRole->id, $firstAssignment->fresh()->custom_role_id);
        self::assertSame($secondRole->id, $secondAssignment->fresh()->custom_role_id);
        self::assertSame($firstRole->id, $projectAssignment->fresh()->custom_role_id);
    }

    public function test_legacy_custom_system_slug_does_not_grant_system_permissions(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_active' => true]);
        $role = $this->customRole($organization, 'organization_owner');
        UserRoleAssignment::assignRole($user, $role->slug,
            AuthorizationContext::getOrganizationContext($organization->id), UserRoleAssignment::TYPE_CUSTOM);

        $authorization = app(AuthorizationService::class)->forCurrentChecks();
        self::assertFalse($authorization->canCurrent($user, 'billing.manage', ['organization_id' => $organization->id]));
        self::assertFalse($authorization->hasRole($user, 'organization_owner'));
        self::assertFalse($user->isOrganizationOwner($organization->id));
        self::assertTrue($authorization->canCurrent($user, 'organization.view', ['organization_id' => $organization->id]));
    }

    public function test_legacy_global_wildcard_fails_closed(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_active' => true]);
        $role = $this->customRole($organization, 'legacy_wildcard');
        $role->update(['system_permissions' => ['*']]);
        UserRoleAssignment::assignRole($user, $role->slug,
            AuthorizationContext::getOrganizationContext($organization->id), UserRoleAssignment::TYPE_CUSTOM);

        self::assertFalse(app(AuthorizationService::class)->canCurrent($user, 'billing.manage', ['organization_id' => $organization->id]));
    }

    public function test_role_access_rejects_a_foreign_role_even_for_an_owner(): void
    {
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $owner->organizations()->attach($organization->id, ['is_active' => true]);
        UserRoleAssignment::assignRole($owner, 'organization_owner', AuthorizationContext::getOrganizationContext($organization->id));
        $role = $this->customRole($foreignOrganization, 'foreign_role');

        $this->expectException(AuthorizationException::class);
        app(CustomRoleService::class)->updateRole($role, ['name' => 'Changed'], $owner, $organization->id);
    }

    public function test_reserved_system_name_produces_a_distinct_custom_slug(): void
    {
        $organization = Organization::factory()->create();
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Organization Owner',
            'created_by' => User::factory()->create()->id,
            'system_permissions' => ['organization.view'],
            'module_permissions' => [],
            'interface_access' => ['lk'],
            'is_active' => true,
        ]);

        self::assertNotSame('organization_owner', $role->slug);
    }

    public function test_role_update_invalidates_the_cached_role_model_and_permissions(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $owner->organizations()->attach($organization->id, ['is_active' => true]);
        UserRoleAssignment::assignRole($owner, 'organization_owner', AuthorizationContext::getOrganizationContext($organization->id));
        $role = $this->customRole($organization, 'cached_role');
        $resolver = app(\App\Domain\Authorization\Services\PermissionResolver::class);
        self::assertContains('organization.view', $resolver->getCustomRolePermissions($role->slug, $organization->id));
        app(CustomRoleService::class)->updateRole($role, ['system_permissions' => []], $owner, $organization->id);
        self::assertNotContains('organization.view', $resolver->getCustomRolePermissions($role->slug, $organization->id));
    }

    public function test_custom_role_creation_rejects_a_global_system_wildcard(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $owner->organizations()->attach($organization->id, ['is_active' => true]);
        UserRoleAssignment::assignRole($owner, 'organization_owner', AuthorizationContext::getOrganizationContext($organization->id));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CustomRoleService::class)->createRole(
            organizationId: $organization->id,
            name: 'Wildcard role',
            systemPermissions: ['*'],
            createdBy: $owner,
        );
    }

    private function customRole(Organization $organization, string $slug): OrganizationCustomRole
    {
        return OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => $slug,
            'slug' => $slug,
            'created_by' => User::factory()->create()->id,
            'system_permissions' => ['organization.view'],
            'module_permissions' => [],
            'interface_access' => ['lk'],
            'is_active' => true,
        ]);
    }
}
