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
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforcePayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_labor_timesheet_is_paid_only_through_workforce_source(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'EMP-PAYROLL-001',
            'last_name' => 'Worker',
            'first_name' => 'One',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $this->allowAccess('web_admin');

        $workOrder = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $project->id,
                'order_number' => 'PL-PAYROLL-001',
                'title' => 'Concrete crew shift',
                'assignee_type' => 'brigade',
                'lines' => [[
                    'name' => 'Concrete works',
                    'planned_quantity' => 10,
                    'planned_hours' => 8,
                    'hour_rate' => 500,
                    'pay_basis' => 'hours',
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
                    'employee_id' => $employee->id,
                    'hours' => 8,
                ]],
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/submit")
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/accept")
            ->assertOk();

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
            ->assertJsonPath('data.0.amount', '4000.00');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/payroll-accruals/prepare', [
                'work_order_id' => $workOrderId,
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
            ])
            ->assertNotFound();

        $this->assertSame(0, DB::table('production_labor_payroll_accruals')->count());
    }

    public function test_payroll_validation_blocks_timesheet_and_output_mismatches(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $organizationId = $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $now = now();
        $employeeIds = collect(range(1, 4))->map(function (int $index) use ($organizationId, $now): int {
            return DB::table('workforce_employees')->insertGetId([
                'organization_id' => $organizationId,
                'personnel_number' => "EMP-VALIDATION-{$index}",
                'last_name' => 'Worker',
                'first_name' => (string) $index,
                'employment_status' => 'active',
                'hire_date' => '2026-05-01',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'VALIDATION',
            'name' => 'Validation',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'VALIDATION',
            'name' => 'Validation',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'VALIDATION',
            'valid_from' => '2026-05-01',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scheduleId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'VALIDATION',
            'name' => 'Validation',
            'hours_per_day' => 8,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($employeeIds->slice(1) as $employeeId) {
            DB::table('workforce_employee_assignments')->insert([
                'organization_id' => $organizationId,
                'employee_id' => $employeeId,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'work_schedule_id' => $scheduleId,
                'valid_from' => '2026-05-01',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('workforce_work_schedule_days')->insert([
            'organization_id' => $organizationId,
            'work_schedule_id' => $scheduleId,
            'work_date' => '2026-05-11',
            'day_type' => 'day_off',
            'planned_hours' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $absenceTypeId = DB::table('workforce_absence_types')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'VACATION',
            'name' => 'Vacation',
            'affects_payroll' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workforce_absences')->insert([
            'organization_id' => $organizationId,
            'employee_id' => $employeeIds->get(2),
            'absence_type_id' => $absenceTypeId,
            'start_date' => '2026-05-12',
            'end_date' => '2026-05-12',
            'status' => 'approved',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $periodId = DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'draft',
            'created_by_user_id' => $context->user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $workOrderId = DB::table('production_labor_work_orders')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'order_number' => 'VALIDATION-001',
            'title' => 'Validation',
            'status' => 'accepted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lineIds = collect(range(1, 6))->map(function (int $index) use ($organizationId, $workOrderId, $now): int {
            return DB::table('production_labor_work_order_lines')->insertGetId([
                'organization_id' => $organizationId,
                'work_order_id' => $workOrderId,
                'name' => "Line {$index}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        foreach ([
            [$employeeIds->get(0), $lineIds->get(0), '2026-05-10', 8],
            [$employeeIds->get(1), $lineIds->get(1), '2026-05-11', 8],
            [$employeeIds->get(2), $lineIds->get(2), '2026-05-12', 8],
            [$employeeIds->get(3), $lineIds->get(3), '2026-05-13', 8],
            [$employeeIds->get(3), $lineIds->get(4), '2026-05-15', 8],
        ] as [$employeeId, $lineId, $workDate, $hours]) {
            DB::table('workforce_payroll_source_rows')->insert([
                'organization_id' => $organizationId,
                'payroll_period_id' => $periodId,
                'employee_id' => $employeeId,
                'project_id' => $project->id,
                'work_order_id' => $workOrderId,
                'work_order_line_id' => $lineId,
                'work_date' => $workDate,
                'source_type' => 'timesheet_hours',
                'hours' => $hours,
                'amount' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            [$lineIds->get(0), '2026-05-10', 8],
            [$lineIds->get(1), '2026-05-11', 8],
            [$lineIds->get(2), '2026-05-12', 8],
            [$lineIds->get(3), '2026-05-13', 4],
            [$lineIds->get(5), '2026-05-14', 8],
        ] as [$lineId, $workDate, $hours]) {
            DB::table('production_labor_output_entries')->insert([
                'organization_id' => $organizationId,
                'work_order_id' => $workOrderId,
                'work_order_line_id' => $lineId,
                'project_id' => $project->id,
                'recorded_by_user_id' => $context->user->id,
                'approved_by_user_id' => $context->user->id,
                'work_date' => $workDate,
                'quantity' => 1,
                'hours' => $hours,
                'status' => 'accepted',
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('production_labor_output_entries')->insert([
            'organization_id' => $organizationId,
            'work_order_id' => $workOrderId,
            'work_order_line_id' => $lineIds->get(4),
            'project_id' => $project->id,
            'recorded_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'work_date' => '2026-05-15',
            'quantity' => 1,
            'hours' => 8,
            'status' => 'accepted',
            'approved_at' => $now,
            'deleted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertOk();

        $issueCodes = collect($response->json('data'))->pluck('issue_code');
        $this->assertTrue($issueCodes->contains('missing_assignment'));
        $this->assertTrue($issueCodes->contains('work_schedule_conflict'));
        $this->assertTrue($issueCodes->contains('absence_conflict'));
        $this->assertTrue($issueCodes->contains('missing_output'));
        $this->assertTrue($issueCodes->contains('output_without_timesheet'));
        $this->assertTrue($issueCodes->contains('hours_output_mismatch'));
        $this->assertTrue(
            collect($response->json('data'))->contains(
                fn (array $issue): bool => $issue['issue_code'] === 'missing_output'
                    && json_decode($issue['payload'], true, 512, JSON_THROW_ON_ERROR)['work_date'] === '2026-05-15'
            )
        );
        $this->assertSame('draft', DB::table('workforce_payroll_periods')->where('id', $periodId)->value('status'));
    }

    public function test_locked_payroll_period_rejects_new_production_labor_output_and_timesheet(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $project = Project::factory()->create(['organization_id' => $organizationId]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $organizationId,
            'personnel_number' => 'EMP-LOCKED-LABOR-001',
            'last_name' => 'Worker',
            'first_name' => 'Locked',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $this->allowAccess('web_admin');

        $now = now();
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'LOCKED-LABOR',
            'name' => 'Locked labor',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'LOCKED-LABOR',
            'name' => 'Locked labor',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'LOCKED-LABOR',
            'valid_from' => '2026-05-01',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scheduleId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'LOCKED-LABOR',
            'name' => 'Locked labor',
            'hours_per_day' => 8,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workforce_employee_assignments')->insert([
            'organization_id' => $organizationId,
            'employee_id' => $employee->id,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'project_id' => $project->id,
            'work_schedule_id' => $scheduleId,
            'valid_from' => '2026-05-01',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workOrder = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $project->id,
                'order_number' => 'LOCKED-LABOR-001',
                'title' => 'Locked labor',
                'assignee_type' => 'brigade',
                'lines' => [[
                    'name' => 'Locked labor',
                    'planned_quantity' => 10,
                    'planned_hours' => 8,
                    'hour_rate' => 500,
                    'pay_basis' => 'hours',
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
                    'employee_id' => $employee->id,
                    'hours' => 8,
                ]],
            ])
            ->assertCreated();

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
            ->assertOk();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/accounting-mappings', [
                'scope_type' => 'project',
                'scope_id' => $project->id,
                'accounting_account' => '20.01',
            ])
            ->assertCreated();
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/lock")
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/output-entries', [
                'work_order_line_id' => $lineId,
                'work_date' => '2026-05-16',
                'quantity' => 1,
                'hours' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('production_labor.errors.payroll_period_locked'));

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => '2026-05-16',
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'employee_id' => $employee->id,
                    'hours' => 1,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('production_labor.errors.payroll_period_locked'));
    }

    public function test_organization_wide_locked_payroll_period_rejects_output_for_all_projects(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $firstProject = Project::factory()->create(['organization_id' => $organizationId]);
        $secondProject = Project::factory()->create(['organization_id' => $organizationId]);
        $this->allowAccess('web_admin');

        $firstWorkOrder = $this->createIssuedWorkOrderForProject($context, $firstProject, 'ORG-LOCK-001');
        $secondWorkOrder = $this->createIssuedWorkOrderForProject($context, $secondProject, 'ORG-LOCK-002');
        $now = now();

        DB::table('workforce_payroll_periods')->insert([
            'organization_id' => $organizationId,
            'project_id' => null,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'locked',
            'created_by_user_id' => $context->user->id,
            'locked_by_user_id' => $context->user->id,
            'locked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$firstWorkOrder, $secondWorkOrder] as $workOrder) {
            $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/production-labor/output-entries', [
                    'work_order_line_id' => $workOrder['line_id'],
                    'work_date' => '2026-05-16',
                    'quantity' => 1,
                    'hours' => 1,
                ])
                ->assertStatus(422)
                ->assertJsonPath('message', trans_message('production_labor.errors.payroll_period_locked'));
        }
    }

    public function test_project_locked_payroll_period_does_not_reject_output_for_another_project(): void
    {
        $context = AdminApiTestContext::create();
        $organizationId = (int) $context->organization->id;
        $lockedProject = Project::factory()->create(['organization_id' => $organizationId]);
        $availableProject = Project::factory()->create(['organization_id' => $organizationId]);
        $this->allowAccess('web_admin');

        $availableWorkOrder = $this->createIssuedWorkOrderForProject($context, $availableProject, 'PROJECT-LOCK-001');
        $now = now();

        DB::table('workforce_payroll_periods')->insert([
            'organization_id' => $organizationId,
            'project_id' => $lockedProject->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'locked',
            'created_by_user_id' => $context->user->id,
            'locked_by_user_id' => $context->user->id,
            'locked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/output-entries', [
                'work_order_line_id' => $availableWorkOrder['line_id'],
                'work_date' => '2026-05-16',
                'quantity' => 1,
                'hours' => 1,
            ])
            ->assertCreated();
    }

    public function test_locked_payroll_period_cannot_rebuild_source_or_revalidate(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $now = now();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'EMP-LOCKED-PERIOD-001',
            'last_name' => 'Locked',
            'first_name' => 'Period',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $periodId = DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'locked',
            'source_hash' => 'locked-source-hash',
            'created_by_user_id' => $context->user->id,
            'locked_by_user_id' => $context->user->id,
            'locked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
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
            'payload' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workforce_payroll_validation_issues')->insert([
            'organization_id' => $context->organization->id,
            'payroll_period_id' => $periodId,
            'issue_code' => 'missing_assignment',
            'message' => 'Existing issue',
            'severity' => 'blocking',
            'entity_type' => 'payroll_source_row',
            'payload' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/build-source")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_locked'));
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_locked'));

        $this->assertSame('locked', DB::table('workforce_payroll_periods')->where('id', $periodId)->value('status'));
        $this->assertSame('locked-source-hash', DB::table('workforce_payroll_periods')->where('id', $periodId)->value('source_hash'));
        $this->assertSame(1, DB::table('workforce_payroll_source_rows')->where('payroll_period_id', $periodId)->count());
        $this->assertSame(1, DB::table('workforce_payroll_validation_issues')->where('payroll_period_id', $periodId)->count());
    }

    private function createIssuedWorkOrderForProject(AdminApiTestContext $context, Project $project, string $orderNumber): array
    {
        $workOrder = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $project->id,
                'order_number' => $orderNumber,
                'title' => 'Payroll lock test',
                'assignee_type' => 'brigade',
                'lines' => [[
                    'name' => 'Payroll lock line',
                    'planned_quantity' => 10,
                    'planned_hours' => 8,
                    'hour_rate' => 500,
                    'pay_basis' => 'hours',
                ]],
            ]);
        $workOrder->assertCreated();
        $workOrderId = (int) $workOrder->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/issue")
            ->assertOk();

        return [
            'work_order_id' => $workOrderId,
            'line_id' => (int) $workOrder->json('data.lines.0.id'),
        ];
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
