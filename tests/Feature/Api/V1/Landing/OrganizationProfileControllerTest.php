<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class OrganizationProfileControllerTest extends TestCase
{
    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->organization = Organization::factory()->create([
            'capabilities' => ['general_contracting', 'subcontracting'],
            'primary_business_type' => 'general_contracting',
            'profile_completeness' => 80,
        ]);

        $this->user = User::factory()->create([
            'current_organization_id' => $this->organization->id,
        ]);

        $this->organization->users()->attach($this->user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);
    }

    public function test_get_profile_returns_workspace_profile(): void
    {
        $response = $this->actingAs($this->user, 'api_landing')
            ->getJson('/api/v1/landing/organization/profile');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workspace_profile.primary_profile', 'general_contracting')
            ->assertJsonCount(2, 'data.workspace_profile.workspace_options');

        $this->assertSame(
            ['create_project', 'open_projects', 'open_modules', 'open_invitations'],
            array_column($response->json('data.workspace_profile.recommended_actions'), 'key')
        );
    }

    public function test_update_business_type_rejects_value_outside_selected_capabilities(): void
    {
        $this->organization->update([
            'capabilities' => ['design'],
            'primary_business_type' => 'design',
        ]);

        $response = $this->actingAs($this->user, 'api_landing')
            ->putJson('/api/v1/landing/organization/profile/business-type', [
                'primary_business_type' => 'general_contracting',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Primary business type must be one of selected capabilities.'
            );
    }
}
