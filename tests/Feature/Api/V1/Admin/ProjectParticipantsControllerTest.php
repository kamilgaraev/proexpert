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

    public function test_owner_without_participant_record_is_listed_with_manage_rights(): void
    {
        $this->assertDatabaseMissing('project_organization', [
            'project_id' => $this->project->id,
            'organization_id' => $this->ownerOrganization->id,
        ]);

        $response = $this->actingAs($this->user, 'api_admin')
            ->getJson("/api/v1/admin/projects/{$this->project->id}/participants");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_manage', true);

        $ownerParticipant = collect($response->json('data.participants'))
            ->firstWhere('id', $this->ownerOrganization->id);

        $this->assertNotNull($ownerParticipant);
        $this->assertSame('owner', $ownerParticipant['role']['value']);
        $this->assertTrue((bool) $ownerParticipant['is_owner']);
    }

    public function test_owner_keeps_manage_rights_with_customer_participant_record(): void
    {
        app(\App\Services\Project\ProjectParticipantService::class)->attach(
            $this->project,
            $this->ownerOrganization->id,
            \App\Enums\ProjectOrganizationRole::CUSTOMER,
            $this->user,
            true,
        );

        $this->assertSame(
            \App\Enums\ProjectOrganizationRole::OWNER,
            app(\App\Services\Project\ProjectContextService::class)
                ->getOrganizationRole($this->project, $this->ownerOrganization)
        );

        $response = $this->actingAs($this->user, 'api_admin')
            ->getJson("/api/v1/admin/projects/{$this->project->id}/participants");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_manage', true);

        $ownerParticipant = collect($response->json('data.participants'))
            ->firstWhere('id', $this->ownerOrganization->id);

        $this->assertNotNull($ownerParticipant);
        $this->assertSame('customer', $ownerParticipant['role']['value']);
        $this->assertTrue((bool) $ownerParticipant['is_owner']);
    }

    public function test_participants_list_exposes_capabilities_and_allowed_project_roles(): void
    {
        $designerOrganization = Organization::factory()->create([
            'capabilities' => ['design'],
            'primary_business_type' => 'design',
        ]);

        app(\App\Services\Project\ProjectParticipantService::class)->attach(
            $this->project,
            $designerOrganization->id,
            \App\Enums\ProjectOrganizationRole::DESIGNER,
            $this->user,
        );

        $response = $this->actingAs($this->user, 'api_admin')
            ->getJson("/api/v1/admin/projects/{$this->project->id}/participants");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_manage', true);

        $designerParticipant = collect($response->json('data.participants'))
            ->firstWhere('id', $designerOrganization->id);

        $this->assertNotNull($designerParticipant);
        $this->assertSame(['design'], $designerParticipant['capabilities']);

        $this->assertSame(
            ['customer', 'designer', 'observer'],
            $designerParticipant['allowed_project_roles']
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

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'general_contractor',
            (string) $response->json('message')
        );
    }

    public function test_confirmed_attach_bypasses_role_capabilities(): void
    {
        $designerOrganization = Organization::factory()->create([
            'capabilities' => ['design'],
            'primary_business_type' => 'design',
        ]);

        $response = $this->actingAs($this->user, 'api_admin')
            ->postJson("/api/v1/admin/projects/{$this->project->id}/participants", [
                'organization_id' => $designerOrganization->id,
                'role' => 'general_contractor',
                'confirmed_capabilities' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            'general_contractor',
            \App\Models\ProjectOrganization::query()
                ->where('project_id', $this->project->id)
                ->where('organization_id', $designerOrganization->id)
                ->value('role_new')
        );
    }

    public function test_confirmed_role_change_bypasses_role_capabilities(): void
    {
        $contractorOrganization = Organization::factory()->create([
            'capabilities' => ['smr'],
            'primary_business_type' => 'contractor',
        ]);

        app(\App\Services\Project\ProjectParticipantService::class)->attach(
            $this->project,
            $contractorOrganization->id,
            \App\Enums\ProjectOrganizationRole::CONTRACTOR,
            $this->user,
        );

        $response = $this->actingAs($this->user, 'api_admin')
            ->patchJson("/api/v1/admin/projects/{$this->project->id}/participants/{$contractorOrganization->id}/role", [
                'role' => 'general_contractor',
                'confirmed_capabilities' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            'general_contractor',
            \App\Models\ProjectOrganization::query()
                ->where('project_id', $this->project->id)
                ->where('organization_id', $contractorOrganization->id)
                ->value('role_new')
        );
    }

    public function test_role_change_of_inactive_participant_returns_actionable_422(): void
    {
        $contractorOrganization = Organization::factory()->create([
            'capabilities' => ['smr'],
            'primary_business_type' => 'contractor',
        ]);

        $service = app(\App\Services\Project\ProjectParticipantService::class);
        $service->attach(
            $this->project,
            $contractorOrganization->id,
            \App\Enums\ProjectOrganizationRole::CONTRACTOR,
            $this->user,
        );
        $service->setActiveState($this->project, $contractorOrganization->id, false);

        $response = $this->actingAs($this->user, 'api_admin')
            ->patchJson("/api/v1/admin/projects/{$this->project->id}/participants/{$contractorOrganization->id}/role", [
                'role' => 'subcontractor',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'активируйте',
            mb_strtolower((string) $response->json('message'))
        );
    }

    public function test_deactivated_participant_stays_visible_until_activated(): void
    {
        $contractorOrganization = Organization::factory()->create([
            'capabilities' => ['smr'],
            'primary_business_type' => 'contractor',
        ]);

        $service = app(\App\Services\Project\ProjectParticipantService::class);
        $service->attach(
            $this->project,
            $contractorOrganization->id,
            \App\Enums\ProjectOrganizationRole::CONTRACTOR,
            $this->user,
        );
        $service->setActiveState($this->project, $contractorOrganization->id, false);

        $response = $this->actingAs($this->user, 'api_admin')
            ->getJson("/api/v1/admin/projects/{$this->project->id}/participants");

        $response->assertOk()->assertJsonPath('success', true);

        $contractorParticipant = collect($response->json('data.participants'))
            ->firstWhere('id', $contractorOrganization->id);

        $this->assertNotNull($contractorParticipant);
        $this->assertFalse((bool) $contractorParticipant['is_active']);
        $this->assertSame('inactive', $contractorParticipant['status']);

        $service->setActiveState($this->project, $contractorOrganization->id, true);

        $reactivated = collect(
            $this->actingAs($this->user, 'api_admin')
                ->getJson("/api/v1/admin/projects/{$this->project->id}/participants")
                ->json('data.participants')
        )->firstWhere('id', $contractorOrganization->id);

        $this->assertNotNull($reactivated);
        $this->assertTrue((bool) $reactivated['is_active']);
        $this->assertSame('active', $reactivated['status']);
        $this->assertSame('contractor', $reactivated['role']['value']);
    }

    public function test_role_change_of_unknown_organization_stays_404(): void
    {
        $strangerOrganization = Organization::factory()->create();

        $response = $this->actingAs($this->user, 'api_admin')
            ->patchJson("/api/v1/admin/projects/{$this->project->id}/participants/{$strangerOrganization->id}/role", [
                'role' => 'observer',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_role_change_of_active_participant_applies_to_target_organization(): void
    {
        $contractorOrganization = Organization::factory()->create([
            'capabilities' => ['smr'],
            'primary_business_type' => 'contractor',
        ]);

        app(\App\Services\Project\ProjectParticipantService::class)->attach(
            $this->project,
            $contractorOrganization->id,
            \App\Enums\ProjectOrganizationRole::CONTRACTOR,
            $this->user,
        );

        $response = $this->actingAs($this->user, 'api_admin')
            ->patchJson("/api/v1/admin/projects/{$this->project->id}/participants/{$contractorOrganization->id}/role", [
                'role' => 'observer',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            'observer',
            \App\Models\ProjectOrganization::query()
                ->where('project_id', $this->project->id)
                ->where('organization_id', $contractorOrganization->id)
                ->value('role_new')
        );
    }
}
