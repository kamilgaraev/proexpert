<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Models\CompletedWork;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Services\CompletedWork\CompletedWorkService;
use App\Services\CompletedWork\CompletedWorkWorkflowService;
use App\Exceptions\BusinessLogicException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkScopeRegressionTest extends TestCase
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

    public function test_authorized_service_call_uses_current_organization_without_http_context(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $dto = CompletedWorkDTO::fromModel(CompletedWork::make([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'quantity' => 1, 'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]));
        $work = app(CompletedWorkService::class)->create($dto, null, $context->user);
        self::assertSame($project->id, $work->project_id);
        self::assertSame(1.0, (float) $work->completed_quantity);
    }

    public function test_read_only_actor_cannot_update_delete_or_confirm_existing_work(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'quantity' => 1, 'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnFalse();
        $service = app(CompletedWorkService::class);
        foreach ([
            fn () => $service->update($work->id, CompletedWorkDTO::fromModel($work), $context->user),
            fn () => $service->delete($work->id, $context->organization->id, $context->user),
            fn () => app(CompletedWorkWorkflowService::class)->confirm($work, $context->user),
        ] as $operation) {
            try {
                $operation();
                self::fail('Пользователь без права записи не должен менять факт');
            } catch (BusinessLogicException $exception) {
                self::assertSame(403, $exception->getCode());
            }
        }
        self::assertSame(CompletedWork::STATUS_PENDING, $work->fresh()->status);
        self::assertNull($work->fresh()->deleted_at);
    }

    public function test_write_role_in_project_a_does_not_grant_write_in_accessible_project_b(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        foreach ([$projectA, $projectB] as $project) {
            $project->users()->syncWithoutDetaching([$context->user->id => ['role' => 'member', 'is_active' => true]]);
        }
        $projectContext = \App\Domain\Authorization\Models\AuthorizationContext::getProjectContext($projectA->id, $context->organization->id);
        $context->user->roleAssignments()->delete();
        \App\Domain\Authorization\Models\UserRoleAssignment::assignRole(
            user: $context->user,
            roleSlug: 'project_manager',
            context: $projectContext,
        );
        $service = app(CompletedWorkService::class);
        foreach ([$projectA, $projectB] as $project) {
            $dto = CompletedWorkDTO::fromModel(CompletedWork::make([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'quantity' => 1, 'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_PENDING,
                'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
                'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
            ]));
            if ($project->is($projectA)) {
                self::assertSame($projectA->id, $service->create($dto, null, $context->user)->project_id);
                continue;
            }
            try {
                $service->create($dto, null, $context->user);
                self::fail('Write access must not spread to a sibling project.');
            } catch (BusinessLogicException $exception) {
                self::assertSame(403, $exception->getCode());
            }
        }
        $this->assertDatabaseMissing('completed_works', ['project_id' => $projectB->id]);
    }

    public function test_service_rejects_read_only_actor_even_when_organization_owns_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnFalse();
        $dto = CompletedWorkDTO::fromModel(CompletedWork::make([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'quantity' => 1,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]));
        try {
            app(CompletedWorkService::class)->create($dto, null, $context->user);
            self::fail('Право организации на проект не должно заменять право пользователя создавать работы');
        } catch (BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
            $this->assertDatabaseMissing('completed_works', ['project_id' => $project->id]);
        }
    }

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
