<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class UserManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_admin_can_open_admin_user_list(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $ownForeman = $this->createOrganizationUser($context->organization, 'foreman', [
            'name' => 'Org Admin Foreman',
            'email' => 'org-admin-foreman@example.test',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/users?per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $ownForeman->id);
    }

    public function test_index_returns_only_current_organization_foremen_by_default(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminUserPermissions($context->user->id);
        $ownForeman = $this->createOrganizationUser($context->organization, 'foreman', [
            'name' => 'Own Foreman',
            'email' => 'own-foreman@example.test',
        ]);
        $this->createOrganizationUser($context->organization, 'accountant', [
            'name' => 'Own Accountant',
            'email' => 'own-accountant@example.test',
        ]);
        $this->createOrganizationUser(Organization::factory()->verified()->create(), 'foreman', [
            'name' => 'Foreign Foreman',
            'email' => 'foreign-foreman@example.test',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/users?per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $ownForeman->id);
        $response->assertJsonPath('data.0.primary_role', 'foreman');

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertNotContains('Own Accountant', $names);
        $this->assertNotContains('Foreign Foreman', $names);
    }

    public function test_index_can_include_all_current_organization_users_without_leaking_foreign_users(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminUserPermissions($context->user->id);
        $ownForeman = $this->createOrganizationUser($context->organization, 'foreman', [
            'name' => 'Own Foreman',
            'email' => 'all-own-foreman@example.test',
        ]);
        $ownAccountant = $this->createOrganizationUser($context->organization, 'accountant', [
            'name' => 'Own Accountant',
            'email' => 'all-own-accountant@example.test',
        ]);
        $this->createOrganizationUser(Organization::factory()->verified()->create(), 'foreman', [
            'name' => 'Foreign Foreman',
            'email' => 'all-foreign-foreman@example.test',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/users?include_all_types=1&per_page=10&sort_by=name&sort_direction=asc');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownForeman->id, $ids);
        $this->assertContains($ownAccountant->id, $ids);
        $this->assertNotContains('Foreign Foreman', collect($response->json('data'))->pluck('name')->all());
    }

    public function test_options_returns_current_organization_users_without_user_management_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $this->allowAdminAccessWithoutUserManagement($context->user->id);
        $ownForeman = $this->createOrganizationUser($context->organization, 'foreman', [
            'name' => 'Options Foreman',
            'email' => 'options-foreman@example.test',
        ]);
        $ownAccountant = $this->createOrganizationUser($context->organization, 'accountant', [
            'name' => 'Options Accountant',
            'email' => 'options-accountant@example.test',
        ]);
        $this->createOrganizationUser(Organization::factory()->verified()->create(), 'foreman', [
            'name' => 'Options Foreign Foreman',
            'email' => 'options-foreign-foreman@example.test',
        ]);

        $optionsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/users/options?per_page=10&is_active=true&sort_by=name&sort_direction=asc');

        $optionsResponse->assertOk();
        $optionsResponse->assertJsonPath('success', true);
        $optionsResponse->assertJsonMissingPath('data.data.0.project_access');

        $ids = collect($optionsResponse->json('data.data'))->pluck('id')->all();

        $this->assertContains($ownForeman->id, $ids);
        $this->assertContains($ownAccountant->id, $ids);
        $this->assertNotContains('Options Foreign Foreman', collect($optionsResponse->json('data.data'))->pluck('name')->all());

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/users?include_all_types=1&per_page=10');

        $indexResponse->assertForbidden();
    }

    public function test_show_hides_user_from_another_organization(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminUserPermissions($context->user->id);
        $foreignUser = $this->createOrganizationUser(Organization::factory()->verified()->create(), 'foreman', [
            'name' => 'Foreign Foreman',
            'email' => 'show-foreign-foreman@example.test',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/users/{$foreignUser->id}");

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
    }

    public function test_owner_receives_only_roles_allowed_by_assignment_hierarchy(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/users/role-options');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $roles = collect($response->json('data.roles'));

        $this->assertEqualsCanonicalizing([
            'organization_admin',
            'accountant',
            'supplier',
            'viewer',
            'project_manager',
            'foreman',
            'worker',
        ], $roles->pluck('slug')->all());
        $this->assertSame('Снабженец', $roles->firstWhere('slug', 'supplier')['name'] ?? null);
        $this->assertNotContains('organization_owner', $roles->pluck('slug')->all());
        $this->assertNotContains('super_admin', $roles->pluck('slug')->all());
        $this->assertNotContains('system_admin', $roles->pluck('slug')->all());
    }

    public function test_owner_cannot_create_user_with_non_assignable_system_role(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/users', [
                'name' => 'Escalation Attempt',
                'email' => 'escalation-attempt@example.test',
                'password' => 'SecurePassword123!',
                'password_confirmation' => 'SecurePassword123!',
                'role_slug' => 'super_admin',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Вы не можете назначить выбранную роль.');
        $this->assertDatabaseMissing('users', ['email' => 'escalation-attempt@example.test']);
    }

    public function test_update_replaces_manageable_role_and_preserves_protected_assignment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $target = $this->createOrganizationUser($context->organization, 'foreman', [
            'name' => 'Role Change Target',
            'email' => 'role-change-target@example.test',
        ]);
        $organizationContext = AuthorizationContext::getOrganizationContext($context->organization->id);
        UserRoleAssignment::assignRole(
            user: $target,
            roleSlug: 'super_admin',
            context: $organizationContext
        );

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/users/{$target->id}", [
                'name' => $target->name,
                'role_slug' => 'accountant',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.primary_role', 'super_admin');
        $response->assertJsonPath('data.primary_role_label', 'Суперадминистратор');

        $activeRoles = UserRoleAssignment::query()
            ->where('user_id', $target->id)
            ->where('context_id', $organizationContext->id)
            ->where('is_active', true)
            ->pluck('role_slug')
            ->all();

        $this->assertContains('accountant', $activeRoles);
        $this->assertContains('super_admin', $activeRoles);
        $this->assertNotContains('foreman', $activeRoles);
    }

    public function test_user_payload_contains_human_readable_role_labels(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $target = $this->createOrganizationUser($context->organization, 'super_admin', [
            'name' => 'System Role User',
            'email' => 'system-role-user@example.test',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/users/{$target->id}");

        $response->assertOk();
        $response->assertJsonPath('data.roles.0', 'super_admin');
        $response->assertJsonPath('data.role_labels.0', 'Суперадминистратор');
        $response->assertJsonPath('data.primary_role', 'super_admin');
        $response->assertJsonPath('data.primary_role_label', 'Суперадминистратор');
    }

    public function test_block_and_unblock_change_only_current_organization_user_status(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminUserPermissions($context->user->id);
        $ownUser = $this->createOrganizationUser($context->organization, 'foreman', [
            'name' => 'Block Target',
            'email' => 'block-target@example.test',
            'is_active' => true,
        ]);
        $foreignUser = $this->createOrganizationUser(Organization::factory()->verified()->create(), 'foreman', [
            'name' => 'Foreign Block Target',
            'email' => 'foreign-block-target@example.test',
            'is_active' => true,
        ]);

        $blockResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/users/{$ownUser->id}/block");

        $blockResponse->assertOk();
        $blockResponse->assertJsonPath('success', true);
        $this->assertFalse((bool) $ownUser->fresh()->is_active);
        $this->assertTrue((bool) $foreignUser->fresh()->is_active);

        $foreignBlockResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/users/{$foreignUser->id}/block");

        $foreignBlockResponse->assertNotFound();
        $foreignBlockResponse->assertJsonPath('success', false);
        $this->assertTrue((bool) $foreignUser->fresh()->is_active);

        $unblockResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/users/{$ownUser->id}/unblock");

        $unblockResponse->assertOk();
        $unblockResponse->assertJsonPath('success', true);
        $this->assertTrue((bool) $ownUser->fresh()->is_active);
    }

    public function test_block_rejects_self_blocking_with_readable_error(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminUserPermissions($context->user->id);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/users/{$context->user->id}/block");

        $response->assertForbidden();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Вы не можете заблокировать самого себя.');
        $this->assertTrue((bool) $context->user->fresh()->is_active);
    }

    private function allowAdminUserPermissions(int $adminUserId): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($adminUserId): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturnUsing(
                static fn (User $user, string $roleSlug): bool => $user->id === $adminUserId
                    && in_array($roleSlug, ['web_admin', 'organization_admin', 'organization_owner'], true)
            );
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function allowAdminAccessWithoutUserManagement(int $adminUserId): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($adminUserId): void {
            $mock->shouldReceive('canAccessInterface')->andReturnUsing(
                static fn (User $user, string $interface, ?AuthorizationContext $context = null): bool => $user->id === $adminUserId && $interface === 'admin'
            );
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, ?array $context = null): bool => $user->id === $adminUserId && $permission === 'admin.access'
            );
        });
    }

    private function createOrganizationUser(Organization $organization, string $roleSlug, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ], $attributes));

        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        UserRoleAssignment::assignRole(
            user: $user,
            roleSlug: $roleSlug,
            context: AuthorizationContext::getOrganizationContext($organization->id)
        );

        return $user->fresh();
    }
}
