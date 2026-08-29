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
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceAttendanceCalendarConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_attendance_sheet_applies_week_pattern_absence_and_business_trip(): void
    {
        // Regression: ISSUE-080 — табель считал отпуск, командировку и выходные рабочими днями
        // Found by /qa on 2026-08-29
        // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employeeWithFiveTwoAssignment($context, $project->id);
        $this->createApprovedVacationAndTrip($context, $employee->id, $project->id);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/attendance-sheet?date_from=2026-08-31&date_to=2026-09-07')
            ->assertOk()
            ->assertJsonPath('data.rows.0.days.2026-08-31.status', 'not_at_work')
            ->assertJsonPath('data.rows.0.days.2026-08-31.hours', 0)
            ->assertJsonPath('data.rows.0.days.2026-09-01.status', 'at_work')
            ->assertJsonPath('data.rows.0.days.2026-09-01.hours', 8)
            ->assertJsonPath('data.rows.0.days.2026-09-02.status', 'absence')
            ->assertJsonPath('data.rows.0.days.2026-09-02.status_label', 'Отпуск')
            ->assertJsonPath('data.rows.0.days.2026-09-02.hours', 0)
            ->assertJsonPath('data.rows.0.days.2026-09-02.source_label', 'По отсутствию')
            ->assertJsonPath('data.rows.0.days.2026-09-04.status', 'business_trip')
            ->assertJsonPath('data.rows.0.days.2026-09-04.hours', 0)
            ->assertJsonPath('data.rows.0.days.2026-09-04.source_label', 'По командировке')
            ->assertJsonPath('data.rows.0.days.2026-09-05.status', 'scheduled_day_off')
            ->assertJsonPath('data.rows.0.days.2026-09-05.hours', 0)
            ->assertJsonPath('data.rows.0.days.2026-09-06.status', 'scheduled_day_off')
            ->assertJsonPath('data.rows.0.days.2026-09-06.hours', 0)
            ->assertJsonPath('data.rows.0.days.2026-09-07.status', 'at_work')
            ->assertJsonPath('data.rows.0.days.2026-09-07.hours', 8);
    }

    private function employeeWithFiveTwoAssignment(AdminApiTestContext $context, int $projectId): WorkforceEmployee
    {
        $employee = WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'QA-ATTENDANCE-CALENDAR',
            'last_name' => 'Волков',
            'first_name' => 'Алексей',
            'employment_status' => 'active',
            'hire_date' => '2026-09-01',
        ]);
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'QA-ATTENDANCE-DEP',
            'name' => 'Производственный участок',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'QA-ATTENDANCE-POS',
            'name' => 'Производитель работ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scheduleId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'QA-ATTENDANCE-5D2',
            'name' => 'Пятидневка',
            'schedule_type' => 'five_two',
            'hours_per_day' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $context->organization->id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'QA-ATTENDANCE-UNIT',
            'valid_from' => '2026-09-01',
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
            'valid_from' => '2026-09-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employee;
    }

    private function createApprovedVacationAndTrip(AdminApiTestContext $context, int $employeeId, int $projectId): void
    {
        $absenceTypeId = DB::table('workforce_absence_types')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'vacation',
            'name' => 'Отпуск',
            'affects_payroll' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workforce_absences')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employeeId,
            'absence_type_id' => $absenceTypeId,
            'start_date' => '2026-09-02',
            'end_date' => '2026-09-03',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workforce_absences')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employeeId,
            'absence_type_id' => $absenceTypeId,
            'start_date' => '2026-08-31',
            'end_date' => '2026-08-31',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('workforce_business_trips')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employeeId,
            'project_id' => $projectId,
            'start_date' => '2026-09-04',
            'end_date' => '2026-09-04',
            'destination' => 'Казань',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

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
