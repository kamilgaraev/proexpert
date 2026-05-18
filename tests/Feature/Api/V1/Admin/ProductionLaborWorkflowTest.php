<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProductionLaborWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_labor_work_order_output_timesheet_payroll_and_scope_guards(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $employee = WorkforceEmployee::create([
            'organization_id' => $context->organization->id,
            'personnel_number' => 'EMP-001',
            'last_name' => 'Worker',
            'first_name' => 'One',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);
        $this->allowAccess('web_admin');

        $workOrderResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $project->id,
                'order_number' => 'PL-001',
                'title' => 'Concrete crew shift',
                'assignee_type' => 'brigade',
                'assignee_name' => 'Crew A',
                'lines' => [
                    [
                        'name' => 'Concrete works',
                        'unit' => 'm3',
                        'planned_quantity' => 10,
                        'unit_rate' => 1200,
                        'planned_hours' => 40,
                        'hour_rate' => 500,
                        'pay_basis' => 'volume',
                        'requires_safety_permit' => true,
                    ],
                ],
            ]);

        $workOrderResponse->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.available_actions.0', 'issue')
            ->assertJsonPath('data.lines.0.remaining_quantity', 10);
        $workOrderId = (int) $workOrderResponse->json('data.id');
        $lineId = (int) $workOrderResponse->json('data.lines.0.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/issue")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.available_actions.0', 'start');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/output-entries', [
                'work_order_line_id' => $lineId,
                'work_date' => now()->toDateString(),
                'quantity' => 6,
                'hours' => 24,
            ])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 6)
            ->assertJsonPath('data.workflow_summary.status', 'accepted');

        $overPlan = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/output-entries', [
                'work_order_line_id' => $lineId,
                'work_date' => now()->toDateString(),
                'quantity' => 5,
                'hours' => 16,
            ]);
        $overPlan->assertStatus(422);

        $timesheetWithoutPermit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => now()->toDateString(),
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'employee_id' => $employee->id,
                    'hours' => 8,
                ]],
            ]);
        $timesheetWithoutPermit->assertStatus(422);

        $timesheetWithUnknownPermit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => now()->toDateString(),
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'employee_id' => $employee->id,
                    'hours' => 8,
                    'safety_permit_reference' => 'WP-MISSING',
                ]],
            ]);
        $timesheetWithUnknownPermit->assertStatus(422);

        SafetyWorkPermit::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'permit_number' => 'HSE-P-VALID',
            'title' => 'Concrete crew safety permit',
            'permit_type' => 'high_risk_work',
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
            'risk_level' => 'high',
            'status' => 'active',
        ]);

        $timesheet = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/timesheets', [
                'work_order_id' => $workOrderId,
                'shift_date' => now()->toDateString(),
                'entries' => [[
                    'work_order_line_id' => $lineId,
                    'employee_id' => $employee->id,
                    'hours' => 8,
                    'safety_permit_reference' => 'HSE-P-VALID',
                ]],
            ]);
        $timesheet->assertCreated()
            ->assertJsonPath('data.entries.0.employee_id', $employee->id)
            ->assertJsonPath('data.entries.0.include_in_payroll', true)
            ->assertJsonPath('data.entries.0.safety_permit_reference', 'HSE-P-VALID');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/production-labor/work-orders/{$workOrderId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $payroll = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/payroll-accruals/prepare', [
                'work_order_id' => $workOrderId,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
            ]);
        $payroll->assertCreated()
            ->assertJsonPath('data.0.amount', 7200)
            ->assertJsonPath('data.0.payment_payload.source', 'production-labor')
            ->assertJsonPath('data.0.project_id', $project->id);

        $duplicatePayroll = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/payroll-accruals/prepare', [
                'work_order_id' => $workOrderId,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
            ]);
        $duplicatePayroll->assertStatus(422);

        $reports = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/production-labor/reports?project_id={$project->id}");
        $reports->assertOk()
            ->assertJsonPath('data.output_by_project.0.project_id', $project->id)
            ->assertJsonPath('data.payroll_by_project.0.amount', '7200.00');

        $foreignWorkOrder = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $foreignProject->id,
                'order_number' => 'PL-FOREIGN',
                'title' => 'Foreign project',
                'assignee_type' => 'brigade',
                'lines' => [[
                    'name' => 'Foreign line',
                    'planned_quantity' => 1,
                ]],
            ]);
        $foreignWorkOrder->assertStatus(422);
    }

    private function allowAccess(string $role): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'production-labor',
                    'project-management',
                ], true)
            );
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
