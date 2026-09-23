<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkSourceParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_fact_defaults_to_manual_origin_and_is_not_confirmed(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            [
                'project_id' => $project->id,
                'quantity' => 1,
                'completion_date' => '2026-09-15',
                'status' => 'pending',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.work_origin_type', CompletedWork::ORIGIN_MANUAL)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_journal_origin_requires_a_journal_entry_link(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            [
                'project_id' => $project->id,
                'quantity' => 1,
                'completion_date' => '2026-09-15',
                'status' => 'pending',
                'work_origin_type' => CompletedWork::ORIGIN_JOURNAL,
            ]
        )->assertUnprocessable();
    }

    public function test_confirmed_status_is_reached_only_through_confirm_endpoint(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();

        $payload = [
            'project_id' => $project->id,
            'quantity' => 1,
            'completion_date' => '2026-09-15',
            'status' => 'confirmed',
        ];

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works",
            $payload
        )->assertUnprocessable();

        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'quantity' => 1,
            'completed_quantity' => 1,
            'completion_date' => '2026-09-15',
            'status' => 'pending',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
            'additional_info' => [
                'work_name' => 'Монтаж секции',
                'unit_of_measurement' => 'шт.',
                'location' => 'Секция А',
            ],
        ]);

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/confirm"
        )->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('confirmed', $work->fresh()->status);
    }

    public function test_officially_completed_scope_excludes_deleted_and_non_confirmed_facts(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $common = [
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'quantity' => 5,
            'completion_date' => '2026-09-15',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
        ];

        $confirmed = CompletedWork::query()->create($common + ['status' => CompletedWork::STATUS_CONFIRMED]);
        $deleted = CompletedWork::query()->create($common + ['status' => CompletedWork::STATUS_CONFIRMED]);
        $deleted->delete();

        foreach (['draft', 'pending', 'in_review', 'rejected', 'cancelled'] as $status) {
            CompletedWork::query()->create($common + ['status' => $status]);
        }

        $ids = CompletedWork::query()
            ->where('project_id', $project->id)
            ->officiallyCompleted()
            ->pluck('id')
            ->all();

        $this->assertSame([$confirmed->id], $ids);
        $this->assertSame(0.0, (new CompletedWork(['quantity' => 5, 'completed_quantity' => 0]))->effectiveCompletedQuantity());
        $this->assertSame(5.0, (new CompletedWork(['quantity' => 5, 'completed_quantity' => null]))->effectiveCompletedQuantity());
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
