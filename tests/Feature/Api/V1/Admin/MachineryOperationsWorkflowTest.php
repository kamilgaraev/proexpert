<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MachineryOperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_asset_shift_downtime_fuel_maintenance_and_reports_with_org_scope(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAccess();

        $assetResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/assets', [
                'asset_code' => 'EXC-001',
                'name' => 'Excavator CAT 320',
                'ownership_type' => 'owned',
                'operating_cost_per_hour' => 4500,
                'fuel_type' => 'diesel',
                'fuel_consumption_rate' => 18.5,
            ]);

        $assetResponse->assertCreated()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.available_actions.0', 'assign')
            ->assertJsonPath('data.workflow_summary.status', 'available');
        $assetId = (int) $assetResponse->json('data.id');

        $assignResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/assets/{$assetId}/assign", [
                'project_id' => $project->id,
                'planned_start_at' => now()->toIso8601String(),
                'planned_hours' => 8,
                'comment' => 'Earthworks shift',
            ]);

        $assignResponse->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.project_id', $project->id);

        $startedAsset = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/assets/{$assetId}/start-operation");
        $startedAsset->assertOk()
            ->assertJsonPath('data.status', 'in_operation')
            ->assertJsonPath('data.available_actions.0', 'return_available');

        $shiftResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/shift-reports', [
                'asset_id' => $assetId,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'planned_hours' => 8,
                'actual_hours' => 6.5,
                'fuel_consumed' => 120,
                'meter_start' => 100,
                'meter_end' => 106.5,
                'work_description' => 'Excavation completed',
            ]);

        $shiftResponse->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.available_actions.0', 'submit');
        $shiftId = (int) $shiftResponse->json('data.id');

        $submittedShift = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/shift-reports/{$shiftId}/submit");
        $submittedShift->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.available_actions.0', 'approve');

        $approvedShift = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/shift-reports/{$shiftId}/approve");
        $approvedShift->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by_user_id', $context->user->id);

        $downtimeResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/downtimes', [
                'asset_id' => $assetId,
                'project_id' => $project->id,
                'shift_report_id' => $shiftId,
                'reason' => 'waiting_material',
                'started_at' => now()->subHours(2)->toIso8601String(),
                'ended_at' => now()->subHour()->toIso8601String(),
                'duration_minutes' => 60,
                'comment' => 'No trucks for soil removal',
            ]);
        $downtimeResponse->assertCreated()
            ->assertJsonPath('data.reason', 'waiting_material');

        $fuelResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/fuel-issues', [
                'asset_id' => $assetId,
                'project_id' => $project->id,
                'issued_at' => now()->toIso8601String(),
                'fuel_type' => 'diesel',
                'quantity' => 140,
                'cost' => 9100,
        ]);
        $fuelResponse->assertCreated()
            ->assertJsonPath('data.quantity', 140);

        $maintenance = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/maintenance-orders', [
                'asset_id' => $assetId,
                'project_id' => $project->id,
                'title' => 'Hydraulic inspection',
                'maintenance_type' => 'service',
                'priority' => 'high',
            ]);
        $maintenance->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.available_actions.0', 'complete');
        $maintenanceId = (int) $maintenance->json('data.id');

        $completedMaintenance = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/maintenance-orders/{$maintenanceId}/complete", [
                'completion_comment' => 'Inspection completed',
            ]);
        $completedMaintenance->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $reports = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/machinery-operations/reports?project_id={$project->id}");
        $reports->assertOk()
            ->assertJsonPath('data.downtime_by_reason.0.reason', 'waiting_material')
            ->assertJsonPath('data.fuel_consumption.0.fuel_type', 'diesel');

        $reserveAsset = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery-operations/assets', [
                'asset_code' => 'CRN-001',
                'name' => 'Tower crane',
                'ownership_type' => 'owned',
            ]);
        $reserveAsset->assertCreated()
            ->assertJsonPath('data.status', 'available');
        $reserveAssetId = (int) $reserveAsset->json('data.id');

        $unavailableAsset = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/assets/{$reserveAssetId}/unavailable");
        $unavailableAsset->assertOk()
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.problem_flags.0.code', 'asset_unavailable');

        $availableAsset = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/assets/{$reserveAssetId}/return-available");
        $availableAsset->assertOk()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.available_actions.0', 'assign');

        $archivedAsset = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/assets/{$reserveAssetId}/archive");
        $archivedAsset->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.available_actions', []);

        $foreignAssign = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/machinery-operations/assets/{$assetId}/assign", [
                'project_id' => $foreignProject->id,
                'planned_start_at' => now()->toIso8601String(),
            ]);
        $foreignAssign->assertStatus(422);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'machinery-operations',
                    'project-management',
                ], true)
            );
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
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
