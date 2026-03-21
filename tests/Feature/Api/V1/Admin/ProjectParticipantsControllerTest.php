<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

class ProjectParticipantsControllerTest extends TestCase
{
    private Organization $ownerOrganization;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->ownerOrganization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'current_organization_id' => $this->ownerOrganization->id,
        ]);
        $this->ownerOrganization->users()->attach($this->user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $this->project = Project::factory()->create([
            'organization_id' => $this->ownerOrganization->id,
        ]);
    }

    public function test_participants_list_exposes_capabilities_and_allowed_project_roles(): void
    {
        $designerOrganization = Organization::factory()->create([
            'capabilities' => ['design'],
            'primary_business_type' => 'design',
        ]);

        $this->project->organizations()->attach($designerOrganization->id, [
            'role' => 'designer',
            'role_new' => 'designer',
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api_admin')
            ->getJson("/api/v1/admin/projects/{$this->project->id}/participants");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.participants.0.capabilities.0', 'design');

        $this->assertSame(
            ['customer', 'designer', 'observer'],
            $response->json('data.participants.0.allowed_project_roles')
        );
    }

    public function test_backend_rejects_role_that_is_not_allowed_by_capabilities(): void
    {
        $designerOrganization = Organization::factory()->create([
            'capabilities' => ['design'],
            'primary_business_type' => 'design',
        ]);

        $response = $this->actingAs($this->user, 'api_admin')
            ->postJson("/api/v1/admin/projects/{$this->project->id}/participants", [
                'organization_id' => $designerOrganization->id,
                'role' => 'general_contractor',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'general_contractor',
            (string) $response->json('message')
        );
    }
}
