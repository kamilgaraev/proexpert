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
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceCorporateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_and_inactive_accounting_mapping_blocks_lock_then_active_mapping_locks_period(): void
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'EMP-CORP-100');
        $this->allowAccess('web_admin');

        $periodId = $this->preparedValidatedPeriod($context, $project->id, $employee->id);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/lock")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_has_blocking_issues'));

        $issues = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validation-issues");
        $this->assertContains('missing_accounting_mapping', collect($issues->json('data'))->pluck('issue_code')->all());

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/accounting-mappings', [
                'scope_type' => 'organization',
                'accounting_account' => '20.01',
                'is_active' => false,
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/lock")
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/accounting-mappings', [
                'scope_type' => 'organization',
                'accounting_account' => '20.02',
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/lock")
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/build-source")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_locked'));
    }

    public function test_export_package_lifecycle_files_and_supersede_flow(): void
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'EMP-CORP-200');
        $this->allowAccess('web_admin');

        $periodId = $this->preparedValidatedPeriod($context, $project->id, $employee->id);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/export-packages")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.payroll_period_not_locked'));

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/accounting-mappings', [
                'scope_type' => 'project',
                'scope_id' => $project->id,
                'accounting_account' => '25.01',
            ])
            ->assertCreated();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/lock")
            ->assertOk();

        $package = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/export-packages");
        $package->assertCreated()
            ->assertJsonPath('data.status', 'created')
            ->assertJsonCount(3, 'data.files');

        $packageId = (int) $package->json('data.id');
        $jsonFile = collect($package->json('data.files'))->firstWhere('file_type', 'source_json');
        Storage::disk('s3')->assertExists($jsonFile['storage_path']);
        $this->assertStringContainsString('25.01', Storage::disk('s3')->get($jsonFile['storage_path']));

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/export-packages/{$packageId}/mark-rejected", ['reason' => 'Повторная отправка'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $replacement = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/export-packages");
        $replacement->assertCreated()
            ->assertJsonPath('data.supersedes_package_id', $packageId);
        $replacementId = (int) $replacement->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/export-packages/{$replacementId}/mark-sent")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/export-packages/{$replacementId}/mark-accepted")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/export-packages")
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('workforce.errors.export_package_accepted'));
    }

    public function test_payroll_statement_is_created_from_validated_source_rows(): void
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $employee = $this->employee($context, 'EMP-STMT-100');
        $this->allowAccess('web_admin');

        $periodId = $this->preparedValidatedPeriod($context, $project->id, $employee->id);

        $statement = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/statements");

        $statement->assertCreated()
            ->assertJsonPath('data.status', 'prepared')
            ->assertJsonPath('data.rows.0.employee_id', $employee->id)
            ->assertJsonPath('data.rows.0.hours', '8.00')
            ->assertJsonPath('data.rows.0.gross_amount', '4000.00');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/statements")
            ->assertOk()
            ->assertJsonPath('data.0.rows.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.rows.0.gross_amount', '4000.00');
    }

    private function preparedValidatedPeriod(AdminApiTestContext $context, int $projectId, int $employeeId): int
    {
        [$departmentId, $positionId, $staffUnitId, $scheduleId] = $this->createStructure($context);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/employee-assignments', [
                'employee_id' => $employeeId,
                'staff_unit_id' => $staffUnitId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'project_id' => $projectId,
                'work_schedule_id' => $scheduleId,
                'valid_from' => '2026-05-01',
            ])
            ->assertCreated();

        $this->createAcceptedWorkOrderWithTimesheet($context, $projectId, $employeeId);

        $period = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/payroll-periods', [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'project_id' => $projectId,
            ]);
        $period->assertCreated();
        $periodId = (int) $period->json('data.id');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/build-source")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/workforce/payroll-periods/{$periodId}/validate")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        return $periodId;
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
        $department = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/departments', [
                'code' => 'DEP-' . uniqid(),
                'name' => 'Строительный участок',
            ]);
        $department->assertCreated();

        $position = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/positions', [
                'code' => 'POS-' . uniqid(),
                'name' => 'Монтажник',
            ]);
        $position->assertCreated();

        $staffUnit = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/staff-units', [
                'department_id' => $department->json('data.id'),
                'position_id' => $position->json('data.id'),
                'code' => 'UNIT-' . uniqid(),
                'valid_from' => '2026-05-01',
            ]);
        $staffUnit->assertCreated();

        $schedule = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/workforce/work-schedules', [
                'code' => 'SCH-' . uniqid(),
                'name' => '5/2',
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

    private function createAcceptedWorkOrderWithTimesheet(AdminApiTestContext $context, int $projectId, int $employeeId): void
    {
        $workOrder = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/production-labor/work-orders', [
                'project_id' => $projectId,
                'order_number' => 'CORP-' . uniqid(),
                'title' => 'Монтаж',
                'assignee_type' => 'brigade',
                'assignee_name' => 'Бригада',
                'lines' => [[
                    'name' => 'Монтаж',
                    'unit' => 'м3',
                    'planned_quantity' => 10,
                    'unit_rate' => 1000,
                    'planned_hours' => 8,
                    'hour_rate' => 500,
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
                    'employee_id' => $employeeId,
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
