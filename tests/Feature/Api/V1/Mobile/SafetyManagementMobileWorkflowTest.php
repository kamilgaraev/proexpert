<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefingParticipant;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyRequirementMatrix;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermitParticipant;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class SafetyManagementMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_permit_list_and_detail_are_scoped_to_current_user_and_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $otherUser = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($otherUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'SAFE-MOB-SCOPE-001',
            'last_name' => 'Полевой',
            'first_name' => 'Работник',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $ownPermit = $this->createPermit($context, $project, $context->user, 'approved');
        $unassignedPermit = $this->createPermit($context, $project, null, 'active');
        $participantPermit = $this->createPermit($context, $project, $otherUser, 'active');
        SafetyWorkPermitParticipant::query()->create([
            'organization_id' => $context->organization->id,
            'permit_id' => $participantPermit->id,
            'employee_id' => $employee->id,
            'user_id' => $context->user->id,
            'role_name' => 'Исполнитель',
            'work_category' => 'height_work',
            'admission_status' => 'admitted',
        ]);
        $otherAssignedPermit = $this->createPermit($context, $project, $otherUser, 'approved');
        $foreignPermit = $this->createPermit($foreignContext, $foreignProject, null, 'approved');

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/work-permits');

        $response->assertOk();
        $permitIds = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($ownPermit->id, $permitIds);
        $this->assertContains($participantPermit->id, $permitIds);
        $this->assertNotContains($unassignedPermit->id, $permitIds);
        $this->assertNotContains($otherAssignedPermit->id, $permitIds);
        $this->assertNotContains($foreignPermit->id, $permitIds);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/work-permits/{$ownPermit->id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions.0', 'activate');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/work-permits/{$participantPermit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $participantPermit->id);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/work-permits/{$otherAssignedPermit->id}")
            ->assertNotFound();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/work-permits?status=not-a-status')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', trans_message('safety_management.validation.status_invalid'));
    }

    public function test_mobile_user_can_view_and_sign_only_own_briefing_participant(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherUser = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($otherUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'SAFE-BRF-MOB-001',
            'last_name' => 'Полевой',
            'first_name' => 'Инструктируемый',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $briefing = SafetyBriefing::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'conducted_by_user_id' => $otherUser->id,
            'briefing_number' => 'HSE-B-MOB-001',
            'title' => 'Инструктаж перед сменой',
            'briefing_type' => 'toolbox',
            'location_name' => 'Башня А',
            'conducted_at' => now()->subHour(),
            'status' => 'awaiting_signatures',
            'started_at' => now()->subHour(),
            'signature_summary' => [
                'total' => 2,
                'signed' => 0,
                'pending' => 2,
                'absent' => 0,
                'refused' => 0,
                'resolved' => 0,
                'completion_percent' => 0,
                'all_resolved' => false,
            ],
            'topics' => ['Безопасный проход'],
        ]);
        $ownParticipant = SafetyBriefingParticipant::query()->create([
            'briefing_id' => $briefing->id,
            'employee_id' => $employee->id,
            'user_id' => $context->user->id,
            'role_name' => 'Исполнитель',
            'signature_status' => 'pending',
        ]);
        $otherParticipant = SafetyBriefingParticipant::query()->create([
            'briefing_id' => $briefing->id,
            'user_id' => $otherUser->id,
            'role_name' => 'Наблюдающий',
            'signature_status' => 'pending',
        ]);
        $foreignBriefing = SafetyBriefing::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'conducted_by_user_id' => $otherUser->id,
            'briefing_number' => 'HSE-B-MOB-002',
            'title' => 'Чужой инструктаж',
            'briefing_type' => 'target',
            'conducted_at' => now()->subMinutes(30),
            'status' => 'awaiting_signatures',
            'started_at' => now()->subMinutes(30),
            'signature_summary' => [
                'total' => 1,
                'signed' => 0,
                'pending' => 1,
                'absent' => 0,
                'refused' => 0,
                'resolved' => 0,
                'completion_percent' => 0,
                'all_resolved' => false,
            ],
        ]);
        SafetyBriefingParticipant::query()->create([
            'briefing_id' => $foreignBriefing->id,
            'user_id' => $otherUser->id,
            'signature_status' => 'pending',
        ]);

        $dashboard = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/dashboard?project_id={$project->id}");
        $dashboard->assertOk()
            ->assertJsonPath('data.mine.briefings_to_sign', 1);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/briefings');
        $response->assertOk();
        $briefingIds = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($briefing->id, $briefingIds);
        $this->assertNotContains($foreignBriefing->id, $briefingIds);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/briefings/{$briefing->id}")
            ->assertOk()
            ->assertJsonPath('data.participants.0.signature_status', 'pending');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/briefings/{$briefing->id}/participants/{$otherParticipant->id}/sign")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('safety_management.errors.briefing_participant_not_found'));

        $signed = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/briefings/{$briefing->id}/participants/{$ownParticipant->id}/sign");

        $signed->assertOk()
            ->assertJsonPath('data.signature_summary.signed', 1)
            ->assertJsonPath('data.signature_summary.pending', 1);
        $signedParticipant = collect($signed->json('data.participants'))->firstWhere('id', $ownParticipant->id);
        self::assertSame('signed', $signedParticipant['signature_status']);
        self::assertSame('mobile', $signedParticipant['signature_method']);
        self::assertSame($context->user->id, $signedParticipant['signed_by_user_id']);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/dashboard?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('data.mine.briefings_to_sign', 0);
    }

    public function test_mobile_dashboard_and_my_admission_are_available_for_current_user(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'SAFE-MOB-001',
            'last_name' => 'Полевой',
            'first_name' => 'Пользователь',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);
        SafetyRequirementMatrix::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_category' => 'height_work',
            'risk_level' => 'high',
            'requirements' => [
                ['type' => 'medical_exam', 'code' => 'default', 'label' => 'Медосмотр', 'required' => true],
            ],
            'is_active' => true,
            'effective_from' => now()->subDay()->toDateString(),
        ]);
        $permit = $this->createPermit($context, $project, $context->user, 'active');

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/dashboard?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('data.mine.employee_id', $employee->id)
            ->assertJsonPath('data.mine.open_permits', 1);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/safety-management/my-admission?project_id={$project->id}&work_category=height_work")
            ->assertOk()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.status', 'not_admitted');

        $permit->delete();
    }

    public function test_mobile_permit_lifecycle_actions_require_real_action_data(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $permit = $this->createPermit($context, $project, $context->user, 'draft');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.available_actions.0', 'approve');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/approve", [
                'approval_comment' => 'Риски проверены',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approval_comment', 'Риски проверены');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/suspend")
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', trans_message('safety_management.validation.reason_required'));

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/suspend", [
                'reason' => 'Усиление ветра',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Усиление ветра');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/close")
            ->assertStatus(422)
            ->assertJsonPath('errors.close_comment.0', trans_message('safety_management.validation.close_comment_required'));

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$permit->id}/close", [
                'close_comment' => 'Работы завершены безопасно',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.close_comment', 'Работы завершены безопасно');

        $rejectPermit = $this->createPermit($context, $project, $context->user, 'pending_approval');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$rejectPermit->id}/reject")
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', trans_message('safety_management.validation.reason_required'));

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/safety-management/work-permits/{$rejectPermit->id}/reject", [
                'reason' => 'Не указаны меры контроля',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Не указаны меры контроля');
    }

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
            ->assertJsonPath('data.available_actions.0', 'triage');
        $incidentId = (int) $incidentResponse->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/incidents?status=not-a-status')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', trans_message('safety_management.validation.status_invalid'));

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/incidents?status=reported')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $incidentId);

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

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/violations?status=not-a-status')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', trans_message('safety_management.validation.status_invalid'));

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/violations?status=open')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $violationId);

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

    public function test_mobile_incident_requires_explicit_type_and_severity(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/safety-management/incidents', [
                'project_id' => $project->id,
                'title' => 'Unsafe work area',
                'occurred_at' => now()->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('safety_management.errors.validation_failed'))
            ->assertJsonPath('errors.incident_type.0', trans_message('safety_management.validation.incident_type_required'))
            ->assertJsonPath('errors.severity.0', trans_message('safety_management.validation.severity_required'));
    }

    public function test_mobile_violation_requires_explicit_severity(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/safety-management/violations', [
                'project_id' => $project->id,
                'title' => 'PPE missing',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('safety_management.errors.validation_failed'))
            ->assertJsonPath('errors.severity.0', trans_message('safety_management.validation.severity_required'));
    }

    public function test_mobile_user_can_view_inspections_and_create_assigned_inspection_finding(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $otherUser = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($otherUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $inspection = SafetyInspection::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'conducted_by_user_id' => $context->user->id,
            'inspection_number' => 'HSE-CHK-MOB-1',
            'title' => 'Site walk',
            'inspection_type' => 'site_walk',
            'location_name' => 'Block A',
            'risk_level' => 'medium',
            'status' => 'planned',
            'planned_at' => now()->addHour(),
        ]);
        $foreignInspection = SafetyInspection::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'conducted_by_user_id' => $foreignContext->user->id,
            'inspection_number' => 'HSE-CHK-MOB-2',
            'title' => 'Foreign site walk',
            'inspection_type' => 'site_walk',
            'risk_level' => 'medium',
            'status' => 'planned',
        ]);

        $inspectionsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/inspections?status=planned');

        $inspectionsResponse->assertOk();
        $inspectionIds = collect($inspectionsResponse->json('data.data'))->pluck('id')->all();
        $this->assertContains($inspection->id, $inspectionIds);
        $this->assertNotContains($foreignInspection->id, $inspectionIds);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/inspections?status=not-a-status')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', trans_message('safety_management.validation.status_invalid'));

        $findingResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/safety-management/inspection-findings', [
                'project_id' => $project->id,
                'inspection_id' => $inspection->id,
                'title' => 'Missing guard rail',
                'description' => 'Temporary guard rail is missing',
                'severity' => 'high',
                'due_date' => now()->addDay()->toDateString(),
            ]);

        $findingResponse->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assigned_to_user_id', $context->user->id);
        $findingId = (int) $findingResponse->json('data.id');

        SafetyInspectionFinding::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'inspection_id' => $inspection->id,
            'assigned_to_user_id' => $otherUser->id,
            'created_by_user_id' => $context->user->id,
            'finding_number' => 'HSE-F-MOB-2',
            'title' => 'Other user finding',
            'severity' => 'major',
            'status' => 'open',
        ]);

        $findingsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/safety-management/inspection-findings?status=open');

        $findingsResponse->assertOk();
        $findingIds = collect($findingsResponse->json('data.data'))->pluck('id')->all();
        $this->assertContains($findingId, $findingIds);
        $this->assertNotContains('HSE-F-MOB-2', collect($findingsResponse->json('data.data'))->pluck('finding_number')->all());

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/safety-management/inspection-findings', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign project finding',
                'severity' => 'minor',
            ])
            ->assertStatus(422);
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

    private function createPermit(
        AdminApiTestContext $context,
        Project $project,
        ?User $responsibleUser,
        string $status,
        array $attributes = []
    ): SafetyWorkPermit {
        return SafetyWorkPermit::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'responsible_user_id' => $responsibleUser?->id,
            'permit_number' => 'HSE-P-' . uniqid(),
            'title' => 'Высотные работы',
            'permit_type' => 'height_work',
            'location_name' => 'Секция А',
            'risk_level' => 'high',
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addDay(),
            'required_controls' => ['ограждение', 'страховка'],
            'status' => $status,
        ], $attributes));
    }
}
