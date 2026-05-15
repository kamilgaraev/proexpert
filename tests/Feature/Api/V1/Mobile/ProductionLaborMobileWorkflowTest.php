<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProductionLaborMobileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreman_records_labor_output_and_timesheet_without_cross_project_leaks(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAccess();

        $workOrder = ProductionLaborWorkOrder::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'order_number' => 'MOB-PL-1',
            'title' => 'Mobile crew order',
            'assignee_type' => 'brigade',
            'assignee_name' => 'Crew M',
            'status' => 'in_progress',
            'issued_at' => now(),
        ]);
        $line = $workOrder->lines()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Masonry',
            'unit' => 'm2',
            'planned_quantity' => 20,
            'unit_rate' => 300,
            'planned_hours' => 16,
            'requires_safety_permit' => false,
        ]);

        $list = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/production-labor/work-orders?project_id={$project->id}");
        $list->assertOk()
            ->assertJsonPath('data.data.0.id', $workOrder->id)
            ->assertJsonPath('data.data.0.workflow_summary.status', 'in_progress');

        $output = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/production-labor/output-entries', [
                'work_order_line_id' => $line->id,
                'work_date' => now()->toDateString(),
                'quantity' => 5,
                'hours' => 4,
            ]);
        $output->assertCreated()
            ->assertJsonPath('data.quantity', 5);

        $timesheet = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/production-labor/timesheets', [
                'work_order_id' => $workOrder->id,
                'shift_date' => now()->toDateString(),
                'entries' => [[
                    'work_order_line_id' => $line->id,
                    'worker_name' => 'Worker M',
                    'hours' => 4,
                ]],
            ]);
        $timesheet->assertCreated()
            ->assertJsonPath('data.entries.0.worker_name', 'Worker M');

        $foreignWorkOrder = ProductionLaborWorkOrder::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'order_number' => 'FOREIGN-PL',
            'title' => 'Foreign order',
            'assignee_type' => 'brigade',
            'status' => 'in_progress',
        ]);
        $foreignLine = $foreignWorkOrder->lines()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign line',
            'planned_quantity' => 2,
        ]);

        $foreignOutput = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/production-labor/output-entries', [
                'work_order_line_id' => $foreignLine->id,
                'work_date' => now()->toDateString(),
                'quantity' => 1,
            ]);
        $foreignOutput->assertStatus(422);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'production-labor',
                    'project-management',
                ], true)
            );
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
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
