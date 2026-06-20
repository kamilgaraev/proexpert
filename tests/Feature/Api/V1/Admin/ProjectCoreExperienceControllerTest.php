<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ProjectOrganizationRole;
use App\Interfaces\Billing\SubscriptionLimitsServiceInterface;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_index_and_selector_show_owned_and_participant_projects_without_foreign_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $participantOrganization = Organization::factory()->verified()->create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $this->allowAdminAccess();

        $ownedActive = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Owned Active Project',
            'status' => 'active',
            'is_archived' => false,
        ]);
        $ownedArchived = Project::factory()->archived()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Owned Archived Project',
            'status' => 'paused',
        ]);
        $participantProject = Project::factory()->create([
            'organization_id' => $participantOrganization->id,
            'name' => 'Participant Project',
            'status' => 'active',
            'is_archived' => false,
        ]);
        $participantProject->organizations()->attach($context->organization->id, [
            'role' => 'contractor',
            'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign Project',
            'status' => 'active',
            'is_archived' => false,
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/projects?per_page=10&sort_by=name&sort_direction=asc');

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);

        $indexIds = collect($indexResponse->json('data.data', $indexResponse->json('data')))->pluck('id')->all();
        $this->assertContains($ownedActive->id, $indexIds);
        $this->assertContains($ownedArchived->id, $indexIds);
        $this->assertContains($participantProject->id, $indexIds);
        $this->assertNotContains($foreignProject->id, $indexIds);

        $selectorResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/available-projects');

        $selectorResponse->assertOk();
        $selectorResponse->assertJsonPath('success', true);
        $selectorResponse->assertJsonPath('data.totals.all', 2);
        $selectorResponse->assertJsonPath('data.totals.owned', 1);
        $selectorResponse->assertJsonPath('data.totals.participant', 1);
        $selectorResponse->assertJsonPath('data.totals.archived', 0);

        $selectorIds = collect($selectorResponse->json('data.projects'))->pluck('id')->all();
        $this->assertContains($ownedActive->id, $selectorIds);
        $this->assertContains($participantProject->id, $selectorIds);
        $this->assertNotContains($ownedArchived->id, $selectorIds);
        $this->assertNotContains($foreignProject->id, $selectorIds);
    }

    public function test_project_create_and_update_preserve_business_fields_in_their_correct_columns(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $this->allowSubscriptionProjectCreation();

        $createPayload = [
            'name' => 'Pilot Build',
            'address' => 'Moscow, Pilot street 1',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'description' => 'Pilot launch project',
            'customer' => 'Pilot Customer',
            'designer' => 'Pilot Designer',
            'budget_amount' => 1234567.89,
            'site_area_m2' => 987.65,
            'contract_number' => 'CNT-001',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
            'status' => 'active',
            'is_archived' => false,
            'external_code' => 'EXT-PILOT',
            'accounting_data' => ['department' => 'pilot'],
            'use_in_accounting_reports' => true,
        ];

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/projects', $createPayload);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.name', 'Pilot Build');
        $createResponse->assertJsonPath('data.start_date', '2026-06-01');
        $createResponse->assertJsonPath('data.end_date', '2026-08-31');
        $createResponse->assertJsonPath('data.status', 'active');
        $createResponse->assertJsonPath('data.contract_number', 'CNT-001');
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);

        $projectId = $createResponse->json('data.id');
        $project = Project::query()->findOrFail($projectId);

        $this->assertSame($context->organization->id, $project->organization_id);
        $this->assertSame('2026-06-01', $project->start_date?->toDateString());
        $this->assertSame('2026-08-31', $project->end_date?->toDateString());
        $this->assertSame('active', $project->status);
        $this->assertSame('1234567.89', (string) $project->budget_amount);
        $this->assertSame('project_planned_cost', $project->additional_info['budget_amount_context']['contour'] ?? null);
        $this->assertSame('manual', $project->additional_info['budget_amount_context']['source'] ?? null);
        $this->assertFalse($project->additional_info['budget_amount_context']['creates_budget_lines'] ?? true);
        $this->assertSame('987.65', (string) $project->site_area_m2);
        $this->assertSame('CNT-001', $project->contract_number);
        $this->assertTrue((bool) $project->use_in_accounting_reports);
        $this->assertSame('geocoded', $project->geocoding_status);
        $this->assertNotNull($project->geocoded_at);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/projects/{$project->id}", [
                'budget_amount' => 2222222.22,
                'site_area_m2' => 111.11,
                'contract_number' => 'CNT-002',
                'start_date' => '2026-07-01',
                'end_date' => '2026-09-15',
                'status' => 'paused',
                'latitude' => null,
                'longitude' => null,
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.start_date', '2026-07-01');
        $updateResponse->assertJsonPath('data.end_date', '2026-09-15');
        $updateResponse->assertJsonPath('data.status', 'paused');
        $updateResponse->assertJsonPath('data.contract_number', 'CNT-002');

        $project = $project->fresh();
        $this->assertSame('2026-07-01', $project->start_date?->toDateString());
        $this->assertSame('2026-09-15', $project->end_date?->toDateString());
        $this->assertSame('paused', $project->status);
        $this->assertSame('2222222.22', (string) $project->budget_amount);
        $this->assertSame('project_planned_cost', $project->additional_info['budget_amount_context']['contour'] ?? null);
        $this->assertSame('manual', $project->additional_info['budget_amount_context']['source'] ?? null);
        $this->assertFalse($project->additional_info['budget_amount_context']['creates_budget_lines'] ?? true);
        $this->assertSame('111.11', (string) $project->site_area_m2);
        $this->assertSame('CNT-002', $project->contract_number);
    }

    public function test_project_status_update_reuses_existing_coordinates_without_type_error(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();

        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Training Project',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}", [
                'name' => $project->name,
                'address' => $project->address,
                'description' => $project->description,
                'status' => 'completed',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'completed');

        $project->refresh();

        $this->assertSame('completed', $project->status);
        $this->assertSame('55.7558000', (string) $project->latitude);
        $this->assertSame('37.6173000', (string) $project->longitude);
    }

    public function test_project_show_update_delete_hide_foreign_projects(): void
    {
        $context = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create([
            'organization_id' => Organization::factory()->verified()->create()->id,
            'name' => 'Foreign Project',
        ]);
        $ownProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Own Project',
        ]);
        $this->allowAdminAccess();

        $showForeignResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$foreignProject->id}");
        $showForeignResponse->assertNotFound();
        $showForeignResponse->assertJsonPath('success', false);

        $updateForeignResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/projects/{$foreignProject->id}", [
                'name' => 'Hacked Name',
            ]);
        $updateForeignResponse->assertNotFound();
        $updateForeignResponse->assertJsonPath('success', false);
        $this->assertSame('Foreign Project', $foreignProject->fresh()->name);

        $deleteForeignResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$foreignProject->id}");
        $deleteForeignResponse->assertNotFound();
        $deleteForeignResponse->assertJsonPath('success', false);
        $this->assertNotSoftDeleted('projects', ['id' => $foreignProject->id]);

        $deleteOwnResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$ownProject->id}");
        $deleteOwnResponse->assertOk();
        $deleteOwnResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('projects', ['id' => $ownProject->id]);
    }

    public function test_project_context_reflects_owner_role(): void
    {
        $ownerContext = AdminApiTestContext::create();
        $this->allowAdminAccess();

        $project = Project::factory()->create([
            'organization_id' => $ownerContext->organization->id,
            'name' => 'Owner Context Project',
        ]);

        $response = $this->withHeaders($ownerContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/context");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.context.project_id', $project->id);
        $response->assertJsonPath('data.context.organization_id', $ownerContext->organization->id);
        $response->assertJsonPath('data.context.role.value', ProjectOrganizationRole::OWNER->value);
        $response->assertJsonPath('data.context.is_owner', true);
    }

    public function test_project_context_meta_and_permissions_reflect_participant_role(): void
    {
        $participantContext = AdminApiTestContext::create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $this->allowAdminAccess();

        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Participant Context Project',
        ]);
        $project->organizations()->attach($participantContext->organization->id, [
            'role' => 'contractor',
            'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $participantPermissionsResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/permissions");
        $participantPermissionsResponse->assertOk();
        $participantPermissionsResponse->assertJsonPath('success', true);
        $participantPermissionsResponse->assertJsonPath('data.role.value', ProjectOrganizationRole::CONTRACTOR->value);
        $participantPermissionsResponse->assertJsonPath('data.capabilities.can_manage_contracts', true);
        $participantPermissionsResponse->assertJsonPath('data.capabilities.can_invite_participants', false);

        $participantMetaResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/form-meta");
        $participantMetaResponse->assertOk();
        $participantMetaResponse->assertJsonPath('success', true);
        $participantMetaResponse->assertJsonPath('data.contractor_field.mode', 'hidden_autofill');
        $participantMetaResponse->assertJsonPath('data.fields_visibility.contractor_selection', false);
        $participantMetaResponse->assertJsonPath('data.available_actions.can_view_all_data', false);
    }

    public function test_project_context_denies_foreign_organization_with_admin_response_contract(): void
    {
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => Organization::factory()->verified()->create()->id,
            'name' => 'Foreign Context Project',
        ]);
        $this->allowAdminAccess();

        $foreignResponse = $this->withHeaders($foreignContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/context");
        $foreignResponse->assertForbidden();
        $foreignResponse->assertJsonPath('success', false);
        $foreignResponse->assertJsonPath('message', trans_message('project.access_denied'));
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
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

    private function allowSubscriptionProjectCreation(): void
    {
        $this->mock(SubscriptionLimitsServiceInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canCreateProject')->andReturn(true);
        });
    }
}
