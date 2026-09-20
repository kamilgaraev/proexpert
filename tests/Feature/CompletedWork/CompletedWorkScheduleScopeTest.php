<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\CompletedWork\CompletedWorkFactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkScheduleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        \Illuminate\Support\Facades\Http::fake(['nominatim.openstreetmap.org/*' => \Illuminate\Support\Facades\Http::response([], 200)]);
    }

    public function test_attach_to_foreign_task_rejects_without_reassigning_work_or_recalculating_task(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $projectA->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $scheduleB = $this->schedule($projectB, $context->user->id);
        $taskB = $this->task($scheduleB, $context->user->id, 6);
        $work = $this->work($context->organization->id, $projectA->id);

        try {
            app(CompletedWorkFactService::class)->attachToTask($work, $taskB, $context->user);
            self::fail('Foreign task must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }

        $this->assertDatabaseHas('completed_works', ['id' => $work->id, 'project_id' => $projectA->id, 'schedule_task_id' => null]);
        self::assertSame(6.0, (float) $taskB->fresh()->completed_quantity);
    }

    public function test_create_task_from_foreign_schedule_rejects_without_creating_task(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $projectA->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $scheduleB = $this->schedule($projectB, $context->user->id);
        $work = $this->work($context->organization->id, $projectA->id);

        try {
            app(CompletedWorkFactService::class)->createTaskFromWork($work, $scheduleB, $context->user->id);
            self::fail('Foreign schedule must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }

        self::assertSame(0, ScheduleTask::query()->where('schedule_id', $scheduleB->id)->count());
        $this->assertDatabaseHas('completed_works', ['id' => $work->id, 'project_id' => $projectA->id, 'schedule_task_id' => null]);
    }

    public function test_read_only_actor_cannot_attach_even_to_same_project_task(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $schedule = $this->schedule($project, $context->user->id);
        $task = $this->task($schedule, $context->user->id, 6);
        $work = $this->work($context->organization->id, $project->id);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnFalse();

        try {
            app(CompletedWorkFactService::class)->attachToTask($work, $task, $context->user);
            self::fail('Read-only actor must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }

        $this->assertDatabaseHas('completed_works', ['id' => $work->id, 'schedule_task_id' => null]);
        try {
            app(CompletedWorkFactService::class)->createTaskFromWork($work, $schedule, $context->user->id);
            self::fail('Read-only actor must not create a task from work.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }
        self::assertSame(1, ScheduleTask::query()->where('schedule_id', $schedule->id)->count());
    }

    public function test_actor_can_attach_to_task_in_same_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $schedule = $this->schedule($project, $context->user->id);
        $task = $this->task($schedule, $context->user->id, 0);
        $work = $this->work($context->organization->id, $project->id);

        app(CompletedWorkFactService::class)->attachToTask($work, $task, $context->user);

        $this->assertDatabaseHas('completed_works', ['id' => $work->id, 'schedule_task_id' => $task->id, 'project_id' => $project->id]);
    }

    public function test_actor_can_create_task_from_schedule_in_same_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $schedule = $this->schedule($project, $context->user->id);
        $work = $this->work($context->organization->id, $project->id);

        $task = app(CompletedWorkFactService::class)->createTaskFromWork($work, $schedule, $context->user->id);

        self::assertSame($schedule->id, $task->schedule_id);
        $this->assertDatabaseHas('completed_works', ['id' => $work->id, 'schedule_task_id' => $task->id, 'project_id' => $project->id]);
    }

    private function work(int $organizationId, int $projectId): CompletedWork
    {
        return CompletedWork::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'quantity' => 2,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);
    }

    private function schedule(Project $project, int $userId): ProjectSchedule
    {
        return ProjectSchedule::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'created_by_user_id' => $userId,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
            'name' => 'Scope schedule',
        ]);
    }

    private function task(ProjectSchedule $schedule, int $userId, int $completedQuantity): ScheduleTask
    {
        return ScheduleTask::query()->create([
            'organization_id' => $schedule->organization_id,
            'schedule_id' => $schedule->id,
            'created_by_user_id' => $userId,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
            'planned_duration_days' => 30,
            'name' => 'Scope task',
            'quantity' => 10,
            'completed_quantity' => $completedQuantity,
        ]);
    }
}
