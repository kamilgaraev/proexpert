<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SafetyManagementMobileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreman_can_report_incident_and_resolve_violation_from_mobile_without_organization_leaks(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $incidentResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/safety-management/incidents', [
                'project_id' => $project->id,
                'title' => 'Guard rail missing',
                'incident_type' => 'unsafe_condition',
                'severity' => 'major',
                'occurred_at' => now()->toIso8601String(),
                'location_name' => 'Stair core',
                'description' => 'Guard rail is missing on the third floor',
                'immediate_actions' => 'Area blocked with tape',
            ]);

        $incidentResponse->assertCreated()
            ->assertJsonPath('data.status', 'reported')
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.available_actions.0', 'start_investigation');

        $violationResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/safety-management/violations', [
                'project_id' => $project->id,
                'title' => 'PPE missing',
                'severity' => 'critical',
                'location_name' => 'Entrance',
                'description' => 'Worker entered without helmet',
                'due_date' => now()->addDay()->toDateString(),
                'corrective_action' => 'Issue helmet and brief worker',
            ]);

        $violationResponse->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.available_actions.0', 'resolve');
        $violationId = (int) $violationResponse->json('data.id');

        $resolveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/violations/{$violationId}/resolve", [
                'resolution_comment' => 'Helmet issued, worker briefed',
            ]);

        $resolveResponse->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolved_by_user_id', $context->user->id);

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/safety-management/incidents', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign project incident',
                'incident_type' => 'unsafe_condition',
                'severity' => 'minor',
                'occurred_at' => now()->toIso8601String(),
            ]);

        $foreignProjectResponse->assertStatus(422);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'safety-management',
                    'project-management',
                    'file-management',
                ], true)
            );
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
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
