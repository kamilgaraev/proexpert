<?php

declare(strict_types=1);

namespace Tests\Feature\Schedule;

use App\Enums\Schedule\TaskStatusEnum;
use App\Models\CompletedWork;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Schedule\ScheduleTaskMutationService;
use App\Services\Schedule\ScheduleTaskSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleTaskProgressRollupTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_completed_progress_repairs_stale_parent_without_changing_dates(): void
    {
        $schedule = $this->schedule();
        $parent = $this->task($schedule, ['task_type' => 'summary', 'progress_percent' => 66.67]);
        $children = collect(range(1, 3))->map(fn () => $this->task($schedule, [
            'parent_task_id' => $parent->id,
            'progress_percent' => 100,
            'status' => 'completed',
            'actual_start_date' => '2026-10-02',
            'actual_end_date' => '2026-10-12',
        ]));
        $child = $children->last();

        ScheduleTaskSyncService::withoutReverseSync(fn () => $child->updateProgress(100));

        self::assertSame(100.0, (float) $parent->fresh()->progress_percent);
        self::assertSame(TaskStatusEnum::COMPLETED, $parent->fresh()->status);
        self::assertSame('2026-10-12', $child->fresh()->actual_end_date->toDateString());
        self::assertNull($parent->fresh()->actual_start_date);
        self::assertNull($parent->fresh()->actual_end_date);
        self::assertSame(0, CompletedWork::query()->count());
    }

    public function test_rollup_uses_persisted_children_instead_of_loaded_relations(): void
    {
        $schedule = $this->schedule();
        $parent = $this->task($schedule, ['task_type' => 'summary']);
        $child = $this->task($schedule, ['parent_task_id' => $parent->id, 'progress_percent' => 40]);
        $this->task($schedule, ['parent_task_id' => $parent->id, 'progress_percent' => 20]);
        $parent->load('childTasks');
        $child->setRelation('parentTask', $parent);

        ScheduleTaskSyncService::withoutReverseSync(fn () => $child->updateProgress(80));

        self::assertSame(50.0, (float) $parent->fresh()->progress_percent);
        self::assertSame(TaskStatusEnum::IN_PROGRESS, $parent->fresh()->status);
    }

    public function test_nested_group_rollup_keeps_hour_weights_and_resets_zero_progress(): void
    {
        $schedule = $this->schedule();
        $root = $this->task($schedule, ['task_type' => 'container']);
        $parent = $this->task($schedule, ['parent_task_id' => $root->id, 'task_type' => 'summary']);
        $child = $this->task($schedule, ['parent_task_id' => $parent->id, 'planned_work_hours' => 30, 'progress_percent' => 100]);
        $this->task($schedule, ['parent_task_id' => $parent->id, 'planned_work_hours' => 10]);

        $child->updateParentProgress();

        self::assertSame(75.0, (float) $parent->fresh()->progress_percent);
        self::assertSame(75.0, (float) $root->fresh()->progress_percent);
        self::assertSame(TaskStatusEnum::IN_PROGRESS, $root->fresh()->status);

        ScheduleTaskSyncService::withoutReverseSync(fn () => $child->updateProgress(0));

        self::assertSame(0.0, (float) $root->fresh()->progress_percent);
        self::assertSame(TaskStatusEnum::NOT_STARTED, $parent->fresh()->status);
        self::assertSame(TaskStatusEnum::NOT_STARTED, $root->fresh()->status);
    }

    public function test_manual_group_states_and_non_group_parent_status_are_preserved(): void
    {
        $schedule = $this->schedule();

        foreach (['on_hold', 'cancelled', 'waiting'] as $status) {
            $parent = $this->task($schedule, ['task_type' => 'summary', 'status' => $status]);
            $child = $this->task($schedule, [
                'parent_task_id' => $parent->id, 'progress_percent' => 100, 'status' => 'completed',
            ]);

            $child->updateParentProgress();

            self::assertSame($status, $parent->fresh()->status->value);
            self::assertSame(100.0, (float) $parent->fresh()->progress_percent);
        }

        $parent = $this->task($schedule);
        $child = $this->task($schedule, [
            'parent_task_id' => $parent->id, 'progress_percent' => 100, 'status' => 'completed',
        ]);
        $child->updateParentProgress();

        self::assertSame(TaskStatusEnum::NOT_STARTED, $parent->fresh()->status);
    }

    public function test_zero_weight_unfinished_child_prevents_group_completion(): void
    {
        $schedule = $this->schedule();
        $parent = $this->task($schedule, ['task_type' => 'summary']);
        $child = $this->task($schedule, [
            'parent_task_id' => $parent->id, 'progress_percent' => 100, 'status' => 'completed',
        ]);
        $this->task($schedule, ['parent_task_id' => $parent->id, 'planned_work_hours' => 0]);

        $child->updateParentProgress();

        self::assertSame(100.0, (float) $parent->fresh()->progress_percent);
        self::assertSame(TaskStatusEnum::IN_PROGRESS, $parent->fresh()->status);
    }

    public function test_group_rollup_does_not_create_physical_work_or_actual_dates(): void
    {
        $schedule = $this->schedule();
        $this->actingAs(User::query()->findOrFail($schedule->created_by_user_id));
        $workType = WorkType::query()->create([
            'organization_id' => $schedule->organization_id,
            'name' => 'Rollup work', 'code' => 'ROLLUP', 'default_price' => 100, 'is_active' => true,
        ]);
        $parent = $this->task($schedule, ['task_type' => 'summary', 'work_type_id' => $workType->id]);
        $child = $this->task($schedule, [
            'parent_task_id' => $parent->id, 'progress_percent' => 100, 'status' => 'completed',
        ]);

        $child->updateParentProgress();

        self::assertSame(TaskStatusEnum::COMPLETED, $parent->fresh()->status);
        self::assertSame(0, CompletedWork::query()->where('schedule_task_id', $parent->id)->count());
        self::assertNull($parent->fresh()->actual_start_date);
        self::assertNull($parent->fresh()->actual_end_date);
    }

    public function test_rollup_rejects_foreign_parent_and_excludes_foreign_children(): void
    {
        $schedule = $this->schedule();
        $foreignSchedule = $this->schedule();
        $sameOrganizationSchedule = ProjectSchedule::query()->create([
            'project_id' => $schedule->project_id, 'organization_id' => $schedule->organization_id,
            'created_by_user_id' => $schedule->created_by_user_id, 'name' => 'Other schedule',
            'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-10-13',
        ]);
        $parent = $this->task($schedule, ['task_type' => 'summary']);
        $localChild = $this->task($schedule, [
            'parent_task_id' => $parent->id, 'progress_percent' => 100, 'status' => 'completed',
        ]);

        foreach ([$foreignSchedule, $sameOrganizationSchedule] as $otherSchedule) {
            $foreignChild = $this->task($otherSchedule, ['parent_task_id' => $parent->id]);
            $foreignChild->updateParentProgress();
            self::assertSame(0.0, (float) $parent->fresh()->progress_percent);
        }

        $localChild->updateParentProgress();

        self::assertSame(100.0, (float) $parent->fresh()->progress_percent);
        self::assertSame(TaskStatusEnum::COMPLETED, $parent->fresh()->status);
    }

    public function test_progress_only_mutation_returns_fresh_parent_for_inline_updates(): void
    {
        $schedule = $this->schedule();
        $parent = $this->task($schedule, ['task_type' => 'summary']);
        $child = $this->task($schedule, ['parent_task_id' => $parent->id, 'progress_percent' => 80]);

        $result = app(ScheduleTaskMutationService::class)->updateTask($schedule, $child, [
            'progress_percent' => 80,
            'intervals' => [],
        ]);

        $affectedParent = $result['affected_tasks']->firstWhere('id', $parent->id);
        self::assertNotNull($affectedParent);
        self::assertSame(80.0, (float) $affectedParent->progress_percent);
        self::assertSame(TaskStatusEnum::IN_PROGRESS, $affectedParent->status);
    }

    public function test_repeated_status_only_mutation_repairs_group_completion(): void
    {
        $schedule = $this->schedule();
        $parent = $this->task($schedule, ['task_type' => 'summary']);
        $child = $this->task($schedule, [
            'parent_task_id' => $parent->id, 'progress_percent' => 100, 'status' => 'completed',
        ]);

        $result = app(ScheduleTaskMutationService::class)->updateTask($schedule, $child, ['status' => 'completed']);

        $affectedParent = $result['affected_tasks']->firstWhere('id', $parent->id);
        self::assertNotNull($affectedParent);
        self::assertSame(100.0, (float) $affectedParent->progress_percent);
        self::assertSame(TaskStatusEnum::COMPLETED, $affectedParent->status);
    }

    private function schedule(): ProjectSchedule
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return ProjectSchedule::query()->create([
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'name' => 'Rollup schedule',
            'planned_start_date' => '2026-10-01',
            'planned_end_date' => '2026-10-13',
        ]);
    }

    private function task(ProjectSchedule $schedule, array $overrides = []): ScheduleTask
    {
        return ScheduleTask::query()->create(array_merge([
            'schedule_id' => $schedule->id,
            'organization_id' => $schedule->organization_id,
            'created_by_user_id' => $schedule->created_by_user_id,
            'name' => 'Rollup task',
            'task_type' => 'task',
            'planned_start_date' => '2026-10-02',
            'planned_end_date' => '2026-10-12',
            'planned_duration_days' => 11,
            'planned_work_hours' => 10,
            'quantity' => 100,
            'progress_percent' => 0,
            'status' => 'not_started',
        ], $overrides));
    }
}
