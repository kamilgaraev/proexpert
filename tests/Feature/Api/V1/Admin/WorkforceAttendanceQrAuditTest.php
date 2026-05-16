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

final class WorkforceAttendanceQrAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_qr_scan_audit_and_attendance_sheet_source_without_token_values(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'name' => 'Объект Литейная']);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'personnel_number' => 'QR-ADM-001',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $this->createAssignment($context, $employee->id, $project->id);
        $this->allowAccess('web_admin');

        $token = 'raw-token-never-visible';

        DB::table('workforce_attendance_scan_events')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'scanned_by_user_id' => $context->user->id,
            'work_date' => '2026-05-16',
            'result' => 'confirmed',
            'result_label' => trans_message('workforce.attendance.qr_status_confirmed'),
            'scanned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/attendance/qr-scans')
            ->assertOk()
            ->assertJsonPath('data.0.employee_label', 'Иванов Иван')
            ->assertJsonPath('data.0.project_label', 'Объект Литейная')
            ->assertJsonPath('data.0.result_label', trans_message('workforce.attendance.qr_status_confirmed'))
            ->assertJsonMissing(['qr_token' => $token]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/attendance-sheet?date_from=2026-05-16&date_to=2026-05-16&project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.days.2026-05-16.status_label', 'На работе')
            ->assertJsonPath('data.rows.0.days.2026-05-16.source_label', trans_message('workforce.attendance.qr_source_label'));

        $this->assertSame(1, DB::table('workforce_attendance_scan_events')->where('result', 'confirmed')->count());
    }

    private function createAssignment(AdminApiTestContext $context, int $employeeId, int $projectId): void
    {
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'SITE',
            'name' => 'Строительный участок',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'MON',
            'name' => 'Монтажник',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scheduleId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'DAY',
            'name' => 'Дневная смена',
            'hours_per_day' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $context->organization->id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'SITE-MON',
            'valid_from' => '2026-05-01',
            'headcount' => 1,
            'rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workforce_employee_assignments')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employeeId,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'project_id' => $projectId,
            'work_schedule_id' => $scheduleId,
            'valid_from' => '2026-05-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
