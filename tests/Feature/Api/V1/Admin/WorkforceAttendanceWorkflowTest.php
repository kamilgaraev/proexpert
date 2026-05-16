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

final class WorkforceAttendanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_sheet_shows_presence_by_project_and_manual_correction_history(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employeeWithAssignment($context, 'ATT-001', $project->id);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/employees/{$employee->id}/attendance-corrections", [
                'project_id' => $project->id,
                'work_date' => '2026-05-16',
                'status' => 'at_work',
                'hours' => 8,
                'reason' => 'Подтверждено руководителем работ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status_label', 'На работе')
            ->assertJsonPath('data.reason', 'Подтверждено руководителем работ')
            ->assertJsonPath('data.source_label', 'Ручная корректировка');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/attendance-sheet?project_id={$project->id}&date_from=2026-05-01&date_to=2026-05-31")
            ->assertOk()
            ->assertJsonPath('data.days.0.date', '2026-05-01')
            ->assertJsonPath('data.rows.0.employee_label', 'Иванов Иван')
            ->assertJsonPath('data.rows.0.days.2026-05-16.status_label', 'На работе')
            ->assertJsonPath('data.rows.0.days.2026-05-16.hours', 8)
            ->assertJsonPath('data.rows.0.days.2026-05-16.source_label', 'Ручная корректировка');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/employees/{$employee->id}/attendance-corrections")
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'Подтверждено руководителем работ')
            ->assertJsonPath('data.0.status_label', 'На работе')
            ->assertJsonPath('data.0.source_label', 'Ручная корректировка');
    }

    public function test_locked_payroll_period_rejects_manual_attendance_correction(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employeeWithAssignment($context, 'ATT-LOCK', $project->id);

        DB::table('workforce_payroll_periods')->insert([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'locked',
            'created_by_user_id' => $context->user->id,
            'locked_by_user_id' => $context->user->id,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/employees/{$employee->id}/attendance-corrections", [
                'project_id' => $project->id,
                'work_date' => '2026-05-16',
                'status' => 'at_work',
                'hours' => 8,
                'reason' => 'Подтверждено руководителем работ',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_locked'));
    }

    private function employeeWithAssignment(AdminApiTestContext $context, string $personnelNumber, int $projectId): WorkforceEmployee
    {
        $employee = WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => $personnelNumber,
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);

        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'ATT-DEP-' . uniqid(),
            'name' => 'Строительный участок',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'ATT-POS-' . uniqid(),
            'name' => 'Монтажник',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scheduleId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'ATT-SCH-' . uniqid(),
            'name' => 'Пятидневка',
            'hours_per_day' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $context->organization->id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'ATT-UNIT-' . uniqid(),
            'valid_from' => '2026-05-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workforce_employee_assignments')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employee->id,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'project_id' => $projectId,
            'work_schedule_id' => $scheduleId,
            'rate' => 1,
            'valid_from' => '2026-05-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employee;
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
