<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceScheduleCalendarWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_schedule_calendar_marks_workdays_days_off_absences_and_business_trips(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'CAL-001');
        $this->allowAccess('web_admin');

        [$departmentId, $positionId, $staffUnitId, $scheduleId] = $this->createStructureWithSchedule($context);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'basis_reference' => 'Основание тестового кадрового события',
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
            ->postJson('/api/v1/admin/workforce/work-schedules/'.$scheduleId.'/days', [
                'work_date' => '2026-05-23',
                'day_type' => 'work',
                'planned_hours' => 6,
            ])
            ->assertCreated();

        $absence = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'basis_reference' => 'Основание тестового кадрового события',
                'reason_code' => 'vacation',
                'employee_id' => $employee->id,
                'absence_type_code' => 'vacation',
                'start_date' => '2026-05-20',
                'end_date' => '2026-05-21',
            ]);
        $absence->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences/'.$absence->json('data.id').'/approve')
            ->assertOk();

        $trip = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/business-trips', [
                'basis_reference' => 'Основание тестового кадрового события',
                'reason_code' => 'business_trip',
                'employee_id' => $employee->id,
                'project_id' => $project->id,
                'start_date' => '2026-05-22',
                'end_date' => '2026-05-22',
                'destination' => 'Объект Север',
            ]);
        $trip->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/business-trips/'.$trip->json('data.id').'/approve')
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
            ->assertJsonPath('data.employees.0.days.2026-05-23.status_label', 'Рабочий день')
            ->assertJsonPath('data.employees.0.days.2026-05-23.hours', 6)
            ->assertJsonPath('data.employees.0.days.2026-05-24.status_label', 'Выходной')
            ->assertJsonPath('data.employees.0.days.2026-05-24.hours', 0);
    }

    public function test_work_schedule_rejects_invalid_week_pattern(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'INVALID-WEEK-'.uniqid(),
                'name' => 'Некорректная неделя',
                'schedule_type' => 'weekly',
                'hours_per_day' => 8,
                'week_pattern' => ['work_days' => [1, 1, 8]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'week_pattern.work_days.1',
                'week_pattern.work_days.2',
            ]);
    }

    public function test_work_schedule_creation_atomically_includes_an_optional_day(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'ATOMIC-DAY', 'name' => 'Учебный график с исключением',
                'hours_per_day' => 8,
                'initial_day' => [
                    'work_date' => '2026-05-23', 'day_type' => 'work',
                    'planned_hours' => 6, 'comment' => 'Учебное исключение',
                ],
            ]);

        $response->assertCreated()->assertJsonPath('data.organization_id', $context->organization->id);
        $this->assertDatabaseHas('workforce_work_schedule_days', [
            'organization_id' => $context->organization->id,
            'work_schedule_id' => $response->json('data.id'),
            'work_date' => '2026-05-23', 'day_type' => 'work',
            'planned_hours' => 6, 'comment' => 'Учебное исключение',
        ]);
        $this->assertSame(1, DB::table('workforce_work_schedules')->where('organization_id', $context->organization->id)->count());
    }

    public function test_work_schedule_creation_rolls_back_when_its_day_is_in_a_locked_period(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        DB::table('workforce_payroll_periods')->insert([
            'organization_id' => $context->organization->id,
            'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'status' => 'locked', 'created_by_user_id' => $context->user->id,
            'locked_by_user_id' => $context->user->id, 'locked_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'ATOMIC-LOCKED', 'name' => 'Не должен сохраниться',
                'initial_day' => ['work_date' => '2026-05-23', 'day_type' => 'weekend', 'planned_hours' => 0],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_locked'));

        $this->assertDatabaseMissing('workforce_work_schedules', ['organization_id' => $context->organization->id, 'code' => 'ATOMIC-LOCKED']);
        $this->assertSame(0, DB::table('workforce_work_schedule_days')->where('organization_id', $context->organization->id)->count());
    }

    public function test_work_schedule_creation_rejects_invalid_initial_day_without_saving_a_schedule(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'ATOMIC-INVALID', 'name' => 'Не должен сохраниться',
                'initial_day' => ['day_type' => 'invalid', 'planned_hours' => 25],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['initial_day.work_date', 'initial_day.day_type', 'initial_day.planned_hours']);

        $this->assertDatabaseMissing('workforce_work_schedules', ['organization_id' => $context->organization->id, 'code' => 'ATOMIC-INVALID']);
    }

    public function test_work_schedule_creation_without_initial_day_keeps_the_existing_contract(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'ATOMIC-NO-DAY', 'name' => 'График без исключения', 'initial_day' => null,
            ])
            ->assertCreated()->assertJsonPath('data.code', 'ATOMIC-NO-DAY');

        $this->assertSame(0, DB::table('workforce_work_schedule_days')->where('organization_id', $context->organization->id)->count());
    }

    public function test_work_schedule_creation_rejects_empty_days_and_explicit_null_required_columns(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $responses = [];

        foreach ([
            [],
            ['work_date' => '2026-05-23', 'day_type' => null],
            ['work_date' => '2026-05-23', 'planned_hours' => null],
        ] as $index => $day) {
            $responses[] = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/workforce/work-schedules', [
                    'code' => 'ATOMIC-EMPTY-'.$index, 'name' => 'Не должен сохраниться',
                    'initial_day' => $day,
                ]);
        }

        $this->assertSame([422, 422, 422], array_map(static fn ($response): int => $response->status(), $responses));
        $responses[0]->assertJsonValidationErrors('initial_day.work_date');
        $responses[1]->assertJsonValidationErrors('initial_day.day_type');
        $responses[2]->assertJsonValidationErrors('initial_day.planned_hours');
        $this->assertSame(0, DB::table('workforce_work_schedules')->where('organization_id', $context->organization->id)->count());
        $this->assertSame(0, DB::table('workforce_work_schedule_days')->where('organization_id', $context->organization->id)->count());
    }

    public function test_work_schedule_initial_day_uses_defaults_for_omitted_optional_fields(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'ATOMIC-DEFAULTS', 'name' => 'График с обычным рабочим днём',
                'initial_day' => ['work_date' => '2026-05-23'],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('workforce_work_schedule_days', [
            'organization_id' => $context->organization->id,
            'work_schedule_id' => $response->json('data.id'),
            'work_date' => '2026-05-23', 'day_type' => 'work', 'planned_hours' => 8,
        ]);
    }

    public function test_unassigned_schedule_days_are_readable_in_date_order_with_pagination(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/workforce/work-schedules', [
            'code' => 'READ-DAYS', 'name' => 'График без назначения',
            'initial_day' => ['work_date' => '2026-05-25', 'day_type' => 'weekend', 'planned_hours' => 0],
        ]);
        $response->assertCreated();
        $scheduleId = $response->json('data.id');
        foreach (['2026-05-23', '2026-05-24'] as $date) {
            $this->withHeaders($context->authHeaders())->postJson("/api/v1/admin/workforce/work-schedules/{$scheduleId}/days", [
                'work_date' => $date, 'day_type' => 'work', 'planned_hours' => 6.5,
            ])->assertCreated();
        }
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/workforce/work-schedules', [
            'code' => 'READ-SIBLING', 'name' => 'Другой график',
            'initial_day' => ['work_date' => '2026-05-27'],
        ])->assertCreated();
        $foreignOrganization = Organization::factory()->create();
        DB::table('workforce_work_schedule_days')->insert([
            'organization_id' => $foreignOrganization->id, 'work_schedule_id' => $scheduleId,
            'work_date' => '2026-05-26', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/work-schedules/{$scheduleId}/days?per_page=2")
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.work_date', '2026-05-23')
            ->assertJsonPath('data.1.work_date', '2026-05-24')->assertJsonPath('data.0.planned_hours', '6.50');
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/work-schedules/{$scheduleId}/days?per_page=2&page=2")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.work_date', '2026-05-25')
            ->assertJsonPath('data.0.day_type', 'weekend')->assertJsonPath('data.0.planned_hours', '0.00')
            ->assertJsonPath('data.0.organization_id', $context->organization->id);
        $this->assertSame(0, DB::table('workforce_employee_assignments')->where('organization_id', $context->organization->id)->count());
    }

    public function test_schedule_days_read_rejects_foreign_deleted_and_missing_parents(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $foreignOrganization = Organization::factory()->create();
        $foreignId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $foreignOrganization->id, 'code' => 'FOREIGN', 'name' => 'Чужой график',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $deletedId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $context->organization->id, 'code' => 'DELETED', 'name' => 'Удалённый график',
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$foreignId, $deletedId, 999999] as $id) {
            $this->withHeaders($context->authHeaders())
                ->getJson("/api/v1/admin/workforce/work-schedules/{$id}/days")
                ->assertNotFound()->assertJsonPath('message', trans_message('workforce.errors.record_not_found'));
        }
    }

    public function test_schedule_days_read_returns_a_paginated_empty_list_for_an_ordinary_schedule(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $response = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/workforce/work-schedules', [
            'code' => 'READ-NO-DAYS', 'name' => 'Обычный график',
        ]);
        $response->assertCreated();
        $id = $response->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/work-schedules/{$id}/days")
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_schedule_days_service_requires_an_actor_with_organization_access(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $this->expectException(AuthorizationException::class);

        app(WorkforceProService::class)->scheduleDays($context->organization->id, 1, null);
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
                'code' => 'CAL-DEP-'.uniqid(),
                'name' => 'Строительный участок',
            ]);
        $department->assertCreated();

        $position = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/positions', [
                'code' => 'CAL-POS-'.uniqid(),
                'name' => 'Монтажник',
            ]);
        $position->assertCreated();

        $staffUnit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/staff-units', [
                'department_id' => $department->json('data.id'),
                'position_id' => $position->json('data.id'),
                'code' => 'CAL-UNIT-'.uniqid(),
                'headcount' => 1,
                'rate' => 1,
                'valid_from' => '2026-05-01',
            ]);
        $staffUnit->assertCreated();

        $schedule = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'CAL-5-2-'.uniqid(),
                'name' => 'Пятидневка',
                'schedule_type' => 'weekly',
                'hours_per_day' => 8,
                'week_pattern' => ['work_days' => [1, 2, 3, 4, 5]],
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
            $mock->shouldReceive('canCurrent')->andReturn(true);
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
