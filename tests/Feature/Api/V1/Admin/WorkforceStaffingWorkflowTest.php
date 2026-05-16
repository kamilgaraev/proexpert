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

final class WorkforceStaffingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_staffing_structure_assignment_and_staff_unit_period_guard(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'STAFF-001');
        $lateEmployee = $this->employee($context, 'STAFF-002');
        $this->allowAccess('web_admin');

        [$departmentId, $positionId, $staffUnitId] = $this->createStructure($context);

        $assignment = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $employee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'project_id' => $project->id,
                'rate' => 1,
                'valid_from' => '2026-05-01',
                'valid_to' => '2026-05-31',
            ]);

        $assignment->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.staff_unit_id', $staffUnitId)
            ->assertJsonPath('data.department_id', $departmentId)
            ->assertJsonPath('data.position_id', $positionId)
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.valid_from', '2026-05-01')
            ->assertJsonPath('data.valid_to', '2026-05-31');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $lateEmployee->id,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'project_id' => $project->id,
                'rate' => 0.5,
                'valid_from' => '2026-06-01',
                'valid_to' => '2026-06-30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.assignment_outside_staff_unit_period'));
    }

    private function employee(AdminApiTestContext $context, string $personnelNumber): WorkforceEmployee
    {
        return WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => $personnelNumber,
            'last_name' => 'Ivanov',
            'first_name' => 'Ivan',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
    }

    private function createStructure(AdminApiTestContext $context): array
    {
        $department = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/departments', [
                'code' => 'STAFF-DEP-' . uniqid(),
                'name' => 'Staff department',
            ]);
        $department->assertCreated();

        $position = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/positions', [
                'code' => 'STAFF-POS-' . uniqid(),
                'name' => 'Staff position',
            ]);
        $position->assertCreated();

        $staffUnit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/staff-units', [
                'department_id' => $department->json('data.id'),
                'position_id' => $position->json('data.id'),
                'code' => 'STAFF-UNIT-' . uniqid(),
                'headcount' => 1.5,
                'rate' => 1,
                'valid_from' => '2026-05-01',
                'valid_to' => '2026-05-31',
            ]);
        $staffUnit->assertCreated();

        return [
            (int) $department->json('data.id'),
            (int) $position->json('data.id'),
            (int) $staffUnit->json('data.id'),
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
