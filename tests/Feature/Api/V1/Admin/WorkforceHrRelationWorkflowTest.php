<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\EmploymentRelationService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\AssignmentHistoryService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrEvent;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\EmploymentContract;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceEmployeeService;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceHrRelationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_secondary_and_civil_contracts_coexist_without_overwriting_history(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowHr();
        $employee = $this->employee((int) $context->organization->id);
        $service = app(EmploymentRelationService::class);
        $main = $service->record((int) $context->organization->id, (int) $employee->id, $this->terms('MAIN-1'), $context->user);
        $secondary = $service->record((int) $context->organization->id, (int) $employee->id, array_replace($this->terms('SECONDARY-1'), [
            'employment_mode' => 'internal_secondary', 'job_share' => 0.5, 'weekly_norm_hours' => 20,
        ]), $context->user);
        $civil = $service->record((int) $context->organization->id, (int) $employee->id, [
            'relation_type' => 'civil', 'contract_number' => 'CIVIL-1', 'contract_date' => '2026-10-01', 'start_date' => '2026-10-01',
            'event_type' => 'hire', 'basis_reference' => 'Paper contract CIVIL-1',
        ], $context->user);

        $this->assertNotSame($main->id, $secondary->id);
        $this->assertNotSame($main->id, $civil->id);
        $this->assertDatabaseCount('workforce_employment_contracts', 3);
        $this->assertDatabaseCount('workforce_hr_events', 3);
        $this->assertNull($main->refresh()->end_date);
        $this->assertNull($secondary->refresh()->end_date);
        $this->assertNull($civil->weekly_norm_hours);
    }

    public function test_overlapping_contract_in_same_employment_mode_is_rejected(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowHr();
        $employee = $this->employee((int) $context->organization->id);
        $service = app(EmploymentRelationService::class);
        $service->record((int) $context->organization->id, (int) $employee->id, $this->terms('MAIN-1'), $context->user);
        $this->expectException(\DomainException::class);
        $service->record((int) $context->organization->id, (int) $employee->id, $this->terms('MAIN-2'), $context->user);
    }

    public function test_actor_from_another_organization_cannot_write_relation(): void
    {
        $context = AdminApiTestContext::create();
        $outsider = AdminApiTestContext::create();
        $this->allowHr();
        $employee = $this->employee((int) $context->organization->id);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(EmploymentRelationService::class)->record((int) $context->organization->id, (int) $employee->id, $this->terms('MAIN-1'), $outsider->user);
    }

    public function test_assignment_history_preserves_the_state_overwritten_in_current_assignment(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee((int) $context->organization->id);
        $organizationId = (int) $context->organization->id;
        $departmentId = DB::table('workforce_departments')->insertGetId(['organization_id' => $organizationId, 'code' => 'HR-DEPT', 'name' => 'Department']);
        $oldPositionId = DB::table('workforce_positions')->insertGetId(['organization_id' => $organizationId, 'code' => 'OLD', 'name' => 'Old position']);
        $newPositionId = DB::table('workforce_positions')->insertGetId(['organization_id' => $organizationId, 'code' => 'NEW', 'name' => 'New position']);
        $staffUnitId = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $organizationId, 'department_id' => $departmentId,
            'position_id' => $newPositionId, 'code' => 'HR-UNIT', 'valid_from' => '2026-10-01']);
        $state = ['organization_id' => $organizationId, 'employee_id' => (int) $employee->id, 'department_id' => $departmentId,
            'position_id' => $newPositionId, 'staff_unit_id' => $staffUnitId, 'rate' => 1, 'valid_from' => '2026-10-01', 'valid_to' => null, 'status' => 'active'];
        $assignmentId = DB::table('workforce_employee_assignments')->insertGetId($state);
        $after = $state + ['id' => $assignmentId];
        HrEvent::create(['organization_id' => $organizationId, 'employee_id' => $employee->id, 'event_type' => 'assignment_changed',
            'effective_date' => '2026-10-01', 'basis_reference' => 'Paper transfer order', 'recorded_by' => $context->user->id, 'recorded_at' => now(),
            'before_state' => array_replace($after, ['position_id' => $oldPositionId]), 'after_state' => $after]);

        $history = app(AssignmentHistoryService::class)->forEmployee($organizationId, (int) $employee->id);
        $this->assertCount(1, $history);
        $this->assertSame('New position', $history[0]['position_label']);
        $this->assertSame('Old position', $history[0]['before_state']['position_label']);
        $this->assertSame('Paper transfer order', $history[0]['basis_reference']);
        $this->assertSame('history_event', $history[0]['source']);
    }

    public function test_approved_timekeeping_history_blocks_a_retrospective_relation(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowHr();
        $employee = $this->employee((int) $context->organization->id);
        $this->period((int) $context->organization->id, (int) $context->user->id, (int) $employee->id);
        $this->expectException(\DomainException::class);
        app(EmploymentRelationService::class)->record((int) $context->organization->id, (int) $employee->id, $this->terms('MAIN-1'), $context->user);
    }

    public function test_a_draft_successor_allows_correction_without_changing_the_approved_ancestor(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowHr();
        $employee = $this->employee((int) $context->organization->id);
        $ancestor = $this->period((int) $context->organization->id, (int) $context->user->id, (int) $employee->id);
        $before = $ancestor->snapshot;
        $successor = $this->period((int) $context->organization->id, (int) $context->user->id, (int) $employee->id, (int) $ancestor->id);
        $relation = app(EmploymentRelationService::class)->record((int) $context->organization->id, (int) $employee->id, $this->terms('MAIN-1'), $context->user);
        $this->assertSame('active', $relation->status);
        $this->assertSame('draft', $successor->status);
        $this->assertSame($before, $ancestor->refresh()->snapshot);
        $this->assertSame('approved', $ancestor->status);
    }

    public function test_legacy_reconciliation_preserves_original_unknown_terms_and_dates(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowHr();
        $employee = $this->employee((int) $context->organization->id);
        $legacy = EmploymentContract::create(['organization_id' => $context->organization->id, 'employee_id' => $employee->id,
            'contract_number' => 'LEGACY-1', 'contract_date' => '2026-10-01', 'start_date' => '2026-10-01', 'status' => 'active']);
        $relation = app(EmploymentRelationService::class)->record((int) $context->organization->id, (int) $employee->id,
            array_replace($this->terms('LEGACY-1'), ['event_type' => 'reconcile', 'supersedes_id' => (int) $legacy->id]), $context->user);

        $this->assertSame('superseded', $legacy->refresh()->status);
        $this->assertNull($legacy->relation_type);
        $this->assertSame('2026-10-01', $legacy->start_date->toDateString());
        $this->assertNull($legacy->end_date);
        $this->assertSame(2, (int) $relation->revision);
        $this->assertSame('2026-10-01', $relation->start_date->toDateString());
        $this->assertSame('reconciled', $employee->refresh()->hr_reconciliation_status);
        $event = HrEvent::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($event->before_state['relation_type']);
        $this->assertSame('employment', $event->after_state['relation_type']);
    }

    public function test_basic_search_cannot_infer_private_payroll_reference(): void
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee((int) $context->organization->id);
        $employee->update(['external_payroll_ref' => 'PAYROLL-SECRET-ONLY']);
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canCurrent')->andReturnFalse();
        });
        $service = app(WorkforceEmployeeService::class);
        $results = $service->paginate((int) $context->organization->id, 20, ['search' => 'PAYROLL-SECRET-ONLY'], $context->user);
        $this->assertSame(0, $results->total());
        $this->allowHr();
        $authorized = $service->paginate((int) $context->organization->id, 20, ['search' => 'PAYROLL-SECRET-ONLY'], $context->user);
        $this->assertSame(1, $authorized->total());
    }

    private function period(int $organizationId, int $userId, int $employeeId, ?int $supersedesId = null): TimekeepingPeriod
    {
        return TimekeepingPeriod::create(['organization_id' => $organizationId, 'month' => '2026-10-01', 'status' => $supersedesId === null ? 'approved' : 'draft',
            'supersedes_id' => $supersedesId, 'rule_snapshot' => [], 'snapshot' => ['rows' => [['employee_id' => $employeeId]]],
            'snapshot_sha256' => str_repeat('a', 64), 'created_by' => $userId, 'revision' => 1]);
    }

    private function employee(int $organizationId): WorkforceEmployee
    {
        return WorkforceEmployee::create(['organization_id' => $organizationId, 'personnel_number' => 'HR-TEST-1',
            'last_name' => 'Test', 'first_name' => 'Employee', 'hire_date' => '2026-10-01', 'employment_status' => 'active']);
    }

    private function terms(string $number): array
    {
        return ['relation_type' => 'employment', 'employment_mode' => 'main', 'contract_number' => $number,
            'contract_date' => '2026-10-01', 'start_date' => '2026-10-01', 'job_share' => 1, 'weekly_norm_hours' => 40,
            'timezone' => 'Europe/Moscow', 'calendar_profile' => 'ru-commercial', 'event_type' => 'hire', 'basis_reference' => 'Paper contract '.$number];
    }

    private function allowHr(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canCurrent')->andReturnTrue();
        });
    }
}
