<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class AdminPanelUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_lk_role_user_from_admin_panel_users_endpoint(): void
    {
        $organization = Organization::factory()->verified()->create();
        $owner = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $supplier = User::factory()->create([
            'name' => 'Снабженов Снабжен',
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $organization->users()->attach($owner->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);
        $organization->users()->attach($supplier->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $context = AuthorizationContext::getOrganizationContext($organization->id);
        $customRole = OrganizationCustomRole::createRole(
            $organization->id,
            'Ответственный за закупки',
            ['organization.view'],
            [],
            ['lk'],
            null,
            null,
            $owner
        );

        UserRoleAssignment::assignRole($owner, 'organization_owner', $context);
        UserRoleAssignment::assignRole($supplier, 'supplier', $context);
        UserRoleAssignment::assignRole($supplier, $customRole->slug, $context, UserRoleAssignment::TYPE_CUSTOM);

        $response = $this
            ->withHeaders($this->landingHeaders($owner, $organization))
            ->patchJson("/api/v1/landing/adminPanelUsers/{$supplier->id}", [
                'name' => 'Снабженов Обновлен',
                'is_active' => false,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Снабженов Обновлен')
            ->assertJsonPath('data.is_active', false);
        $response->assertJsonFragment([
            'name' => 'Снабженец',
            'slug' => 'supplier',
            'type' => UserRoleAssignment::TYPE_SYSTEM,
        ]);
        $response->assertJsonFragment([
            'name' => 'Ответственный за закупки',
            'slug' => $customRole->slug,
            'type' => UserRoleAssignment::TYPE_CUSTOM,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $supplier->id,
            'name' => 'Снабженов Обновлен',
            'is_active' => false,
        ]);
    }

    public function test_owner_can_create_user_with_custom_role_from_admin_panel_users_endpoint(): void
    {
        $organization = Organization::factory()->verified()->create();
        $owner = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($owner->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $context = AuthorizationContext::getOrganizationContext($organization->id);
        UserRoleAssignment::assignRole($owner, 'organization_owner', $context);

        $customRole = OrganizationCustomRole::createRole(
            $organization->id,
            'Координатор закупок',
            ['organization.view'],
            [],
            ['lk'],
            null,
            null,
            $owner
        );

        $response = $this
            ->withHeaders($this->landingHeaders($owner, $organization))
            ->postJson('/api/v1/landing/adminPanelUsers', [
                'name' => 'Кастомный Пользователь',
                'email' => 'custom-role-user@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role_slug' => $customRole->slug,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role_slug', $customRole->slug);
        $response->assertJsonFragment([
            'name' => 'Координатор закупок',
            'slug' => $customRole->slug,
            'type' => UserRoleAssignment::TYPE_CUSTOM,
        ]);

        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $response->json('data.id'),
            'role_slug' => $customRole->slug,
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => $context->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function landingHeaders(User $user, Organization $organization): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::claims([
                'organization_id' => $organization->id,
            ])->fromUser($user),
            'Accept' => 'application/json',
        ];
    }
}
