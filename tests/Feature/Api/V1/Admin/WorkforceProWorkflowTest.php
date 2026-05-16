<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

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

final class WorkforceProWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_structure_assignment_schedule_absence_and_payroll_source_validation_workflow(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'EMP-100');
        $this->allowAccess('web_admin');

        [$departmentId, $positionId, $staffUnitId, $scheduleId] = $this->createStructure($context);

        $assignment = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $employee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'project_id' => $project->id,
                'work_schedule_id' => $scheduleId,
                'valid_from' => '2026-05-01',
            ]);
        $assignment->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.work_schedule_id', $scheduleId);

        $overlap = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $employee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'project_id' => $project->id,
                'work_schedule_id' => $scheduleId,
                'valid_from' => '2026-05-10',
            ]);
        $overlap->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.assignment_overlap'));

        [$workOrderId] = $this->createAcceptedWorkOrderWithTimesheet($context, $project->id, $employee->id);

        $period = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/payroll-periods', [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'project_id' => $project->id,
            ]);
        $period->assertCreated();
        $periodId = (int) $period->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/build-source")
            ->assertOk()
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.work_order_id', $workOrderId)
            ->assertJsonPath('data.0.source_type', 'timesheet_hours');

        $draftAbsence = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-05-16',
                'end_date' => '2026-05-16',
            ]);
        $draftAbsence->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $absenceId = (int) $draftAbsence->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/absences/{$absenceId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertOk()
            ->assertJsonPath('data.0.issue_code', 'absence_conflict')
            ->assertJsonPath('data.0.severity', 'blocking');
    }

    public function test_missing_assignment_and_missing_schedule_are_blocking_payroll_source_issues(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employeeWithoutAssignment = $this->employee($context, 'EMP-200');
        $employeeWithoutSchedule = $this->employee($context, 'EMP-201');
        $this->allowAccess('web_admin');

        [$departmentId, $positionId, $staffUnitId] = $this->createStructure($context, createSchedule: false);
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $employeeWithoutSchedule->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'project_id' => $project->id,
                'valid_from' => '2026-05-01',
            ])
            ->assertCreated();

        $this->createAcceptedWorkOrderWithTimesheet($context, $project->id, $employeeWithoutAssignment->id, 'PL-MISSING-1');
        $this->createAcceptedWorkOrderWithTimesheet($context, $project->id, $employeeWithoutSchedule->id, 'PL-MISSING-2');

        $period = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/payroll-periods', [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'project_id' => $project->id,
            ]);
        $period->assertCreated();
        $periodId = (int) $period->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/build-source")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertOk();

        $issues = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validation-issues");

        $issues->assertOk();
        $codes = collect($issues->json('data'))->pluck('issue_code')->all();

        $this->assertContains('missing_assignment', $codes);
        $this->assertContains('missing_work_schedule', $codes);
    }

    public function test_draft_and_returned_production_facts_are_not_used_for_payroll_source(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'EMP-300');
        $this->allowAccess('web_admin');

        $this->createIssuedWorkOrderWithTimesheet($context, $project->id, $employee->id);

        $period = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/payroll-periods', [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'project_id' => $project->id,
            ]);
        $period->assertCreated();
        $periodId = (int) $period->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/build-source")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_working_schedule_day_blocks_payroll_source_validation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'EMP-400');
        $this->allowAccess('web_admin');

        [$departmentId, $positionId, $staffUnitId, $scheduleId] = $this->createStructure($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/work-schedules/{$scheduleId}/days", [
                'work_date' => '2026-05-16',
                'day_type' => 'weekend',
                'planned_hours' => 0,
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $employee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'project_id' => $project->id,
                'work_schedule_id' => $scheduleId,
                'valid_from' => '2026-05-01',
            ])
            ->assertCreated();

        $this->createAcceptedWorkOrderWithTimesheet($context, $project->id, $employee->id, 'PL-SCHEDULE-1');

        $period = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/payroll-periods', [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'project_id' => $project->id,
            ]);
        $period->assertCreated();
        $periodId = (int) $period->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/build-source")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertOk()
            ->assertJsonPath('data.0.issue_code', 'work_schedule_conflict');
    }

    private function employee(AdminApiTestContext $context, string $personnelNumber): WorkforceEmployee
    {
        return WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => $personnelNumber,
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
    }

    private function createStructure(AdminApiTestContext $context, bool $createSchedule = true): array
    {
        $department = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/departments', [
                'code' => 'STR-' . uniqid(),
                'name' => 'Строительный участок',
            ]);
        $department->assertCreated();
        $departmentId = (int) $department->json('data.id');

        $position = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/positions', [
                'code' => 'MON-' . uniqid(),
                'name' => 'Монтажник',
            ]);
        $position->assertCreated();
        $positionId = (int) $position->json('data.id');

        $staffUnit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/staff-units', [
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'code' => 'STR-MON-' . uniqid(),
                'valid_from' => '2026-05-01',
            ]);
        $staffUnit->assertCreated();
        $staffUnitId = (int) $staffUnit->json('data.id');

        if (!$createSchedule) {
            return [$departmentId, $positionId, $staffUnitId];
        }

        $schedule = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => '5-2-' . uniqid(),
                'name' => '5/2',
                'hours_per_day' => 8,
            ]);
        $schedule->assertCreated();

        return [$departmentId, $positionId, $staffUnitId, (int) $schedule->json('data.id')];
    }

    private function createAcceptedWorkOrderWithTimesheet(AdminApiTestContext $context, int $projectId, int $employeeId, ?string $number = null): array
    {
        [$workOrderId, $lineId] = $this->createIssuedWorkOrderWithTimesheet($context, $projectId, $employeeId, $number);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/submit")
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/accept")
            ->assertOk();

        return [$workOrderId, $lineId];
    }

    private function createIssuedWorkOrderWithTimesheet(AdminApiTestContext $context, int $projectId, int $employeeId, ?string $number = null): array
    {
        $workOrder = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $projectId,
                'order_number' => $number ?? ('PL-' . uniqid()),
                'title' => 'Монтаж',
                'assignee_type' => 'brigade',
                'assignee_name' => 'Бригада',
                'lines' => [[
                    'name' => 'Монтаж',
                    'unit' => 'м3',
                    'planned_quantity' => 10,
                    'unit_rate' => 1000,
                    'planned_hours' => 8,
                    'hour_rate' => 500,
                ]],
            ]);
        $workOrder->assertCreated();
        $workOrderId = (int) $workOrder->json('data.id');
        $lineId = (int) $workOrder->json('data.lines.0.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/issue")
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => '2026-05-16',
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'employee_id' => $employeeId,
                    'hours' => 8,
                ]],
            ])
            ->assertCreated();

        return [$workOrderId, $lineId];
    }

    private function allowAccess(string $role): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($role): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn([$role]);
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
