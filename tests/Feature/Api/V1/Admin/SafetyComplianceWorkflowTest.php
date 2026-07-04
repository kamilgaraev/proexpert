<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyRequirementMatrix;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
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

final class SafetyComplianceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_is_not_admitted_when_required_medical_exam_and_ppe_are_missing(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'SAFE-001',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);

        SafetyRequirementMatrix::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'position_name' => 'Монтажник',
            'work_category' => 'height_work',
            'risk_level' => 'high',
            'requirements' => [
                ['type' => 'briefing', 'code' => 'target', 'label' => 'Целевой инструктаж', 'required' => true],
                ['type' => 'training', 'code' => 'occupational_safety', 'label' => 'Обучение требованиям охраны труда', 'required' => true],
                ['type' => 'training', 'code' => 'first_aid', 'label' => 'Первая помощь', 'required' => true],
                ['type' => 'training', 'code' => 'ppe', 'label' => 'Применение СИЗ', 'required' => true],
                ['type' => 'medical_exam', 'code' => 'default', 'label' => 'Медосмотр', 'required' => true],
                ['type' => 'ppe', 'code' => 'harness', 'label' => 'Страховочная привязь', 'required' => true],
                ['type' => 'ppe', 'code' => 'helmet', 'label' => 'Каска', 'required' => true],
            ],
            'is_active' => true,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/admission/check', [
                'employee_id' => $employee->id,
                'project_id' => $project->id,
                'position_name' => 'Монтажник',
                'work_category' => 'height_work',
                'work_date' => now()->toDateString(),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'not_admitted');

        $blockerCodes = collect($response->json('data.blockers'))->pluck('code')->all();
        self::assertContains('medical_exam_missing', $blockerCodes);
        self::assertContains('ppe_missing', $blockerCodes);
    }

    public function test_work_permit_activation_is_blocked_when_participant_is_not_admitted(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'SAFE-002',
            'last_name' => 'Петров',
            'first_name' => 'Петр',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);

        SafetyRequirementMatrix::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'position_name' => 'Монтажник',
            'work_category' => 'height_work',
            'risk_level' => 'high',
            'requirements' => [
                ['type' => 'medical_exam', 'code' => 'default', 'label' => 'Медосмотр', 'required' => true],
                ['type' => 'ppe', 'code' => 'helmet', 'label' => 'Каска', 'required' => true],
            ],
            'is_active' => true,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $permit = SafetyWorkPermit::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'permit_number' => 'HSE-P-BLOCKED',
            'title' => 'Высотные работы',
            'permit_type' => 'height_work',
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addDay(),
            'risk_level' => 'high',
            'status' => 'approved',
        ]);

        $participant = $permit->participants()->create([
            'organization_id' => $context->organization->id,
            'employee_id' => $employee->id,
            'user_id' => $context->user->id,
            'position_name' => 'Монтажник',
            'work_category' => 'height_work',
        ]);

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/work-permits/{$permit->id}/activate");

        $response->assertStatus(422)
            ->assertJsonPath('message', trans_message('safety_management.errors.permit_participant_not_admitted'));

        self::assertSame('not_admitted', $participant->refresh()->admission_status);
    }

    public function test_inspection_completion_creates_open_finding_for_non_compliant_item(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $templateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/inspection-templates', [
                'name' => 'Ежедневный обход',
                'inspection_type' => 'site_walk',
                'checklist_items' => [[
                    'code' => 'guardrails',
                    'title' => 'Ограждения опасных зон',
                    'severity' => 'high',
                ]],
            ]);

        $templateResponse->assertCreated();

        $inspectionResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/inspections', [
                'project_id' => $project->id,
                'template_id' => $templateResponse->json('data.id'),
                'title' => 'Обход зоны монтажа',
                'inspection_type' => 'site_walk',
            ]);

        $inspectionResponse->assertCreated()
            ->assertJsonPath('data.items.0.item_code', 'guardrails');

        $inspectionId = (int) $inspectionResponse->json('data.id');

        $completeResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/safety-management/inspections/{$inspectionId}/complete", [
                'items' => [[
                    'item_code' => 'guardrails',
                    'status' => 'non_compliant',
                    'comment' => 'Нет временного ограждения',
                    'finding_title' => 'Восстановить ограждение',
                    'due_date' => now()->addDay()->toDateString(),
                ]],
            ]);

        $completeResponse->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result', 'failed')
            ->assertJsonPath('data.findings.0.status', 'open');
    }

    public function test_employee_safety_records_and_document_draft_contracts_are_available(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'SAFE-003',
            'last_name' => 'Sidorov',
            'first_name' => 'Sergey',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $requirementResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/employee-requirements', [
                'employee_id' => $employee->id,
                'project_id' => $project->id,
                'work_category' => 'height_work',
                'requirement_code' => 'height_access',
                'requirement_type' => 'training',
                'valid_from' => now()->subDay()->toDateString(),
                'valid_until' => now()->addYear()->toDateString(),
            ]);

        $requirementResponse->assertCreated()
            ->assertJsonPath('data.status', 'valid')
            ->assertJsonPath('data.employee.id', $employee->id);
        $requirementId = (int) $requirementResponse->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/safety-management/employee-requirements/{$requirementId}", [
                'status' => 'waived',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'waived');

        $trainingResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/training-records', [
                'employee_id' => $employee->id,
                'program_code' => 'occupational_safety',
                'program_name' => 'Occupational safety',
                'training_type' => 'knowledge_check',
                'completed_at' => now()->subDay()->toDateString(),
                'valid_until' => now()->addYear()->toDateString(),
                'result' => 'passed',
            ]);

        $trainingResponse->assertCreated()
            ->assertJsonPath('data.result', 'passed');

        $medicalResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/medical-exams', [
                'employee_id' => $employee->id,
                'exam_type' => 'default',
                'completed_at' => now()->subDay()->toDateString(),
                'valid_until' => now()->addYear()->toDateString(),
                'result' => 'fit',
            ]);

        $medicalResponse->assertCreated()
            ->assertJsonPath('data.result', 'fit');

        $ppeResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/ppe-issues', [
                'employee_id' => $employee->id,
                'ppe_code' => 'helmet',
                'ppe_name' => 'Helmet',
                'issued_at' => now()->subDay()->toDateString(),
                'valid_until' => now()->addYear()->toDateString(),
                'quantity' => 1,
            ]);

        $ppeResponse->assertCreated()
            ->assertJsonPath('data.status', 'issued');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/safety-management/training-records?employee_id={$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.0.employee.id', $employee->id);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/documents/ppe-card/draft', [
                'employee_id' => $employee->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.document_type', 'ppe_card')
            ->assertJsonPath('data.sections.0.rows.0.ppe_code', 'helmet');

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/safety-management/employee-requirements/{$requirementId}")
            ->assertOk();
    }

    public function test_requirement_matrix_crud_contract_is_available(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/safety-management/requirement-matrices', [
                'project_id' => $project->id,
                'work_category' => 'height_work',
                'risk_level' => 'high',
                'requirements' => [
                    ['type' => 'medical_exam', 'code' => 'default', 'label' => 'Медосмотр', 'required' => true],
                    ['type' => 'ppe', 'code' => 'helmet', 'label' => 'Каска', 'required' => true],
                ],
                'effective_from' => now()->toDateString(),
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.work_category', 'height_work')
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.requirements.0.code', 'default');
        $matrixId = (int) $createResponse->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/safety-management/requirement-matrices?work_category=height_work')
            ->assertOk()
            ->assertJsonPath('data.0.id', $matrixId);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/safety-management/requirement-matrices/{$matrixId}", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/safety-management/requirement-matrices/{$matrixId}")
            ->assertOk();
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'safety-management',
                    'project-management',
                    'workforce-management',
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
