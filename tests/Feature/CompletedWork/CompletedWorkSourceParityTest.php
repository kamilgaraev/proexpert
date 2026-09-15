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
        ]);

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/confirm"
        )->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('confirmed', $work->fresh()->status);
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
