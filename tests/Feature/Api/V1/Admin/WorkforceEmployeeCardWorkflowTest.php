<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceEmployeeCardWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_card_shows_current_assignment_schedule_presence_and_lifecycle_actions(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $employee = $this->employee($context, 'EMP-CARD-001');
        [$departmentId, $positionId, $staffUnitId, $scheduleId] = $this->createStructure($context);

        DB::table('workforce_employee_assignments')->insert([
            'organization_id' => $context->organization->id,
            'employee_id' => $employee->id,
            'staff_unit_id' => $staffUnitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'work_schedule_id' => $scheduleId,
            'rate' => 1,
            'valid_from' => '2026-05-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('workforce_work_schedule_days')->insert([
            'organization_id' => $context->organization->id,
            'work_schedule_id' => $scheduleId,
            'work_date' => '2026-05-16',
            'day_type' => 'work',
            'planned_hours' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/employees/{$employee->id}/card?work_date=2026-05-16")
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Иванов Иван')
            ->assertJsonPath('data.employment_status_label', trans_message('workforce.employee_statuses.active'))
            ->assertJsonPath('data.presence.status', 'at_work')
            ->assertJsonPath('data.presence.label', 'На работе')
            ->assertJsonPath('data.current_assignment.department_label', 'Строительный участок')
            ->assertJsonPath('data.current_assignment.position_label', 'Монтажник')
            ->assertJsonPath('data.current_schedule.label', 'Пятидневка')
            ->assertJsonPath('data.available_actions.dismiss.enabled', true)
            ->assertJsonPath('data.available_actions.assign.enabled', true);
    }

    public function test_employee_card_is_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $otherContext = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $otherEmployee = $this->employee($otherContext, 'EMP-OTHER-001');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/employees/{$otherEmployee->id}/card?work_date=2026-05-16")
            ->assertNotFound()
            ->assertJsonPath('message', trans_message('workforce.errors.employee_not_found'));
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

    private function createStructure(AdminApiTestContext $context): array
    {
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'CARD-DEP-' . uniqid(),
            'name' => 'Строительный участок',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'CARD-POS-' . uniqid(),
            'name' => 'Монтажник',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staffUnitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $context->organization->id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'CARD-UNIT-' . uniqid(),
            'headcount' => 1,
            'rate' => 1,
            'valid_from' => '2026-05-01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scheduleId = DB::table('workforce_work_schedules')->insertGetId([
            'organization_id' => $context->organization->id,
            'code' => 'CARD-5-2-' . uniqid(),
            'name' => 'Пятидневка',
            'schedule_type' => 'weekly',
            'hours_per_day' => 8,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$departmentId, $positionId, $staffUnitId, $scheduleId];
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
