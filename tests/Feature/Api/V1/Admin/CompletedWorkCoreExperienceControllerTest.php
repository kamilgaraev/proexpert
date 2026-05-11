<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\ContractorType;
use App\Enums\ProjectOrganizationRole;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class CompletedWorkCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_list_and_delete_completed_work_inside_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Owner Work Contractor');
        $contract = $this->createContract($context->organization, $project, $contractor, ['number' => 'WORK-CON-001']);
        $workType = $this->createWorkType($context->organization, 'Монтаж вентиляции');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works", [
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'contractor_id' => $contractor->id,
                'work_type_id' => $workType->id,
                'user_id' => $context->user->id,
                'quantity' => 8,
                'price' => 1250,
                'completion_date' => '2026-06-10',
                'notes' => 'First owner work',
                'status' => 'confirmed',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.contract_id', $contract->id);
        $createResponse->assertJsonPath('data.contractor_id', $contractor->id);
        $createResponse->assertJsonPath('data.total_amount', 10000);

        $work = CompletedWork::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $work->organization_id);
        $this->assertSame($project->id, $work->project_id);
        $this->assertSame(10000.0, (float) $work->total_amount);

        $otherProjectWork = $this->createCompletedWork($context->organization, $anotherProject, $contractor, [
            'notes' => 'Other project work',
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works?per_page=20&sort_by=id&sort_direction=asc");

        $indexResponse->assertOk();
        $indexIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($work->id, $indexIds);
        $this->assertNotContains($otherProjectWork->id, $indexIds);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}", [
                'quantity' => 5,
                'total_amount' => 15000,
                'notes' => 'Updated owner work',
                'status' => 'pending',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.notes', 'Updated owner work');
        $work->refresh();
        $this->assertSame(15000.0, (float) $work->total_amount);
        $this->assertSame(3000.0, (float) $work->price);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}");

        $deleteResponse->assertNoContent();
        $this->assertSoftDeleted('completed_works', ['id' => $work->id]);
    }

    public function test_completed_work_routes_hide_work_from_another_project_without_mutating_it(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Scoped Work Contractor');
        $work = $this->createCompletedWork($context->organization, $anotherProject, $contractor, [
            'notes' => 'Out of project work',
        ]);
        $this->allowAdminAccess();

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}");
        $showResponse->assertNotFound();

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}", [
                'notes' => 'SHOULD-NOT-CHANGE',
            ]);
        $updateResponse->assertNotFound();
        $this->assertSame('Out of project work', $work->fresh()->notes);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}");
        $deleteResponse->assertNotFound();
        $this->assertNotSoftDeleted('completed_works', ['id' => $work->id]);
    }

    public function test_contractor_participant_works_only_with_own_completed_works(): void
    {
        $participantContext = AdminApiTestContext::create();
        $otherParticipantOrganization = Organization::factory()->verified()->create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Participant Works Project',
        ]);
        $this->attachProjectParticipant($project, $participantContext->organization, ProjectOrganizationRole::CONTRACTOR);
        $this->attachProjectParticipant($project, $otherParticipantOrganization, ProjectOrganizationRole::CONTRACTOR);

        $ownContractor = $this->createContractor($ownerOrganization, 'Own Works Contractor', $participantContext->organization);
        $otherContractor = $this->createContractor($ownerOrganization, 'Other Works Contractor', $otherParticipantOrganization);
        $ownContract = $this->createContract($ownerOrganization, $project, $ownContractor, ['number' => 'OWN-WORK-CON']);
        $otherContract = $this->createContract($ownerOrganization, $project, $otherContractor, ['number' => 'OTHER-WORK-CON']);
        $workType = $this->createWorkType($ownerOrganization, 'Работы подрядчика');
        $ownWork = $this->createCompletedWork($ownerOrganization, $project, $ownContractor, [
            'contract_id' => $ownContract->id,
            'work_type_id' => $workType->id,
            'notes' => 'Own participant work',
        ]);
        $otherWork = $this->createCompletedWork($ownerOrganization, $project, $otherContractor, [
            'contract_id' => $otherContract->id,
            'work_type_id' => $workType->id,
            'notes' => 'Other participant work',
        ]);
        $this->allowAdminAccess();

        $indexResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works?per_page=20");

        $indexResponse->assertOk();
        $indexIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($ownWork->id, $indexIds);
        $this->assertNotContains($otherWork->id, $indexIds);

        $showOwnResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/{$ownWork->id}");
        $showOwnResponse->assertOk();
        $showOwnResponse->assertJsonPath('data.id', $ownWork->id);

        $updateOwnResponse = $this->withHeaders($participantContext->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$ownWork->id}", [
                'quantity' => 6,
                'price' => 900,
                'notes' => 'Own participant work updated',
            ]);
        $updateOwnResponse->assertOk();
        $this->assertSame('Own participant work updated', $ownWork->fresh()->notes);
        $this->assertSame(5400.0, (float) $ownWork->fresh()->total_amount);

        $showOtherResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/{$otherWork->id}");
        $showOtherResponse->assertNotFound();

        $updateOtherResponse = $this->withHeaders($participantContext->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$otherWork->id}", [
                'notes' => 'LEAKED-UPDATE',
            ]);
        $updateOtherResponse->assertNotFound();
        $this->assertSame('Other participant work', $otherWork->fresh()->notes);

        $createResponse = $this->withHeaders($participantContext->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works", [
                'project_id' => $project->id,
                'contract_id' => $ownContract->id,
                'work_type_id' => $workType->id,
                'quantity' => 3,
                'price' => 700,
                'completion_date' => '2026-06-18',
                'notes' => 'Created by participant',
                'status' => 'pending',
            ]);

        $createResponse->assertCreated();
        $createdWork = CompletedWork::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($ownerOrganization->id, $createdWork->organization_id);
        $this->assertSame($ownContractor->id, $createdWork->contractor_id);
        $this->assertSame($ownContract->id, $createdWork->contract_id);

        $forbiddenCreateResponse = $this->withHeaders($participantContext->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works", [
                'project_id' => $project->id,
                'contract_id' => $otherContract->id,
                'work_type_id' => $workType->id,
                'quantity' => 2,
                'price' => 500,
                'completion_date' => '2026-06-19',
                'status' => 'pending',
            ]);

        $forbiddenCreateResponse->assertNotFound();
        $this->assertDatabaseMissing('completed_works', [
            'contract_id' => $otherContract->id,
            'notes' => null,
            'quantity' => 2,
        ]);
    }

    private function createContractor(
        Organization $organization,
        string $name,
        ?Organization $sourceOrganization = null
    ): Contractor {
        return Contractor::query()->create([
            'organization_id' => $organization->id,
            'source_organization_id' => $sourceOrganization?->id,
            'name' => $name,
            'contact_person' => $name . ' Manager',
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'inn' => (string) random_int(1000000000, 9999999999),
            'contractor_type' => $sourceOrganization
                ? ContractorType::INVITED_ORGANIZATION->value
                : ContractorType::MANUAL->value,
            'connected_at' => $sourceOrganization ? now() : null,
        ]);
    }

    private function createContract(
        Organization $organization,
        Project $project,
        Contractor $contractor,
        array $overrides = []
    ): Contract {
        return Contract::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'number' => 'WORK-CON-' . random_int(10000, 99999),
            'date' => '2026-06-01',
            'subject' => 'Completed work contract',
            'work_type_category' => ContractWorkTypeCategoryEnum::SMR->value,
            'base_amount' => 300000,
            'total_amount' => 300000,
            'gp_percentage' => 0,
            'planned_advance_amount' => 0,
            'actual_advance_amount' => 0,
            'status' => ContractStatusEnum::ACTIVE->value,
            'start_date' => '2026-06-01',
            'end_date' => '2026-09-01',
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ], $overrides));
    }

    private function createWorkType(Organization $organization, string $name): WorkType
    {
        return WorkType::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'code' => 'WT-' . random_int(1000, 9999),
            'default_price' => 1000,
            'is_active' => true,
        ]);
    }

    private function createCompletedWork(
        Organization $organization,
        Project $project,
        Contractor $contractor,
        array $overrides = []
    ): CompletedWork {
        $workType = $overrides['work_type_id'] ?? $this->createWorkType($organization, 'Test work type')->id;
        $userId = $overrides['user_id'] ?? User::factory()->create([
            'current_organization_id' => $organization->id,
        ])->id;

        return CompletedWork::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contract_id' => null,
            'contractor_id' => $contractor->id,
            'work_type_id' => $workType,
            'user_id' => $userId,
            'quantity' => 4,
            'completed_quantity' => null,
            'price' => 1000,
            'total_amount' => 4000,
            'completion_date' => '2026-06-12',
            'notes' => 'Completed work',
            'status' => 'confirmed',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ], $overrides));
    }

    private function attachProjectParticipant(
        Project $project,
        Organization $organization,
        ProjectOrganizationRole $role
    ): void {
        $project->organizations()->attach($organization->id, [
            'role' => $role->value,
            'role_new' => $role->value,
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
}
