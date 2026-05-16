<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_mutate_workforce_employees(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employees', [
                'personnel_number' => 'VIEW-001',
                'last_name' => 'Иванов',
                'first_name' => 'Иван',
                'hire_date' => '2026-05-01',
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', trans_message('errors.unauthorized'));
    }

    public function test_active_user_assignment_is_unique_per_organization(): void
    {
        $context = AdminApiTestContext::create();
        $user = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employees', [
                'user_id' => $user->id,
                'personnel_number' => 'USR-001',
                'last_name' => 'Иванов',
                'first_name' => 'Иван',
                'hire_date' => '2026-05-01',
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employees', [
                'user_id' => $user->id,
                'personnel_number' => 'USR-002',
                'last_name' => 'Петров',
                'first_name' => 'Петр',
                'hire_date' => '2026-05-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.employee_user_already_active'));
    }

    public function test_employee_lifecycle_closes_active_assignment_on_dismissal(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'LIFE-001');
        [$departmentId, $positionId, $staffUnitId] = $this->structure($context);
        $this->allowAccess('web_admin');

        $assignmentId = DB::table('workforce_employee_assignments')->insertGetId([
            'organization_id' => $context->organization->id,
            'employee_id' => $employee->id,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'valid_from' => '2026-05-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/employees/{$employee->id}/dismiss", [
                'dismissal_date' => '2026-05-20',
            ])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'dismissed')
            ->assertJsonPath('data.status_label', trans_message('workforce.employee_statuses.dismissed'));

        $this->assertDatabaseHas('workforce_employee_assignments', [
            'id' => $assignmentId,
            'valid_to' => '2026-05-20',
        ]);
    }

    public function test_employee_cannot_be_dismissed_before_hire_date(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'LIFE-002');
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/employees/{$employee->id}/dismiss", [
                'dismissal_date' => '2026-04-30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.dismissal_before_hire_date'));
    }

    public function test_payroll_periods_do_not_overlap(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/payroll-periods', [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/payroll-periods', [
                'period_start' => '2026-05-15',
                'period_end' => '2026-06-15',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_overlap'));
    }

    public function test_locked_payroll_period_rejects_stale_source_before_export(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'SRC-001');
        $this->allowAccess('web_admin');

        $periodId = DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'locked',
            'source_hash' => 'stale-source-hash',
            'created_by_user_id' => $context->user->id,
            'locked_by_user_id' => $context->user->id,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workforce_payroll_source_rows')->insert([
            'organization_id' => $context->organization->id,
            'payroll_period_id' => $periodId,
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'work_date' => '2026-05-16',
            'source_type' => 'timesheet_hours',
            'hours' => 8,
            'amount' => 4000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/export-packages")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_source_changed'));
    }

    public function test_export_package_status_has_guarded_transitions(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $periodId = DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $context->organization->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'locked',
            'source_hash' => 'same',
            'created_by_user_id' => $context->user->id,
            'locked_by_user_id' => $context->user->id,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $packageId = DB::table('workforce_export_packages')->insertGetId([
            'organization_id' => $context->organization->id,
            'payroll_period_id' => $periodId,
            'package_number' => 'WF-GUARD-1',
            'status' => 'created',
            'source_hash' => 'same',
            'created_by_user_id' => $context->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/export-packages/{$packageId}/mark-accepted")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.export_status_transition_forbidden'));
    }

    public function test_absence_approval_rejects_dismissed_employee(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'ABS-001', 'dismissed');
        $this->allowAccess('web_admin');

        $absence = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-05-10',
                'end_date' => '2026-05-12',
            ]);
        $absence->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $absenceId = (int) $absence->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/absences/{$absenceId}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.employee_not_active'));
    }

    public function test_absence_approval_rejects_overlapping_approved_absence(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'ABS-002');
        $this->allowAccess('web_admin');

        $first = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-05-10',
                'end_date' => '2026-05-12',
            ]);
        $first->assertCreated();

        $second = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-05-11',
                'end_date' => '2026-05-13',
            ]);
        $second->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences/' . (int) $first->json('data.id') . '/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences/' . (int) $second->json('data.id') . '/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.absence_overlap'));
    }

    public function test_business_trip_approval_rejects_approved_absence_overlap(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'TRIP-001');
        $this->allowAccess('web_admin');

        $absence = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-05-10',
                'end_date' => '2026-05-12',
            ]);
        $absence->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences/' . (int) $absence->json('data.id') . '/approve')
            ->assertOk();

        $trip = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/business-trips', [
                'employee_id' => $employee->id,
                'start_date' => '2026-05-11',
                'end_date' => '2026-05-13',
                'destination' => 'Казань',
            ]);
        $trip->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/business-trips/' . (int) $trip->json('data.id') . '/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.business_trip_absence_overlap'));
    }

    public function test_assignment_rejects_inactive_staff_unit(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'ASSIGN-001');
        [$departmentId, $positionId, $staffUnitId] = $this->structure($context, isActive: false);
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $employee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'valid_from' => '2026-05-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.structure_record_inactive'));
    }

    public function test_assignment_rejects_staff_unit_capacity_overflow(): void
    {
        $context = AdminApiTestContext::create();
        $firstEmployee = $this->employee($context, 'CAP-001');
        $secondEmployee = $this->employee($context, 'CAP-002');
        [$departmentId, $positionId, $staffUnitId] = $this->structure($context, headcount: 1);
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $firstEmployee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'rate' => 1,
                'valid_from' => '2026-05-01',
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $secondEmployee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'rate' => 1,
                'valid_from' => '2026-05-10',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.staff_unit_capacity_exceeded'));
    }

    public function test_structure_deactivation_rejects_active_assignments(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'STRUCT-001');
        [$departmentId, $positionId, $staffUnitId] = $this->structure($context);
        $this->allowAccess('web_admin');

        DB::table('workforce_employee_assignments')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employee->id,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'valid_from' => '2026-05-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/workforce/departments/{$departmentId}", [
                'is_active' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.structure_has_active_assignments'));
    }

    private function employee(AdminApiTestContext $context, string $personnelNumber, string $status = 'active'): WorkforceEmployee
    {
        return WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => $personnelNumber,
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => $status,
            'hire_date' => '2026-05-01',
        ]);
    }

    private function structure(AdminApiTestContext $context, bool $isActive = true, int $headcount = 1): array
    {
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'DEP-' . uniqid(),
            'name' => 'Строительный участок',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'POS-' . uniqid(),
            'name' => 'Монтажник',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $context->organization->id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'UNIT-' . uniqid(),
            'headcount' => $headcount,
            'rate' => 1,
            'valid_from' => '2026-05-01',
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$departmentId, $positionId, $staffUnitId];
    }

    private function allowAccess(string $roleSlug): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($roleSlug): void {
            $mock->shouldReceive('can')
                ->andReturn($roleSlug === 'web_admin');
        });
    }
}
