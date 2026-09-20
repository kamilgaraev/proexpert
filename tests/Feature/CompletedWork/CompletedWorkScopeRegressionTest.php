<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Models\CompletedWork;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkScopeRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_body_project_different_from_route_is_rejected_without_a_write(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$projectA->id}/works",
            [
                'project_id' => $projectB->id,
                'quantity' => 1,
                'completion_date' => '2026-09-20',
                'status' => CompletedWork::STATUS_PENDING,
            ],
        );

        $response->assertStatus(404);
        $this->assertDatabaseMissing('completed_works', ['project_id' => $projectB->id]);
    }

    public function test_bulk_foreign_schedule_task_is_rejected_before_any_work_or_task_recalculation(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $scheduleB = ProjectSchedule::query()->create([
            'project_id' => $projectB->id,
            'organization_id' => $projectB->organization_id,
            'name' => 'Чужой график',
            'created_by_user_id' => $context->user->id,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
        ]);
        $taskB = ScheduleTask::query()->create([
            'schedule_id' => $scheduleB->id,
            'organization_id' => $projectB->organization_id,
            'name' => 'Чужая задача',
            'created_by_user_id' => $context->user->id,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
            'planned_duration_days' => 30,
            'quantity' => 10,
            'completed_quantity' => 7,
        ]);

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$projectA->id}/works/bulk",
            [
                'works' => [[
                    'schedule_task_id' => $taskB->id,
                    'quantity' => 1,
                    'completion_date' => '2026-09-20',
                    'status' => CompletedWork::STATUS_PENDING,
                ]],
            ],
        );

        $response->assertStatus(404);
        $this->assertDatabaseMissing('completed_works', ['project_id' => $projectA->id]);
        $this->assertSame(7.0, (float) $taskB->fresh()->completed_quantity);
    }
}
