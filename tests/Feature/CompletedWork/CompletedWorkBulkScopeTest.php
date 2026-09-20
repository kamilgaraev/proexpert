<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use App\Services\CompletedWork\CompletedWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkBulkScopeTest extends TestCase
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

    public function test_bulk_valid_first_and_foreign_schedule_task_writes_nothing_and_keeps_task_unchanged(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectA->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $scheduleB = ProjectSchedule::query()->create([
            'project_id' => $projectB->id,
            'organization_id' => $organizationB->id,
            'name' => 'Foreign schedule',
            'created_by_user_id' => $context->user->id,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
        ]);
        $taskB = ScheduleTask::query()->create([
            'schedule_id' => $scheduleB->id,
            'organization_id' => $organizationB->id,
            'name' => 'Foreign task',
            'created_by_user_id' => $context->user->id,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-30',
            'planned_duration_days' => 30,
            'quantity' => 10,
            'completed_quantity' => 4,
        ]);
        $dtos = [
            $this->dto($context->organization->id, $projectA->id),
            $this->dto($context->organization->id, $projectA->id, $taskB->id),
        ];

        try {
            app(CompletedWorkService::class)->createMany($dtos, $context->user);
            self::fail('Foreign schedule task must reject the whole bulk operation.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }

        $this->assertDatabaseCount('completed_works', 0);
        self::assertSame(4.0, (float) $taskB->fresh()->completed_quantity);
    }

    public function test_update_existing_work_cannot_move_from_project_a_to_project_b(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectA->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $projectA->id,
            'quantity' => 2,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]);

        try {
            app(CompletedWorkService::class)->update(
                $work->id,
                $this->dto($context->organization->id, $projectB->id),
                $context->user,
            );
            self::fail('Moving a completed work to another project must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertContains($exception->getCode(), [404, 422]);
        }

        $this->assertDatabaseHas('completed_works', ['id' => $work->id, 'project_id' => $projectA->id]);
    }

    public function test_bulk_foreign_estimate_item_after_valid_first_writes_nothing(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectA->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $organizationB = Organization::factory()->create();
        $projectB = Project::factory()->create(['organization_id' => $organizationB->id]);
        $estimateB = Estimate::query()->create([
            'organization_id' => $organizationB->id,
            'project_id' => $projectB->id,
            'name' => 'Foreign estimate',
            'number' => 'FOREIGN-EST',
            'status' => 'approved',
            'type' => 'local',
            'version' => 1,
            'estimate_date' => '2026-09-20',
        ]);
        $itemB = EstimateItem::query()->create(['estimate_id' => $estimateB->id, 'position_number' => '1', 'name' => 'Foreign item', 'quantity' => 1]);

        try {
            app(CompletedWorkService::class)->createMany([
                $this->dto($context->organization->id, $projectA->id),
                $this->dto($context->organization->id, $projectA->id, null, $itemB->id),
            ], $context->user);
            self::fail('Foreign estimate item must reject the whole bulk operation.');
        } catch (BusinessLogicException $exception) {
            self::assertContains($exception->getCode(), [404, 422]);
        }

        $this->assertDatabaseCount('completed_works', 0);
    }

    public function test_bulk_foreign_user_after_valid_first_writes_nothing(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectA->users()->attach($context->user->id, ['role' => 'member', 'is_active' => true]);
        $organizationB = Organization::factory()->create();
        $foreignUser = User::factory()->create(['current_organization_id' => $organizationB->id]);
        $organizationB->users()->attach($foreignUser->id, ['is_active' => true, 'is_owner' => true]);

        try {
            app(CompletedWorkService::class)->createMany([
                $this->dto($context->organization->id, $projectA->id),
                $this->dto($context->organization->id, $projectA->id, null, null, $foreignUser->id),
            ], $context->user);
            self::fail('Foreign user must reject the whole bulk operation.');
        } catch (BusinessLogicException $exception) {
            self::assertContains($exception->getCode(), [404, 422]);
        }

        $this->assertDatabaseCount('completed_works', 0);
    }

    private function dto(int $organizationId, int $projectId, ?int $scheduleTaskId = null, ?int $estimateItemId = null, ?int $userId = null): CompletedWorkDTO
    {
        return CompletedWorkDTO::fromModel(CompletedWork::make([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'schedule_task_id' => $scheduleTaskId,
            'estimate_item_id' => $estimateItemId,
            'user_id' => $userId,
            'quantity' => 1,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_PENDING,
            'work_origin_type' => $scheduleTaskId ? CompletedWork::ORIGIN_SCHEDULE : CompletedWork::ORIGIN_MANUAL,
            'planning_status' => $scheduleTaskId ? CompletedWork::PLANNING_PLANNED : CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ]));
    }
}
