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

final class WorkforceStartWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_lists_updates_and_dismisses_start_employee(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        $created = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employees', [
                'personnel_number' => 'EMP-001',
                'last_name' => 'Иванов',
                'first_name' => 'Иван',
                'middle_name' => 'Иванович',
                'employment_status' => 'active',
                'hire_date' => '2026-05-01',
                'external_payroll_ref' => '1C-EMP-001',
            ]);

        $created->assertCreated()
            ->assertJsonPath('data.personnel_number', 'EMP-001')
            ->assertJsonPath('data.full_name', 'Иванов Иван Иванович')
            ->assertJsonPath('data.status_label', 'Работает');

        $employeeId = (int) $created->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/workforce/employees?search=Иванов')
            ->assertOk()
            ->assertJsonPath('data.0.id', $employeeId)
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/workforce/employees/{$employeeId}", [
                'phone' => '+79990000000',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone', '+79990000000');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/employees/{$employeeId}/dismiss", [
                'dismissal_date' => '2026-05-20',
            ])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'dismissed')
            ->assertJsonPath('data.dismissal_date', '2026-05-20');
    }

    public function test_employee_personnel_number_is_unique_per_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $this->allowAccess('web_admin');

        WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'EMP-001',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        WorkforceEmployee::create([
            'organization_id' => $foreignContext->organization->id,
            'personnel_number' => 'EMP-001',
            'last_name' => 'Петров',
            'first_name' => 'Петр',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employees', [
                'personnel_number' => 'EMP-001',
                'last_name' => 'Сидоров',
                'first_name' => 'Сидор',
                'hire_date' => '2026-05-02',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['personnel_number']);
    }

    public function test_payroll_timesheet_row_requires_employee_and_rejects_foreign_employee(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignEmployee = WorkforceEmployee::create([
            'organization_id' => $foreignContext->organization->id,
            'personnel_number' => 'FOREIGN-001',
            'last_name' => 'Чужой',
            'first_name' => 'Работник',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $this->allowAccess('web_admin');

        [$workOrderId, $lineId] = $this->createIssuedWorkOrder($context, $project->id);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => '2026-05-16',
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'hours' => 8,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('production_labor.errors.employee_required_for_payroll'));

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => '2026-05-16',
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'employee_id' => $foreignEmployee->id,
                    'hours' => 8,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('production_labor.errors.employee_not_found'));
    }

    public function test_free_text_worker_is_allowed_only_when_excluded_from_payroll(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'EMP-002',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => '2026-05-01',
        ]);
        $this->allowAccess('web_admin');

        [$workOrderId, $lineId] = $this->createIssuedWorkOrder($context, $project->id);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => '2026-05-16',
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'employee_id' => $employee->id,
                    'worker_name' => 'Свободный текст',
                    'hours' => 6,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('production_labor.errors.worker_name_not_allowed_for_payroll'));

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => '2026-05-16',
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'include_in_payroll' => false,
                    'worker_name' => 'Разовый исполнитель',
                    'hours' => 6,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.entries.0.include_in_payroll', false)
            ->assertJsonPath('data.entries.0.employee_id', null)
            ->assertJsonPath('data.entries.0.worker_name', 'Разовый исполнитель');
    }

    private function createIssuedWorkOrder(AdminApiTestContext $context, int $projectId): array
    {
        $workOrder = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $projectId,
                'order_number' => 'PL-' . uniqid(),
                'title' => 'Монтаж',
                'assignee_type' => 'brigade',
                'assignee_name' => 'Бригада',
                'lines' => [[
                    'name' => 'Монтаж',
                    'unit' => 'м3',
                    'planned_quantity' => 10,
                    'unit_rate' => 1000,
                ]],
            ]);

        $workOrder->assertCreated();
        $workOrderId = (int) $workOrder->json('data.id');
        $lineId = (int) $workOrder->json('data.lines.0.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/issue")
            ->assertOk();

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
