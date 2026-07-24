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
