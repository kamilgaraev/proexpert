<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Auth\JwtTokenIssuer;
use DomainException;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\MachineryOperationsAssetFactory;
use Tests\TestCase;

final class MachineryOperationsWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These workflow tests exercise the legacy JWT route stack, not the
        // independently covered browser-session token handshake.
        $this->withoutMiddleware(WebInterfaceSecurityMiddleware::class);
    }

    public function test_admin_rejects_same_organization_project_leak_in_shift_report(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'current_project_id' => $projectA->id,
            'asset_code' => 'ADMIN-INTEGRITY-1',
            'name' => 'Admin integrity asset',
            'status' => 'in_operation',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        $assignment = MachineryAssignment::query()->create([
            'organization_id' => $context->organization->id,
            'asset_id' => $asset->id,
            'project_id' => $projectA->id,
            'requested_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();

        $response = $this->withHeaders([...$this->adminAuthHeaders($context), 'Idempotency-Key' => 'admin-project-mismatch'])
            ->postJson('/api/v1/admin/machinery-operations/shift-reports', [
                'asset_id' => $asset->id,
                'project_id' => $projectB->id,
                'assignment_id' => $assignment->id,
                'report_date' => now()->toDateString(),
                'actual_hours' => 8,
                'fuel_consumed' => 10,
                'pre_shift_inspection' => [
                    'result' => 'serviceable',
                    'defects' => [],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('machinery_shift_reports', [
            'asset_id' => $asset->id,
            'project_id' => $projectB->id,
        ]);
    }

    public function test_admin_rejects_overlapping_active_assignment(): void
    {
        $context = AdminApiTestContext::create();
        $projectA = Project::factory()->create(['organization_id' => $context->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryAsset::query()->create([
            'organization_id' => $context->organization->id,
            'asset_code' => 'ADMIN-INTEGRITY-2',
            'name' => 'Admin assignment integrity asset',
            'status' => 'available',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 1000,
        ]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();

        $first = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$asset->id}/assign", [
                'project_id' => $projectA->id,
                'planned_start_at' => now()->addDay()->toIso8601String(),
                'planned_end_at' => now()->addDays(3)->toIso8601String(),
            ]);
        $first->assertOk();

        $overlapping = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$asset->id}/assign", [
                'project_id' => $projectB->id,
                'planned_start_at' => now()->addDays(2)->toIso8601String(),
                'planned_end_at' => now()->addDays(4)->toIso8601String(),
            ]);

        $overlapping->assertStatus(422);
        $this->assertDatabaseCount('machinery_assignments', 1);
    }

    public function test_admin_cannot_mark_asset_unavailable_while_shift_is_open(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'ADMIN-OPEN-SHIFT-1',
            'name' => 'Open shift excavator',
            'current_project_id' => $project->id,
            'status' => 'in_operation',
        ]);
        MachineryAssignment::query()->create([
            'organization_id' => $context->organization->id,
            'organization_asset_id' => $asset->organization_asset_id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'requested_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'status' => 'active',
            'planned_start_at' => now()->subHour(),
            'actual_start_at' => now()->subHour(),
        ]);
        MachineryShiftReport::query()->create([
            'organization_id' => $context->organization->id,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'report_date' => now()->toDateString(),
            'status' => 'draft',
            'actual_hours' => 0,
            'fuel_consumed' => 0,
            'reported_by_user_id' => $context->user->id,
        ]);

        try {
            app(MachineryOperationsService::class)->setUnavailable($asset);
            self::fail('Open shift must block unavailable transition.');
        } catch (DomainException $exception) {
            self::assertSame(
                trans_message('machinery_operations.errors.asset_has_open_shift'),
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseHas('machinery_assets', [
            'id' => $asset->id,
            'status' => 'assigned',
        ]);
    }

    public function test_admin_manages_asset_shift_downtime_fuel_maintenance_and_reports_with_org_scope(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->actingAs($context->user, 'api_admin');
        $this->allowAccess();

        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'EXC-001',
            'name' => 'Excavator CAT 320',
            'ownership_type' => 'owned',
            'operating_cost_per_hour' => 4500,
            'fuel_type' => 'diesel',
            'fuel_consumption_rate' => 18.5,
        ]);
        $assetId = (int) $asset->id;
        $this->withHeaders($this->adminAuthHeaders($context))
            ->getJson('/api/v1/admin/machinery-operations/assets?search=EXC-001')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'available')
            ->assertJsonPath('data.0.available_actions.0', 'assign')
            ->assertJsonPath('data.0.workflow_summary.status', 'available');

        $assignResponse = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$assetId}/assign", [
                'project_id' => $project->id,
                'planned_start_at' => now()->toIso8601String(),
                'planned_hours' => 8,
                'comment' => 'Earthworks shift',
            ]);

        $assignResponse->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.project_id', $project->id);

        $startedAsset = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$assetId}/start-operation");
        $startedAsset->assertOk()
            ->assertJsonPath('data.status', 'in_operation')
            ->assertJsonPath('data.available_actions.0', 'return_available');

        $shiftResponse = $this->withHeaders([...$this->adminAuthHeaders($context), 'Idempotency-Key' => 'admin-shift-start'])
            ->postJson('/api/v1/admin/machinery-operations/shift-reports', [
                'asset_id' => $assetId,
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'planned_hours' => 8,
                'actual_hours' => 6.5,
                'fuel_consumed' => 120,
                'meter_start' => 100,
                'pre_shift_inspection' => [
                    'result' => 'serviceable',
                    'meter_value' => 100,
                    'defects' => [],
                ],
            ]);

        $shiftResponse->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $shiftId = (int) $shiftResponse->json('data.id');

        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Admin fuel warehouse',
            'code' => 'ADMIN-FUEL',
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Admin diesel fuel',
            'code' => 'ADMIN-DIESEL',
            'is_active' => true,
        ]);
        app(WarehouseService::class)->receiveAsset(
            (int) $context->organization->id,
            (int) $warehouse->id,
            (int) $material->id,
            200,
            65,
        );
        $fuelResponse = $this->withHeaders([...$this->adminAuthHeaders($context), 'Idempotency-Key' => 'admin-fuel-once'])
            ->postJson('/api/v1/admin/machinery-operations/fuel-issues', [
                'asset_id' => $assetId,
                'project_id' => $project->id,
                'shift_report_id' => $shiftId,
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'issued_at' => now()->toIso8601String(),
                'fuel_type' => 'diesel',
                'quantity' => 120,
                'unit' => 'l',
            ]);
        $fuelResponse->assertCreated();
        self::assertSame(120.0, (float) $fuelResponse->json('data.quantity'));

        $this->withHeaders([...$this->adminAuthHeaders($context), 'Idempotency-Key' => 'admin-shift-finish'])
            ->postJson("/api/v1/admin/machinery-operations/shift-reports/{$shiftId}/finish", [
                'actual_hours' => 6.5,
                'fuel_consumed' => 120,
                'meter_end' => 106.5,
                'work_description' => 'Excavation completed',
                'post_shift_inspection' => [
                    'result' => 'serviceable',
                    'meter_value' => 106.5,
                    'defects' => [],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $submittedShift = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/shift-reports/{$shiftId}/submit");
        $submittedShift->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.available_actions.0', 'approve');

        $approvedShift = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/shift-reports/{$shiftId}/approve");
        $approvedShift->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by_user_id', $context->user->id)
            ->assertJsonPath('data.hourly_rate_snapshot', '4500.00');

        $canonicalProfile = MachineryAsset::query()->findOrFail($assetId)
            ->organizationAsset()->firstOrFail()
            ->operationProfile()->firstOrFail();
        self::assertSame('106.50', $canonicalProfile->meter_value);

        $downtimeResponse = $this->withHeaders($this->adminAuthHeaders($context))
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

        $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$assetId}/return-available")
            ->assertOk();

        $maintenance = $this->withHeaders($this->adminAuthHeaders($context))
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

        $completedMaintenance = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/maintenance-orders/{$maintenanceId}/complete", [
                'completion_comment' => 'Inspection completed',
            ]);
        $completedMaintenance->assertOk()
            ->assertJsonPath('data.status', 'completed');

        MachineryAsset::query()->whereKey($assetId)->update(['operating_cost_per_hour' => 9999]);

        $reports = $this->withHeaders($this->adminAuthHeaders($context))
            ->getJson("/api/v1/admin/machinery-operations/reports?project_id={$project->id}");
        $reports->assertOk()
            ->assertJsonPath('data.downtime_by_reason.0.reason', 'waiting_material')
            ->assertJsonPath('data.fuel_consumption.0.fuel_type', 'diesel')
            ->assertJsonPath('data.operating_cost_by_project.0.cost', static fn (mixed $cost): bool => (float) $cost === 29250.0);

        $reserveAsset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'CRN-001',
            'name' => 'Tower crane',
            'ownership_type' => 'owned',
        ]);
        $reserveAssetId = (int) $reserveAsset->id;

        $unavailableAsset = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$reserveAssetId}/unavailable");
        $unavailableAsset->assertOk()
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.problem_flags.0.code', 'asset_unavailable');

        $availableAsset = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$reserveAssetId}/return-available");
        $availableAsset->assertOk()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.available_actions.0', 'assign');

        $archivedAsset = $this->withHeaders($this->adminAuthHeaders($context))
            ->postJson("/api/v1/admin/machinery-operations/assets/{$reserveAssetId}/archive");
        $archivedAsset->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.available_actions', []);

        $foreignAssign = $this->withHeaders($this->adminAuthHeaders($context))
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

    /** @return array<string, string> */
    private function adminAuthHeaders(AdminApiTestContext $context): array
    {
        $token = app(JwtTokenIssuer::class)->issue($context->user, [
            'guard' => 'api_admin',
            'organization_id' => $context->organization->id,
        ]);

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Origin' => 'https://admin.1мост.рф',
        ];
    }
}
