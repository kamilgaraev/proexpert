<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Domain\Authorization\Models\AuthorizationContext;
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
        UserRoleAssignment::assignRole($owner, 'organization_owner', $context);
        UserRoleAssignment::assignRole($supplier, 'supplier', $context);

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

        $this->assertDatabaseHas('users', [
            'id' => $supplier->id,
            'name' => 'Снабженов Обновлен',
            'is_active' => false,
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
