<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\TaskDependency;
use App\Models\TaskResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectScheduleCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_project_schedule_and_tasks_without_project_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProjectSchedule = $this->createSchedule($context->organization, $anotherProject, $context->user, [
            'name' => 'Another Project Schedule',
        ]);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/schedules", [
                'project_id' => $anotherProject->id,
                'name' => 'Pilot Schedule',
                'description' => 'Initial project schedule',
                'planned_start_date' => '2026-06-01',
                'planned_end_date' => '2026-07-15',
                'status' => 'active',
                'total_estimated_cost' => 120000,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.name', 'Pilot Schedule');

        $schedule = ProjectSchedule::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($project->id, $schedule->project_id);
        $this->assertSame($context->organization->id, $schedule->organization_id);
        $this->assertSame('active', $schedule->status->value);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules?per_page=20");

        $indexResponse->assertOk();
        $scheduleIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($schedule->id, $scheduleIds);
        $this->assertNotContains($anotherProjectSchedule->id, $scheduleIds);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}", [
                'name' => 'Pilot Schedule Updated',
                'planned_end_date' => '2026-07-31',
                'is_template' => true,
                'template_name' => 'Should Not Become Template',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Pilot Schedule Updated');
        $schedule->refresh();
        $this->assertFalse((bool) $schedule->is_template);
        $this->assertSame('2026-07-31', $schedule->planned_end_date?->toDateString());

        $parentTaskResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks", [
                'name' => 'Foundation',
                'planned_start_date' => '2026-06-01',
                'planned_end_date' => '2026-06-10',
                'quantity' => 100,
                'planned_work_hours' => 80,
            ]);

        $parentTaskResponse->assertCreated();
        $parentTaskResponse->assertJsonPath('data.name', 'Foundation');
        $parentTaskResponse->assertJsonPath('data.level', 0);
        $parentTask = ScheduleTask::query()->findOrFail($parentTaskResponse->json('data.id'));
        $this->assertSame($schedule->id, $parentTask->schedule_id);
        $this->assertSame($context->organization->id, $parentTask->organization_id);
        $this->assertSame(10, $parentTask->planned_duration_days);

        $childTaskResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks", [
                'parent_task_id' => $parentTask->id,
                'name' => 'Rebar',
                'planned_start_date' => '2026-06-02',
                'planned_end_date' => '2026-06-05',
                'quantity' => 40,
                'planned_work_hours' => 32,
            ]);

        $childTaskResponse->assertCreated();
        $childTaskResponse->assertJsonPath('data.parent_task_id', $parentTask->id);
        $childTaskResponse->assertJsonPath('data.level', 1);
        $childTask = ScheduleTask::query()->findOrFail($childTaskResponse->json('data.id'));

        $tasksResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks");

        $tasksResponse->assertOk();
        $taskIds = collect($tasksResponse->json('data'))->pluck('id')->all();
        $this->assertContains($parentTask->id, $taskIds);
        $this->assertContains($childTask->id, $taskIds);

        $updateTaskResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks/{$parentTask->id}", [
                'progress_percent' => 50,
                'completed_quantity' => 50,
                'status' => 'in_progress',
            ]);

        $updateTaskResponse->assertOk();
        $updateTaskResponse->assertJsonPath('data.task.progress_percent', 50);
        $this->assertSame(50.0, (float) $parentTask->fresh()->progress_percent);

        $deleteParentResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks/{$parentTask->id}");

        $deleteParentResponse->assertStatus(409);
        $this->assertNotSoftDeleted('schedule_tasks', ['id' => $parentTask->id]);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules/{$anotherProjectSchedule->id}");

        $foreignShowResponse->assertNotFound();
    }

    public function test_schedule_cannot_be_completed_until_tasks_are_fully_done(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $schedule = $this->createSchedule($context->organization, $project, $context->user, [
            'status' => 'active',
            'overall_progress_percent' => 50,
        ]);
        $this->createTask($context->organization, $schedule, $context->user, [
            'name' => 'Incomplete task',
            'quantity' => 100,
            'completed_quantity' => 50,
            'progress_percent' => 50,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}", [
                'status' => 'completed',
            ]);

        $response->assertStatus(422);
        $this->assertSame('active', $schedule->fresh()->status->value);
    }

    public function test_owner_can_assign_and_remove_task_resource_with_schedule_scope(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $schedule = $this->createSchedule($context->organization, $project, $context->user);
        $task = $this->createTask($context->organization, $schedule, $context->user, [
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-05',
        ]);
        $assignee = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($assignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $this->allowAdminAccess();

        $assignResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks/{$task->id}/resources", [
                'resource_type' => 'user',
                'user_id' => $assignee->id,
                'allocation_percent' => 60,
                'assignment_start_date' => '2026-06-01',
                'assignment_end_date' => '2026-06-05',
                'estimated_cost' => 1200,
            ]);

        $assignResponse->assertCreated();
        $assignResponse->assertJsonPath('success', true);
        $assignResponse->assertJsonPath('data.resource_type', 'user');
        $assignResponse->assertJsonPath('data.user.id', $assignee->id);
        $assignResponse->assertJsonPath('data.estimated_cost', 1200);

        $resource = TaskResource::query()->findOrFail($assignResponse->json('data.id'));
        $this->assertSame($task->id, $resource->task_id);
        $this->assertSame($schedule->id, $resource->schedule_id);
        $this->assertSame($context->organization->id, $resource->organization_id);
        $this->assertSame($assignee->id, $resource->resource_id);
        $this->assertSame($assignee->id, $resource->user_id);
        $this->assertSame(User::class, $resource->resource_model);
        $this->assertSame(60.0, (float) $resource->allocation_percent);
        $this->assertSame(1200.0, (float) $resource->total_planned_cost);
        $this->assertSame('2026-06-01', $resource->assignment_start_date?->toDateString());
        $this->assertSame('2026-06-05', $resource->assignment_end_date?->toDateString());

        $removeResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks/{$task->id}/resources/{$resource->id}");

        $removeResponse->assertOk();
        $removeResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('task_resources', ['id' => $resource->id]);
    }

    public function test_task_resource_assignment_rejects_material_from_another_organization(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $schedule = $this->createSchedule($context->organization, $project, $context->user);
        $task = $this->createTask($context->organization, $schedule, $context->user);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignMaterialId = \DB::table('materials')->insertGetId([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Foreign material',
            'code' => 'foreign-resource-material',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/tasks/{$task->id}/resources", [
                'resource_type' => 'material',
                'material_id' => $foreignMaterialId,
                'allocation_percent' => 50,
                'assignment_start_date' => '2026-06-01',
                'assignment_end_date' => '2026-06-05',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('task_resources', [
            'task_id' => $task->id,
            'material_id' => $foreignMaterialId,
        ]);
    }

    public function test_owner_can_manage_schedule_dependencies_with_admin_contract_fields(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $schedule = $this->createSchedule($context->organization, $project, $context->user, [
            'critical_path_calculated' => true,
        ]);
        $predecessor = $this->createTask($context->organization, $schedule, $context->user, [
            'name' => 'Foundation',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-05',
        ]);
        $successor = $this->createTask($context->organization, $schedule, $context->user, [
            'name' => 'Walls',
            'planned_start_date' => '2026-06-06',
            'planned_end_date' => '2026-06-12',
        ]);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/dependencies", [
                'predecessor_task_id' => $predecessor->id,
                'successor_task_id' => $successor->id,
                'dependency_type' => 'FS',
                'lag_days' => 1,
                'lag_type' => 'days',
                'is_hard_constraint' => true,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.schedule_id', $schedule->id);
        $createResponse->assertJsonPath('data.predecessor_task_id', $predecessor->id);
        $createResponse->assertJsonPath('data.successor_task_id', $successor->id);
        $createResponse->assertJsonPath('data.dependency_type', 'FS');
        $createResponse->assertJsonPath('data.predecessor_task.name', 'Foundation');
        $createResponse->assertJsonPath('data.successor_task.name', 'Walls');

        $dependency = TaskDependency::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($schedule->id, $dependency->schedule_id);
        $this->assertSame($context->organization->id, $dependency->organization_id);
        $this->assertSame($context->user->id, $dependency->created_by_user_id);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/dependencies");

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('data.0.predecessor_task_id', $predecessor->id);
        $indexResponse->assertJsonPath('data.0.successor_task_id', $successor->id);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->patchJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/dependencies/{$dependency->id}", [
                'dependency_type' => 'SS',
                'lag_days' => 2,
                'description' => 'Start walls after layout',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.predecessor_task_id', $predecessor->id);
        $updateResponse->assertJsonPath('data.successor_task_id', $successor->id);
        $updateResponse->assertJsonPath('data.dependency_type', 'SS');
        $updateResponse->assertJsonPath('data.lag_days', 2);
        $this->assertFalse((bool) $schedule->fresh()->critical_path_calculated);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/schedules/{$schedule->id}/dependencies/{$dependency->id}");

        $deleteResponse->assertOk();
        $this->assertDatabaseMissing('task_dependencies', ['id' => $dependency->id]);
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
            'name' => 'Schedule ' . random_int(1000, 9999),
            'description' => null,
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

    private function createTask(
        Organization $organization,
        ProjectSchedule $schedule,
        User $user,
        array $overrides = []
    ): ScheduleTask {
        return ScheduleTask::query()->create(array_merge([
            'schedule_id' => $schedule->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Task ' . random_int(1000, 9999),
            'task_type' => 'task',
            'planned_start_date' => '2026-06-01',
            'planned_end_date' => '2026-06-05',
            'planned_duration_days' => 5,
            'planned_work_hours' => 40,
            'quantity' => 10,
            'progress_percent' => 0,
            'status' => 'not_started',
            'priority' => 'normal',
            'constraint_type' => 'none',
            'level' => 0,
            'sort_order' => 1,
        ], $overrides));
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
