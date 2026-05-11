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
                static fn (User $user, string $roleSlug): bool =>
                    $user->id === $adminUserId
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
