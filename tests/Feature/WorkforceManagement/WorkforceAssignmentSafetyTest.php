<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyMedicalExam;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyRequirementMatrix;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyTrainingRecord;
use App\BusinessModules\Features\SafetyManagement\Services\SafetyComplianceService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\WorkforceAssignmentSafetyService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceAssignmentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_medical_expiry_is_checked_on_assignment_start_not_today(): void
    {
        [$context, $employee, $positionId] = $this->fixture();
        $this->medical($context, $employee);
        $service = app(WorkforceAssignmentSafetyService::class);
        $service->assertAssignment($context->organization->id, $this->assignment($employee, $positionId, '2026-10-15'), $context->user);
        $result = $this->compliance($context, $employee, '2026-10-16');
        self::assertContains('medical_exam_expired', array_column($result->blockers, 'code'));

        try {
            $service->assertAssignment($context->organization->id, $this->assignment($employee, $positionId), $context->user);
            self::fail('mandatory expired exam accepted');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.assignment_safety_not_admitted'), $exception->getMessage());
            self::assertStringNotContainsString('private diagnosis', $exception->getMessage());
        }
    }

    public function test_required_expired_training_blocks_configured_assignment(): void
    {
        [$context, $employee, $positionId] = $this->fixture(true, 'training');
        SafetyTrainingRecord::query()->create(['organization_id' => $context->organization->id, 'employee_id' => $employee->id, 'program_code' => 'default', 'program_name' => 'Обучение', 'training_type' => 'occupational_safety', 'completed_at' => '2026-09-01', 'valid_until' => '2026-10-15', 'result' => 'passed'])->forceFill(['created_at' => '2026-09-01'])->save();
        self::assertContains('training_expired', array_column($this->compliance($context, $employee, '2026-10-16')->blockers, 'code'));
        $this->expectException(\DomainException::class);
        app(WorkforceAssignmentSafetyService::class)->assertAssignment($context->organization->id, $this->assignment($employee, $positionId), $context->user);
    }

    public function test_optional_expired_evidence_warns_without_blocking_assignment(): void
    {
        [$context, $employee, $positionId] = $this->fixture(false);
        $this->medical($context, $employee);
        $result = $this->compliance($context, $employee, '2026-10-16');
        self::assertFalse($result->blocked);
        self::assertContains('medical_exam_expired', array_column($result->warnings, 'code'));
        app(WorkforceAssignmentSafetyService::class)->assertAssignment($context->organization->id, $this->assignment($employee, $positionId), $context->user);
    }

    public function test_position_without_policy_does_not_invent_general_applicability(): void
    {
        [$context, $employee, $positionId] = $this->fixture();
        $this->medical($context, $employee);
        DB::table('workforce_positions')->where('id', $positionId)->update(['safety_work_category' => null]);
        self::assertTrue($this->compliance($context, $employee, '2026-10-16')->blocked);
        app(WorkforceAssignmentSafetyService::class)->assertAssignment($context->organization->id, $this->assignment($employee, $positionId), $context->user);
    }

    public function test_configured_policy_without_applicable_matrix_fails_closed(): void
    {
        [$context, $employee, $positionId] = $this->fixture();
        DB::table('workforce_positions')->where('id', $positionId)->update(['safety_work_category' => 'unconfigured_category']);
        $this->expectException(\DomainException::class);
        app(WorkforceAssignmentSafetyService::class)->assertAssignment($context->organization->id, $this->assignment($employee, $positionId), $context->user);
    }

    public function test_foreign_project_cannot_supply_applicability_context(): void
    {
        [$context, $employee, $positionId] = $this->fixture(false);
        $foreign = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $this->expectException(\DomainException::class);
        app(WorkforceAssignmentSafetyService::class)->assertAssignment($context->organization->id, $this->assignment($employee, $positionId) + ['project_id' => $project->id], $context->user);
    }

    public function test_unknown_historical_employee_binding_fails_closed_only_when_opted_in(): void
    {
        [$context, $employee, $positionId] = $this->fixture(false);
        $employee->forceFill(['created_at' => '2026-02-01'])->save();
        try {
            app(WorkforceAssignmentSafetyService::class)->assertAssignment($context->organization->id, $this->assignment($employee, $positionId, '2026-01-15'), $context->user);
            self::fail('unbound historical employee accepted');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.assignment_safety_not_admitted'), $exception->getMessage());
        }
        DB::table('workforce_positions')->where('id', $positionId)->update(['safety_work_category' => null]);
        app(WorkforceAssignmentSafetyService::class)->assertAssignment($context->organization->id, $this->assignment($employee, $positionId, '2026-01-15'), $context->user);
    }

    public function test_structure_permission_cannot_modify_opt_in_policy(): void
    {
        [$context] = $this->fixture();
        $this->permissions(false);
        $service = app(WorkforceAssignmentSafetyService::class);
        $service->assertPositionPolicy($context->organization->id, ['name' => 'Монтажник'], $context->user);
        $this->expectException(AuthorizationException::class);
        $service->assertPositionPolicy($context->organization->id, ['safety_work_category' => 'height_work'], $context->user);
    }

    private function fixture(bool $required = true, string $type = 'medical_exam'): array
    {
        $context = AdminApiTestContext::create();
        $this->permissions();
        $employee = WorkforceEmployee::query()->create(['organization_id' => $context->organization->id, 'personnel_number' => 'SAFETY-ASSIGNMENT', 'first_name' => 'Иван', 'last_name' => 'Петров', 'hire_date' => '2026-01-01', 'employment_status' => 'active']);
        $employee->forceFill(['created_at' => '2026-01-01'])->save();
        $positionId = DB::table('workforce_positions')->insertGetId(['organization_id' => $context->organization->id, 'code' => 'INSTALLER', 'name' => 'Монтажник', 'is_active' => true, 'safety_work_category' => 'height_work']);
        SafetyRequirementMatrix::query()->create(['organization_id' => $context->organization->id, 'work_category' => 'height_work', 'position_name' => 'Монтажник', 'risk_level' => 'high', 'requirements' => [['type' => $type, 'code' => 'default', 'label' => 'private diagnosis', 'required' => $required]], 'is_active' => true, 'effective_from' => '2026-01-01', 'effective_until' => '2026-12-31']);

        return [$context, $employee, $positionId];
    }

    private function medical(AdminApiTestContext $context, WorkforceEmployee $employee): void
    {
        SafetyMedicalExam::query()->create(['organization_id' => $context->organization->id, 'employee_id' => $employee->id, 'exam_type' => 'default', 'completed_at' => '2026-09-01', 'valid_until' => '2026-10-15', 'result' => 'fit', 'restrictions' => 'private diagnosis'])->forceFill(['created_at' => '2026-09-01'])->save();
    }

    private function assignment(WorkforceEmployee $employee, int $positionId, string $date = '2026-10-16'): array
    {
        return ['employee_id' => $employee->id, 'position_id' => $positionId, 'valid_from' => $date];
    }

    private function compliance(AdminApiTestContext $context, WorkforceEmployee $employee, string $date): object
    {
        return app(SafetyComplianceService::class)->check(new SafetyComplianceContext(organizationId: $context->organization->id, employeeId: $employee->id, workCategory: 'height_work', positionName: 'Монтажник', date: CarbonImmutable::parse($date)));
    }

    private function permissions(bool $settings = true): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($settings): void {
            $mock->shouldReceive('canCurrent')->andReturnUsing(static fn ($actor, string $permission): bool => $permission !== 'workforce.settings.manage' || $settings);
        });
    }
}
