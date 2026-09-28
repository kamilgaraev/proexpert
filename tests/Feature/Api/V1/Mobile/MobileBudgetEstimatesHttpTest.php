<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\UserProjectAccessMode;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileBudgetEstimatesHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_list_detail_and_approval_actions_use_the_http_contract_and_persist_transitions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->grantProject($context, $project);
        $estimate = $this->createEstimate($context, $project, 'in_review', 'APPROVE');
        $requestChangesEstimate = $this->createEstimate($context, $project, 'in_review', 'RETURN');
        $this->allowAccess();

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/budget-estimates/estimates?project_id='.$project->id.'&search='.$estimate->number)
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $estimate->id)
            ->assertJsonPath('data.items.0.available_actions', ['approve', 'request_changes'])
            ->assertJsonPath('data.meta.total', 1);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/budget-estimates/estimates/'.$estimate->id)
            ->assertOk()
            ->assertJsonPath('data.estimate.id', $estimate->id)
            ->assertJsonPath('data.estimate.status', 'in_review');

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/budget-estimates/estimates/'.$estimate->id.'/approve', ['comment' => 'Проверено'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('estimates', [
            'id' => $estimate->id,
            'status' => 'approved',
            'approved_by_user_id' => $context->user->id,
        ]);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/budget-estimates/estimates/'.$estimate->id.'/request-changes', ['comment' => 'Повторный переход недопустим'])
            ->assertStatus(422);

        $this->assertDatabaseHas('estimates', ['id' => $estimate->id, 'status' => 'approved']);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/budget-estimates/estimates/'.$requestChangesEstimate->id.'/request-changes', [
                'comment' => 'Уточнить объём работ',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $requestChangesEstimate->refresh();
        self::assertSame('draft', $requestChangesEstimate->status);
        self::assertSame('Уточнить объём работ', $requestChangesEstimate->metadata['approval_history'][0]['comment']);
    }

    public function test_mobile_budget_estimate_http_endpoints_enforce_permission_and_project_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);
        $allowedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $hiddenProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->grantProject($context, $allowedProject);
        $hiddenEstimate = $this->createEstimate($context, $hiddenProject, 'in_review', 'SCOPE');
        $this->allowAccess();

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/budget-estimates/estimates?project_id='.$hiddenProject->id)
            ->assertUnprocessable();

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/budget-estimates/estimates/'.$hiddenEstimate->id)
            ->assertNotFound();

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/budget-estimates/estimates/'.$hiddenEstimate->id.'/approve')
            ->assertUnprocessable();

        $this->assertDatabaseHas('estimates', ['id' => $hiddenEstimate->id, 'status' => 'in_review']);
    }

    public function test_mobile_budget_estimate_actions_require_approval_permission(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->grantProject($context, $project);
        $estimate = $this->createEstimate($context, $project, 'in_review', 'DENIED');
        $this->allowAccess(canApprove: false);

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/budget-estimates/estimates/'.$estimate->id.'/approve')
            ->assertForbidden();

        $this->assertDatabaseHas('estimates', ['id' => $estimate->id, 'status' => 'in_review']);
    }

    private function grantProject(AdminApiTestContext $context, Project $project): void
    {
        $context->user->assignedProjects()->attach($project->id, [
            'is_active' => true,
            'role' => 'member',
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
    }

    private function createEstimate(AdminApiTestContext $context, Project $project, string $status, string $case): Estimate
    {
        return Estimate::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'number' => 'MOB-'.$project->id.'-'.$case,
            'name' => 'Mobile estimate '.$status,
            'type' => 'local',
            'status' => $status,
            'version' => 1,
            'estimate_date' => now()->toDateString(),
            'total_amount' => 120000,
            'total_amount_with_vat' => 144000,
            'metadata' => [],
        ]);
    }

    private function allowAccess(bool $canApprove = true): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($canApprove): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $permission !== 'budget-estimates.approve' || $canApprove
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
