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

    public function test_body_project_from_another_organization_is_rejected_without_a_write(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create();
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$projectA->id}/works",
            [
                'project_id' => $projectB->id,
                'quantity' => 1,
                'completion_date' => '2026-09-20',
                'status' => CompletedWork::STATUS_PENDING,
            ],
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('completed_works', ['project_id' => $projectB->id]);
    }

    public function test_bulk_foreign_schedule_task_is_rejected_before_any_work_or_task_recalculation(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create();
        $scheduleB = ProjectSchedule::factory()->create([
            'project_id' => $projectB->id,
            'organization_id' => $projectB->organization_id,
        ]);
        $taskB = ScheduleTask::factory()->create([
            'schedule_id' => $scheduleB->id,
            'organization_id' => $projectB->organization_id,
            'completed_quantity' => 7,
        ]);
        $this->allowAdminAccess();

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
