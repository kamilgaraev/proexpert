<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceAbsenceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_absence_business_trip_and_sick_leave_block_conflicting_attendance(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');
        $employee = $this->employee($context, 'ABS-PROD-001');

        $absenceId = (int) $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/absences', [
                'employee_id' => $employee->id,
                'absence_type_code' => 'sick_leave',
                'start_date' => '2026-05-16',
                'end_date' => '2026-05-18',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status_label', 'Черновик')
            ->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/absences/{$absenceId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Согласовано');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/employees/{$employee->id}/attendance-corrections", [
                'work_date' => '2026-05-17',
                'status' => 'at_work',
                'hours' => 8,
                'reason' => 'Попытка поставить явку поверх больничного',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.attendance_conflicts_with_absence'));

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/attendance-sheet?date_from=2026-05-16&date_to=2026-05-18')
            ->assertOk()
            ->assertJsonMissingPath('data.rows.0.days.2026-05-17.status');
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
