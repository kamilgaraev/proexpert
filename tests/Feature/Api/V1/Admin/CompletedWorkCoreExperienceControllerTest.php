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
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
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

    public function test_observer_project_role_cannot_mutate_completed_works(): void
    {
        $observerContext = AdminApiTestContext::create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Observer Works Project',
        ]);
        $this->attachProjectParticipant($project, $observerContext->organization, ProjectOrganizationRole::OBSERVER);

        $contractor = $this->createContractor($ownerOrganization, 'Observer Work Contractor');
        $contract = $this->createContract($ownerOrganization, $project, $contractor, ['number' => 'OBS-WORK-CON']);
        $workType = $this->createWorkType($ownerOrganization, 'Observer blocked work');
        $work = $this->createCompletedWork($ownerOrganization, $project, $contractor, [
            'contract_id' => $contract->id,
            'work_type_id' => $workType->id,
            'notes' => 'Observer cannot mutate this',
        ]);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($observerContext->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works", [
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'work_type_id' => $workType->id,
                'quantity' => 1,
                'price' => 100,
                'completion_date' => '2026-06-20',
                'status' => 'pending',
            ]);

        $createResponse->assertForbidden();

        $updateResponse = $this->withHeaders($observerContext->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}", [
                'notes' => 'OBSERVER-UPDATE',
            ]);

        $updateResponse->assertForbidden();
        $this->assertSame('Observer cannot mutate this', $work->fresh()->notes);

        $deleteResponse = $this->withHeaders($observerContext->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}");

        $deleteResponse->assertForbidden();
        $this->assertNotSoftDeleted('completed_works', ['id' => $work->id]);
    }

    public function test_bulk_completed_work_create_rejects_foreign_contract_and_contractor_ids(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignContractor = $this->createContractor($foreignOrganization, 'Foreign Bulk Contractor');
        $foreignContract = $this->createContract($foreignOrganization, $foreignProject, $foreignContractor, [
            'number' => 'FOREIGN-BULK-CON',
        ]);
        $this->allowAdminAccess();

        $contractResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/bulk", [
                'works' => [[
                    'contract_id' => $foreignContract->id,
                    'quantity' => 1,
                    'completion_date' => '2026-06-20',
                    'status' => 'pending',
                ]],
        ]);

        $contractResponse->assertStatus(422);
        $contractResponse->assertJsonPath('errors.index', 0);
        $this->assertArrayHasKey('contract_id', $contractResponse->json('errors.errors'));

        $contractorResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/bulk", [
                'works' => [[
                    'contractor_id' => $foreignContractor->id,
                    'quantity' => 1,
                    'completion_date' => '2026-06-20',
                    'status' => 'pending',
                ]],
            ]);

        $contractorResponse->assertStatus(422);
        $contractorResponse->assertJsonPath('errors.index', 0);
        $this->assertArrayHasKey('contractor_id', $contractorResponse->json('errors.errors'));
    }

    public function test_completed_work_create_rejects_foreign_user_schedule_task_and_estimate_item(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Scoped Create Work Contractor');
        $workType = $this->createWorkType($context->organization, 'Scoped create work');

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = User::factory()->create(['current_organization_id' => $foreignOrganization->id]);
        $foreignOrganization->users()->attach($foreignUser->id, ['is_owner' => true, 'is_active' => true]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignSchedule = $this->createSchedule($foreignOrganization, $foreignProject, $foreignUser);
        $foreignTask = $this->createScheduleTask($foreignOrganization, $foreignSchedule, $foreignUser);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignEstimateItem = $this->createEstimateItem($foreignEstimate);
        $this->allowAdminAccess();

        $basePayload = [
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'work_type_id' => $workType->id,
            'quantity' => 3,
            'price' => 1000,
            'completion_date' => '2026-06-22',
            'status' => 'pending',
        ];

        $userResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works", $basePayload + [
                'user_id' => $foreignUser->id,
            ]);

        $userResponse->assertStatus(422);
        $userResponse->assertJsonValidationErrors(['user_id']);

        $taskResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works", $basePayload + [
                'schedule_task_id' => $foreignTask->id,
            ]);

        $taskResponse->assertStatus(422);
        $taskResponse->assertJsonValidationErrors(['schedule_task_id']);

        $estimateItemResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works", $basePayload + [
                'estimate_item_id' => $foreignEstimateItem->id,
            ]);

        $estimateItemResponse->assertStatus(422);
        $estimateItemResponse->assertJsonValidationErrors(['estimate_item_id']);

        $this->assertDatabaseMissing('completed_works', [
            'project_id' => $project->id,
            'user_id' => $foreignUser->id,
        ]);
        $this->assertDatabaseMissing('completed_works', [
            'project_id' => $project->id,
            'schedule_task_id' => $foreignTask->id,
        ]);
        $this->assertDatabaseMissing('completed_works', [
            'project_id' => $project->id,
            'estimate_item_id' => $foreignEstimateItem->id,
        ]);
    }

    public function test_completed_work_update_rejects_foreign_user_schedule_task_and_estimate_item_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Scoped Update Work Contractor');
        $work = $this->createCompletedWork($context->organization, $project, $contractor, [
            'notes' => 'Original scoped work',
            'user_id' => $context->user->id,
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUser = User::factory()->create(['current_organization_id' => $foreignOrganization->id]);
        $foreignOrganization->users()->attach($foreignUser->id, ['is_owner' => true, 'is_active' => true]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignSchedule = $this->createSchedule($foreignOrganization, $foreignProject, $foreignUser);
        $foreignTask = $this->createScheduleTask($foreignOrganization, $foreignSchedule, $foreignUser);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignEstimateItem = $this->createEstimateItem($foreignEstimate);
        $this->allowAdminAccess();

        $userResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}", [
                'user_id' => $foreignUser->id,
                'notes' => 'SHOULD-NOT-CHANGE-USER',
            ]);

        $userResponse->assertStatus(422);
        $userResponse->assertJsonValidationErrors(['user_id']);
        $this->assertSame('Original scoped work', $work->fresh()->notes);
        $this->assertSame($context->user->id, $work->fresh()->user_id);

        $taskResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}", [
                'schedule_task_id' => $foreignTask->id,
                'notes' => 'SHOULD-NOT-CHANGE-TASK',
            ]);

        $taskResponse->assertStatus(422);
        $taskResponse->assertJsonValidationErrors(['schedule_task_id']);
        $this->assertSame('Original scoped work', $work->fresh()->notes);
        $this->assertNull($work->fresh()->schedule_task_id);

        $estimateItemResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}", [
                'estimate_item_id' => $foreignEstimateItem->id,
                'notes' => 'SHOULD-NOT-CHANGE-ESTIMATE',
            ]);

        $estimateItemResponse->assertStatus(422);
        $estimateItemResponse->assertJsonValidationErrors(['estimate_item_id']);
        $this->assertSame('Original scoped work', $work->fresh()->notes);
        $this->assertNull($work->fresh()->estimate_item_id);
    }

    public function test_owner_can_attach_completed_work_to_existing_schedule_task(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Schedule Attach Contractor');
        $workType = $this->createWorkType($context->organization, 'Schedule attach work');
        $schedule = $this->createSchedule($context->organization, $project, $context->user);
        $anotherSchedule = $this->createSchedule($context->organization, $anotherProject, $context->user);
        $task = $this->createScheduleTask($context->organization, $schedule, $context->user, [
            'name' => 'Attach target task',
            'work_type_id' => $workType->id,
            'quantity' => 10,
            'completed_quantity' => 0,
        ]);
        $foreignTask = $this->createScheduleTask($context->organization, $anotherSchedule, $context->user, [
            'name' => 'Foreign task',
        ]);
        $work = $this->createCompletedWork($context->organization, $project, $contractor, [
            'work_type_id' => $workType->id,
            'quantity' => 4,
            'completed_quantity' => 4,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);
        $this->allowAdminAccess();

        $tasksResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedule-tasks");

        $tasksResponse->assertOk();
        $taskIds = collect($tasksResponse->json('data'))->pluck('id')->all();
        $this->assertContains($task->id, $taskIds);
        $this->assertNotContains($foreignTask->id, $taskIds);

        $attachResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}/attach-schedule-task", [
                'schedule_task_id' => $task->id,
            ]);

        $attachResponse->assertOk();
        $attachResponse->assertJsonPath('data.id', $work->id);
        $attachResponse->assertJsonPath('data.schedule_task_id', $task->id);

        $work->refresh();
        $task->refresh();
        $this->assertSame($task->id, $work->schedule_task_id);
        $this->assertSame(CompletedWork::PLANNING_PLANNED, $work->planning_status);
        $this->assertSame(4.0, (float) $task->completed_quantity);
        $this->assertSame(40.0, (float) $task->progress_percent);

        $anotherWork = $this->createCompletedWork($context->organization, $project, $contractor, [
            'work_type_id' => $workType->id,
            'notes' => 'Should stay unlinked',
        ]);

        $foreignAttachResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/{$anotherWork->id}/attach-schedule-task", [
                'schedule_task_id' => $foreignTask->id,
            ]);

        $foreignAttachResponse->assertNotFound();
        $this->assertNull($anotherWork->fresh()->schedule_task_id);
    }

    public function test_owner_can_create_schedule_task_from_completed_work(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Schedule Create Contractor');
        $workType = $this->createWorkType($context->organization, 'Schedule create work');
        $schedule = $this->createSchedule($context->organization, $project, $context->user);
        $anotherSchedule = $this->createSchedule($context->organization, $anotherProject, $context->user);
        $work = $this->createCompletedWork($context->organization, $project, $contractor, [
            'work_type_id' => $workType->id,
            'quantity' => 5,
            'completed_quantity' => 2,
            'completion_date' => '2026-06-21',
            'notes' => 'Task created from actual work',
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);
        $this->allowAdminAccess();

        $createTaskResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}/create-schedule-task", [
                'schedule_id' => $schedule->id,
            ]);

        $createTaskResponse->assertCreated();
        $createTaskResponse->assertJsonPath('data.work.id', $work->id);
        $createTaskResponse->assertJsonPath('data.work.planning_status', CompletedWork::PLANNING_PLANNED);
        $createTaskResponse->assertJsonPath('data.task.schedule_id', $schedule->id);
        $createTaskResponse->assertJsonPath('data.task.progress_percent', 40);
        $createTaskResponse->assertJsonPath('data.task.completed_quantity', 2);

        $task = ScheduleTask::query()->findOrFail($createTaskResponse->json('data.task.id'));
        $work->refresh();
        $this->assertSame($task->id, $work->schedule_task_id);
        $this->assertSame($context->organization->id, $task->organization_id);
        $this->assertSame($workType->id, $task->work_type_id);
        $this->assertSame('2026-06-21', $task->planned_start_date?->toDateString());
        $this->assertSame('in_progress', $task->status->value);

        $anotherWork = $this->createCompletedWork($context->organization, $project, $contractor, [
            'work_type_id' => $workType->id,
            'notes' => 'Should not create in another project schedule',
        ]);

        $foreignScheduleResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/{$anotherWork->id}/create-schedule-task", [
                'schedule_id' => $anotherSchedule->id,
            ]);

        $foreignScheduleResponse->assertNotFound();
        $this->assertNull($anotherWork->fresh()->schedule_task_id);
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

    private function createSchedule(
        Organization $organization,
        Project $project,
        User $user,
        array $overrides = []
    ): ProjectSchedule {
        return ProjectSchedule::query()->create(array_merge([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Works schedule ' . random_int(1000, 9999),
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-07-01',
            'status' => 'draft',
            'is_template' => false,
            'calculation_settings' => [
                'auto_schedule' => true,
                'level_resources' => false,
                'working_days_per_week' => 5,
                'working_hours_per_day' => 8,
            ],
            'display_settings' => [
                'show_critical_path' => true,
                'show_float' => false,
                'show_baseline' => false,
            ],
            'critical_path_calculated' => false,
            'overall_progress_percent' => 0,
        ], $overrides));
    }

    private function createScheduleTask(
        Organization $organization,
        ProjectSchedule $schedule,
        User $user,
        array $overrides = []
    ): ScheduleTask {
        return ScheduleTask::query()->create(array_merge([
            'schedule_id' => $schedule->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Works task ' . random_int(1000, 9999),
            'task_type' => 'task',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-05',
            'planned_duration_days' => 5,
            'planned_work_hours' => 40,
            'quantity' => 10,
            'completed_quantity' => 0,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 0,
            'sort_order' => 1,
        ], $overrides));
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-WORK-' . random_int(10000, 99999),
            'name' => 'Completed work estimate',
            'type' => 'local',
            'status' => 'approved',
            'estimate_date' => '2026-06-01',
            'total_direct_costs' => 10000,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_amount' => 10000,
            'total_amount_with_vat' => 12000,
        ], $overrides));
    }

    private function createEstimateItem(Estimate $estimate, array $overrides = []): EstimateItem
    {
        return EstimateItem::query()->create(array_merge([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Completed work estimate item',
            'quantity' => 10,
            'unit_price' => 1000,
            'direct_costs' => 10000,
            'overhead_amount' => 0,
            'profit_amount' => 0,
            'total_amount' => 10000,
            'is_manual' => true,
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
