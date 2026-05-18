<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SafetyManagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_permit_incident_and_violation_lifecycles_are_scoped_and_guarded(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $assignee = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($assignee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $permitResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/work-permits', [
                'project_id' => $project->id,
                'title' => 'Hot works in section A',
                'permit_type' => 'hot_work',
                'location_name' => 'Section A / Floor 2',
                'valid_from' => now()->addDay()->toDateString(),
                'valid_until' => now()->addDays(2)->toDateString(),
                'responsible_user_id' => $assignee->id,
                'risk_level' => 'high',
                'required_controls' => ['fire_extinguisher', 'spotter'],
            ]);

        $permitResponse->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.available_actions.0', 'submit')
            ->assertJsonPath('data.workflow_summary.status', 'draft');
        $permitId = (int) $permitResponse->json('data.id');

        $submittedPermit = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/submit");
        $submittedPermit->assertOk()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.available_actions.0', 'approve');

        $approvedPermit = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/approve", [
                'comment' => 'Controls verified',
            ]);
        $approvedPermit->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by_user_id', $context->user->id);

        $activePermit = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/activate");
        $activePermit->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.available_actions.0', 'suspend');

        $suspendedPermit = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/suspend", [
                'reason' => 'Wind speed exceeded safe limit',
            ]);
        $suspendedPermit->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.available_actions.0', 'resume');

        $resumedPermit = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/resume");
        $resumedPermit->assertOk()
            ->assertJsonPath('data.status', 'active');

        $closedPermit = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/close", [
                'close_comment' => 'Works finished safely',
            ]);
        $closedPermit->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $incidentResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/incidents', [
                'project_id' => $project->id,
                'title' => 'Worker slipped near entrance',
                'incident_type' => 'near_miss',
                'severity' => 'major',
                'occurred_at' => now()->subHour()->toIso8601String(),
                'location_name' => 'Main entrance',
                'description' => 'No injury, unsafe surface found',
                'immediate_actions' => 'Area isolated',
            ]);

        $incidentResponse->assertCreated()
            ->assertJsonPath('data.status', 'reported')
            ->assertJsonPath('data.available_actions.0', 'triage')
            ->assertJsonPath('data.problem_flags.0.code', 'investigation_required');
        $incidentId = (int) $incidentResponse->json('data.id');

        $triageResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/incidents/{$incidentId}/triage", [
                'comment' => 'Needs formal investigation',
            ]);
        $triageResponse->assertOk()
            ->assertJsonPath('data.status', 'triage')
            ->assertJsonPath('data.available_actions.0', 'start_investigation');

        $investigationResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/incidents/{$incidentId}/start-investigation", [
                'assigned_to_user_id' => $assignee->id,
            ]);
        $investigationResponse->assertOk()
            ->assertJsonPath('data.status', 'investigation')
            ->assertJsonPath('data.assigned_to_user_id', $assignee->id);

        $correctiveStage = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/incidents/{$incidentId}/corrective-actions", [
                'root_cause' => 'Ice on access path',
            ]);
        $correctiveStage->assertOk()
            ->assertJsonPath('data.status', 'corrective_actions');

        $blockedCloseResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/incidents/{$incidentId}/close", [
                'root_cause' => 'Ice on access path',
                'corrective_actions' => 'Surface cleaned and anti-slip mats installed',
            ]);
        $blockedCloseResponse->assertStatus(422);

        $correctiveAction = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/corrective-actions', [
                'incident_id' => $incidentId,
                'title' => 'Install anti-slip mats',
                'description' => 'Install anti-slip mats at the main entrance',
                'severity' => 'high',
                'assigned_to_user_id' => $assignee->id,
                'due_date' => now()->addDay()->toDateString(),
            ]);
        $correctiveAction->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.available_actions.0', 'resolve');
        $correctiveActionId = (int) $correctiveAction->json('data.id');

        $resolvedAction = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/corrective-actions/{$correctiveActionId}/resolve", [
                'resolution_comment' => 'Mats installed',
            ]);
        $resolvedAction->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.available_actions.0', 'verify');

        $verifiedAction = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/corrective-actions/{$correctiveActionId}/verify", [
                'verification_comment' => 'Verified on site',
            ]);
        $verifiedAction->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $closedIncident = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/incidents/{$incidentId}/close", [
                'root_cause' => 'Ice on access path',
                'corrective_actions' => 'Surface cleaned and anti-slip mats installed',
            ]);
        $closedIncident->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_by_user_id', $context->user->id);

        $briefingResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/briefings', [
                'project_id' => $project->id,
                'title' => 'Toolbox talk before hot works',
                'briefing_type' => 'toolbox',
                'conducted_at' => now()->toIso8601String(),
                'topics' => ['hot works', 'fire watch'],
                'participants' => [
                    ['user_id' => $assignee->id],
                    ['external_name' => 'Ivan Petrov', 'company_name' => 'Subcontractor LLC', 'role_name' => 'welder'],
                ],
            ]);
        $briefingResponse->assertCreated()
            ->assertJsonCount(2, 'data.participants');
        $briefingParticipants = collect($briefingResponse->json('data.participants'));
        $this->assertTrue($briefingParticipants->contains('user_id', $assignee->id));
        $this->assertTrue($briefingParticipants->contains('external_name', 'Ivan Petrov'));

        $violationResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/violations', [
                'project_id' => $project->id,
                'title' => 'Missing harness',
                'severity' => 'critical',
                'location_name' => 'Roof edge',
                'description' => 'Subcontractor worker without harness',
                'assigned_to_user_id' => $assignee->id,
                'due_date' => now()->addDay()->toDateString(),
                'corrective_action' => 'Suspend roof access until harnesses are issued',
            ]);

        $violationResponse->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.available_actions.0', 'resolve');
        $violationId = (int) $violationResponse->json('data.id');

        $resolvedViolation = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/violations/{$violationId}/resolve", [
                'resolution_comment' => 'Harnesses issued and toolbox talk completed',
            ]);
        $resolvedViolation->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolved_by_user_id', $context->user->id);

        SafetyWorkPermit::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'created_by_user_id' => $foreignContext->user->id,
            'permit_number' => 'HSE-P-foreign-' . uniqid(),
            'title' => 'Foreign permit',
            'permit_type' => 'confined_space',
            'valid_from' => now()->addDay(),
            'valid_until' => now()->addDays(2),
            'risk_level' => 'medium',
            'status' => 'draft',
        ]);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/safety-management/work-permits?per_page=50');

        $listResponse->assertOk();
        $permitIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertContains($permitId, $permitIds);
        $this->assertNotContains('Foreign permit', collect($listResponse->json('data'))->pluck('title')->all());
        $this->assertTrue(SafetyCorrectiveAction::query()->where('incident_id', $incidentId)->where('status', 'verified')->exists());
    }

    public function test_expired_permit_cannot_be_activated(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $permitResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/work-permits', [
                'project_id' => $project->id,
                'title' => 'Expired hot works',
                'permit_type' => 'hot_work',
                'valid_from' => now()->subDays(3)->toDateString(),
                'valid_until' => now()->subDay()->toDateString(),
                'risk_level' => 'high',
            ]);

        $permitResponse->assertCreated();
        $permitId = (int) $permitResponse->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/submit")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $activateResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permitId}/activate");

        $activateResponse->assertStatus(422);
        self::assertTrue(SafetyWorkPermit::query()->whereKey($permitId)->where('status', 'approved')->exists());
    }

    public function test_corrective_action_requires_exactly_one_source(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $incidentResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/incidents', [
                'project_id' => $project->id,
                'title' => 'Unsafe scaffold',
                'incident_type' => 'unsafe_condition',
                'severity' => 'major',
                'occurred_at' => now()->subHour()->toIso8601String(),
            ]);
        $incidentResponse->assertCreated();

        $violationResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/violations', [
                'project_id' => $project->id,
                'title' => 'Missing guard rail',
                'severity' => 'high',
            ]);
        $violationResponse->assertCreated();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/corrective-actions', [
                'incident_id' => $incidentResponse->json('data.id'),
                'violation_id' => $violationResponse->json('data.id'),
                'title' => 'Fix source ambiguity',
                'severity' => 'high',
            ]);

        $response->assertStatus(422);
        self::assertDatabaseMissing('safety_corrective_actions', [
            'title' => 'Fix source ambiguity',
        ]);
    }

    public function test_overdue_corrective_action_returns_problem_flag(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $incidentResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/incidents', [
                'project_id' => $project->id,
                'title' => 'Unsafe passage',
                'incident_type' => 'unsafe_condition',
                'severity' => 'high',
                'occurred_at' => now()->subHour()->toIso8601String(),
            ]);
        $incidentResponse->assertCreated();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/corrective-actions', [
                'incident_id' => $incidentResponse->json('data.id'),
                'title' => 'Install barrier',
                'severity' => 'high',
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.problem_flags.0.code', 'corrective_action_overdue')
            ->assertJsonPath('data.problem_flags.0.severity', 'critical');
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
