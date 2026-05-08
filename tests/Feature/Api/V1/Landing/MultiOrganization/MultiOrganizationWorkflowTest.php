<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\MultiOrganization;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\User;
use App\Services\Landing\ChildOrganizationUserService;
use App\Services\Landing\MultiOrganizationService;
use App\Services\Storage\OrgBucketService;
use Tests\TestCase;

class MultiOrganizationWorkflowTest extends TestCase
{
    public function test_create_holding_converts_current_organization_to_parent_with_group(): void
    {
        [$organization, $owner] = $this->createOrganizationWithOwner();

        $group = app(MultiOrganizationService::class)->createOrganizationGroup($owner, [
            'name' => 'Основной холдинг',
            'description' => 'Группа компаний',
            'max_child_organizations' => 25,
        ]);

        $organization->refresh();

        $this->assertSame('parent', $organization->organization_type);
        $this->assertTrue($organization->is_holding);
        $this->assertSame(0, $organization->hierarchy_level);
        $this->assertSame((string) $organization->id, $organization->hierarchy_path);
        $this->assertSame($organization->id, $group->parent_organization_id);
        $this->assertSame($owner->id, $group->created_by_user_id);
    }

    public function test_add_child_organization_assigns_parent_group_owner_and_child_hierarchy_path(): void
    {
        [$parent, $owner] = $this->createHoldingWithOwner();
        $group = OrganizationGroup::query()->where('parent_organization_id', $parent->id)->firstOrFail();

        $this->mock(OrgBucketService::class, function ($mock): void {
            $mock->shouldReceive('createBucket')->once()->andReturn('test-bucket');
        });

        $result = app(MultiOrganizationService::class)->addChildOrganization($group, [
            'name' => 'Дочерняя компания',
            'inn' => '1234567890',
            'owner' => [
                'name' => 'Child Owner',
                'email' => 'child-owner@example.com',
                'password' => 'password123',
            ],
        ], $owner);

        /** @var Organization $child */
        $child = $result['organization'];
        /** @var User $childOwner */
        $childOwner = $result['owner_user'];

        $this->assertSame('child', $child->organization_type);
        $this->assertFalse($child->is_holding);
        $this->assertSame($parent->id, $child->parent_organization_id);
        $this->assertSame(1, $child->hierarchy_level);
        $this->assertSame($parent->hierarchy_path . '.' . $child->id, $child->hierarchy_path);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $child->id,
            'user_id' => $childOwner->id,
            'is_owner' => true,
            'is_active' => true,
        ]);
        $this->assertSame($child->id, $childOwner->fresh()->current_organization_id);
    }

    public function test_delete_child_organization_rejects_transfer_target_outside_current_holding(): void
    {
        [$parent, $owner] = $this->createHoldingWithOwner();
        $child = $this->createChildOrganization($parent);
        $outsideOrganization = Organization::factory()->create();
        $childUser = User::factory()->create(['current_organization_id' => $child->id]);

        $child->users()->attach($childUser->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $this->expectException(\Exception::class);

        app(MultiOrganizationService::class)->deleteChildOrganization(
            $parent->id,
            $child->id,
            $owner,
            $outsideOrganization->id
        );
    }

    public function test_delete_child_organization_rejects_transfer_target_equal_to_deleted_child(): void
    {
        [$parent, $owner] = $this->createHoldingWithOwner();
        $child = $this->createChildOrganization($parent);
        $childUser = User::factory()->create(['current_organization_id' => $child->id]);

        $child->users()->attach($childUser->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $this->expectException(\Exception::class);

        app(MultiOrganizationService::class)->deleteChildOrganization(
            $parent->id,
            $child->id,
            $owner,
            $child->id
        );
    }

    public function test_removing_child_user_deactivates_effective_role_assignment(): void
    {
        [$parent, $owner] = $this->createHoldingWithOwner();
        $child = $this->createChildOrganization($parent);
        $user = User::factory()->create(['current_organization_id' => $child->id]);
        $context = AuthorizationContext::getOrganizationContext($child->id);

        $child->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        UserRoleAssignment::assignRole(
            $user,
            'organization_user',
            $context,
            UserRoleAssignment::TYPE_SYSTEM,
            $owner
        );

        app(MultiOrganizationService::class)->removeUserFromChildOrganization(
            $parent->id,
            $child->id,
            $user->id,
            $owner
        );

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $child->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'context_id' => $context->id,
            'role_slug' => 'organization_user',
            'is_active' => false,
        ]);
    }

    public function test_child_user_system_role_assignment_is_recorded_as_system_role(): void
    {
        [$parent, $owner] = $this->createHoldingWithOwner();
        $child = $this->createChildOrganization($parent);

        app(ChildOrganizationUserService::class)->createUserWithRole($child->id, [
            'name' => 'System Role User',
            'email' => 'system-role-user@example.com',
            'password' => 'password123',
            'role_data' => [
                'slug' => 'organization_user',
            ],
        ], $owner);

        $context = AuthorizationContext::getOrganizationContext($child->id);

        $this->assertDatabaseHas('user_role_assignments', [
            'context_id' => $context->id,
            'role_slug' => 'organization_user',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'is_active' => true,
        ]);
    }

    public function test_child_user_custom_role_assignment_is_recorded_as_custom_role(): void
    {
        [$parent, $owner] = $this->createHoldingWithOwner();
        $child = $this->createChildOrganization($parent);

        app(ChildOrganizationUserService::class)->createUserWithRole($child->id, [
            'name' => 'Custom Role User',
            'email' => 'custom-role-user@example.com',
            'password' => 'password123',
            'role_data' => [
                'is_custom' => true,
                'name' => 'Кастомная роль',
                'description' => 'Ограниченная роль',
                'permissions' => [],
            ],
        ], $owner);

        $context = AuthorizationContext::getOrganizationContext($child->id);

        $this->assertDatabaseHas('user_role_assignments', [
            'context_id' => $context->id,
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'is_active' => true,
        ]);
    }

    public function test_organization_group_active_children_use_organization_activity_flag(): void
    {
        [$parent] = $this->createHoldingWithOwner();
        $group = OrganizationGroup::query()->where('parent_organization_id', $parent->id)->firstOrFail();
        $activeChild = $this->createChildOrganization($parent);

        $this->createChildOrganization($parent)->update([
            'is_active' => false,
        ]);

        $activeChildren = $group->getActiveChildOrganizations()->pluck('id')->all();

        $this->assertSame([$activeChild->id], $activeChildren);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function createOrganizationWithOwner(array $organizationOverrides = []): array
    {
        $organization = Organization::factory()->create($organizationOverrides);
        $owner = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($owner->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        return [$organization, $owner];
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function createHoldingWithOwner(): array
    {
        [$organization, $owner] = $this->createOrganizationWithOwner([
            'organization_type' => 'single',
            'is_holding' => false,
        ]);

        app(MultiOrganizationService::class)->createOrganizationGroup($owner, [
            'name' => 'Основной холдинг',
            'max_child_organizations' => 10,
        ]);

        return [$organization->fresh(), $owner->fresh()];
    }

    private function createChildOrganization(Organization $parent): Organization
    {
        return Organization::factory()->create([
            'parent_organization_id' => $parent->id,
            'organization_type' => 'child',
            'is_holding' => false,
            'hierarchy_level' => 1,
            'hierarchy_path' => $parent->hierarchy_path . '.child',
        ]);
    }
}
