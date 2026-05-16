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

final class WorkforceScheduleCalendarWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_calendar_marks_workdays_days_off_absences_and_business_trips(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'CAL-001');
        $this->allowAccess('web_admin');

        [$departmentId, $positionId, $staffUnitId, $scheduleId] = $this->createStructureWithSchedule($context);

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

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules/' . $scheduleId . '/days', [
                'work_date' => '2026-05-23',
                'day_type' => 'weekend',
                'planned_hours' => 0,
            ])
            ->assertCreated();

        $absence = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-05-20',
                'end_date' => '2026-05-21',
            ]);
        $absence->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences/' . $absence->json('data.id') . '/approve')
            ->assertOk();

        $trip = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/business-trips', [
                'employee_id' => $employee->id,
                'project_id' => $project->id,
                'start_date' => '2026-05-22',
                'end_date' => '2026-05-22',
                'destination' => 'Объект Север',
            ]);
        $trip->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/business-trips/' . $trip->json('data.id') . '/approve')
            ->assertOk();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/schedule-calendar?date_from=2026-05-18&date_to=2026-05-24')
            ->assertOk()
            ->assertJsonPath('data.days.0.date', '2026-05-18')
            ->assertJsonPath('data.days.0.label', '18.05')
            ->assertJsonPath('data.days.0.weekday', 'Пн')
            ->assertJsonPath('data.employees.0.full_name', 'Иванов Иван')
            ->assertJsonPath('data.employees.0.assignment_label', 'Монтажник / Строительный участок')
            ->assertJsonPath('data.employees.0.days.2026-05-18.status_label', 'Рабочий день')
            ->assertJsonPath('data.employees.0.days.2026-05-18.hours', 8)
            ->assertJsonPath('data.employees.0.days.2026-05-20.status_label', 'Отпуск')
            ->assertJsonPath('data.employees.0.days.2026-05-22.status_label', 'Командировка')
            ->assertJsonPath('data.employees.0.days.2026-05-23.status_label', 'Выходной');
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

    private function createStructureWithSchedule(AdminApiTestContext $context): array
    {
        $department = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/departments', [
                'code' => 'CAL-DEP-' . uniqid(),
                'name' => 'Строительный участок',
            ]);
        $department->assertCreated();

        $position = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/positions', [
                'code' => 'CAL-POS-' . uniqid(),
                'name' => 'Монтажник',
            ]);
        $position->assertCreated();

        $staffUnit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/staff-units', [
                'department_id' => $department->json('data.id'),
                'position_id' => $position->json('data.id'),
                'code' => 'CAL-UNIT-' . uniqid(),
                'headcount' => 1,
                'rate' => 1,
                'valid_from' => '2026-05-01',
            ]);
        $staffUnit->assertCreated();

        $schedule = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'CAL-5-2-' . uniqid(),
                'name' => 'Пятидневка',
                'hours_per_day' => 8,
            ]);
        $schedule->assertCreated();

        return [
            (int) $department->json('data.id'),
            (int) $position->json('data.id'),
            (int) $staffUnit->json('data.id'),
            (int) $schedule->json('data.id'),
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
