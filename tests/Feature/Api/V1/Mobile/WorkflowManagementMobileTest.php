<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkflowManagementMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_lists_assigned_workflow_tasks_and_loads_detail(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $assigned = $this->completedWork($context, $project, $workType, [
            'status' => 'pending',
            'notes' => 'Монолитный участок А',
        ]);
        $this->completedWork($context, $project, $workType, [
            'user_id' => User::factory()->create(['current_organization_id' => $context->organization->id])->id,
            'status' => 'pending',
        ]);
        $this->allowAccess(editAllowed: true);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks?assigned_to_me=1&project_id=' . $project->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $assigned->id)
            ->assertJsonPath('data.items.0.project_label', $project->name)
            ->assertJsonPath('data.items.0.work_type_label', $workType->name)
            ->assertJsonPath('data.items.0.status', 'pending')
            ->assertJsonPath('data.items.0.status_label', trans_message('workflow_management.statuses.pending'))
            ->assertJsonPath('data.summary.assigned_to_me', true);

        $this->assertContains('approve', $response->json('data.items.0.available_actions'));
        $this->assertContains('request_changes', $response->json('data.items.0.available_actions'));

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks/' . $assigned->id)
            ->assertOk()
            ->assertJsonPath('data.id', $assigned->id)
            ->assertJsonPath('data.notes', 'Монолитный участок А');
    }

    public function test_mobile_actions_persist_status_history_and_comments(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $approved = $this->completedWork($context, $project, $workType, ['status' => 'pending']);
        $changes = $this->completedWork($context, $project, $workType, ['status' => 'pending']);
        $rejected = $this->completedWork($context, $project, $workType, ['status' => 'in_review']);
        $this->allowAccess(editAllowed: true);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/' . $approved->id . '/approve', [
                'comment' => 'Объем проверен на объекте',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.status_history.0.action', 'approve')
            ->assertJsonPath('data.comments.0.comment', 'Объем проверен на объекте');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/' . $changes->id . '/request-changes', [
                'comment' => 'Нужно уточнить объем',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.status_history.0.action', 'request_changes');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/' . $rejected->id . '/reject', [
                'reason' => 'Объем не подтвержден',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.status_history.0.action', 'reject');

        $this->assertDatabaseHas('completed_works', [
            'id' => $approved->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('completed_works', [
            'id' => $changes->id,
            'status' => 'in_review',
        ]);
        $this->assertDatabaseHas('completed_works', [
            'id' => $rejected->id,
            'status' => 'rejected',
        ]);
    }

    public function test_mobile_workflow_actions_require_edit_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = $this->workType($context);
        $task = $this->completedWork($context, $project, $workType, ['status' => 'pending']);
        $this->allowAccess(editAllowed: false);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/workflow-management/tasks/' . $task->id)
            ->assertOk()
            ->assertJsonPath('data.available_actions', []);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/workflow-management/tasks/' . $task->id . '/approve')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');

        $this->assertDatabaseHas('completed_works', [
            'id' => $task->id,
            'status' => 'pending',
        ]);
    }

    private function workType(AdminApiTestContext $context): WorkType
    {
        return WorkType::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетонирование',
            'code' => 'CONCRETE',
            'is_active' => true,
        ]);
    }

    private function completedWork(
        AdminApiTestContext $context,
        Project $project,
        WorkType $workType,
        array $attributes = []
    ): CompletedWork {
        return CompletedWork::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_type_id' => $workType->id,
            'user_id' => $context->user->id,
            'quantity' => 12.5,
            'completed_quantity' => 12.5,
            'price' => 1500,
            'total_amount' => 18750,
            'completion_date' => '2026-05-20',
            'status' => 'pending',
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_PLANNED,
        ], $attributes));
    }

    private function allowAccess(bool $editAllowed): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($editAllowed): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $permission === 'completed_works.view'
                    || ($permission === 'completed_works.edit' && $editAllowed)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
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
