<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseInventoryAndProjectAllocationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_allocation_respects_stock_availability_and_can_be_partially_deallocated(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-ALLOC');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-ALLOC');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Pilot allocation project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 250);
        $this->allowAdminAccess();

        $firstAllocationResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $project->id,
                'quantity' => 6,
                'notes' => 'Hold for first stage',
            ]);

        $firstAllocationResponse->assertCreated();
        $firstAllocationResponse->assertJsonPath('success', true);

        $allocation = WarehouseProjectAllocation::query()
            ->where('organization_id', $context->organization->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $this->assertSame(6.0, (float) $allocation->allocated_quantity);

        $tooMuchResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $project->id,
                'quantity' => 5,
            ]);

        $tooMuchResponse->assertStatus(422);
        $this->assertSame(6.0, (float) $allocation->fresh()->allocated_quantity);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/project-allocations/project/{$project->id}");

        $listResponse->assertOk();
        $listResponse->assertJsonPath('success', true);
        $this->assertSame([$allocation->id], collect($listResponse->json('data'))->pluck('id')->all());

        $partialDeallocateResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}", [
                'quantity' => 2,
            ]);

        $partialDeallocateResponse->assertOk();
        $this->assertSame(4.0, (float) $allocation->fresh()->allocated_quantity);

        $fullDeallocateResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/project-allocations/{$allocation->id}");

        $fullDeallocateResponse->assertOk();
        $this->assertDatabaseMissing('warehouse_project_allocations', [
            'id' => $allocation->id,
        ]);
    }

    public function test_project_allocation_rejects_foreign_project_and_foreign_material_before_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-SCOPE');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-SCOPE');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'CEM-F-SCOPE');
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign allocation project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 250);
        $this->allowAdminAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $foreignProject->id,
                'quantity' => 1,
            ]);

        $foreignProjectResponse->assertStatus(422);

        $foreignMaterialResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $foreignMaterial->id,
                'project_id' => $foreignProject->id,
                'quantity' => 1,
            ]);

        $foreignMaterialResponse->assertStatus(422);

        $this->assertDatabaseMissing('warehouse_project_allocations', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_inventory_lifecycle_builds_items_from_current_stock_and_approval_updates_balances(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Inventory warehouse', 'INV-WH');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Paint', 'PNT-INV');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 4, 100, 'A1', 'B-1');
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 6, 100, 'A2', 'B-1');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-05-12',
                'commission_members' => [$context->user->id],
                'notes' => 'Monthly control',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.status', InventoryAct::STATUS_DRAFT);
        $createResponse->assertJsonCount(1, 'data.items');
        $createResponse->assertJsonPath('data.items.0.expected_quantity', 10);
        $createResponse->assertJsonPath('data.items.0.location_code', null);
        $createResponse->assertJsonPath('data.items.0.batch_number', 'B-1');

        $actId = (int) $createResponse->json('data.id');
        $itemId = (int) $createResponse->json('data.items.0.id');

        $completeBeforeCountingResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete");

        $completeBeforeCountingResponse->assertStatus(400);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAct::STATUS_IN_PROGRESS);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/warehouses/inventory/{$actId}/items/{$itemId}", [
                'actual_quantity' => 8,
                'notes' => 'Shortage found',
            ])
            ->assertOk()
            ->assertJsonPath('data.difference_quantity', -2)
            ->assertJsonPath('data.difference_value', -200);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAct::STATUS_COMPLETED)
            ->assertJsonPath('data.summary.total_items', 1)
            ->assertJsonPath('data.summary.items_with_discrepancy', 1);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/inventory/{$actId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAct::STATUS_APPROVED);

        $act = InventoryAct::query()->findOrFail($actId);
        $this->assertSame($context->user->id, $act->approved_by);
        $this->assertSame(8.0, $this->availableQuantity($context->organization->id, $warehouse->id, $material->id));
    }

    public function test_inventory_rejects_foreign_warehouse_before_creating_act(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'INV-FOR');
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $foreignWarehouse->id,
                'inventory_date' => '2026-05-12',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('inventory_acts', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_advanced_reservation_rejects_foreign_warehouse_material_and_project_before_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign reservation warehouse', 'RES-FOR');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign paint', 'PNT-FOR');
        $foreignProject = Project::factory()->create([
            'organization_id' => $foreignContext->organization->id,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advanced-warehouse/reservations', [
                'warehouse_id' => $foreignWarehouse->id,
                'material_id' => $foreignMaterial->id,
                'project_id' => $foreignProject->id,
                'quantity' => 1,
                'reason' => 'Foreign reservation attempt',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_id', 'material_id', 'project_id']);
        $this->assertDatabaseMissing('asset_reservations', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_auto_reorder_rule_rejects_foreign_warehouse_material_and_supplier_before_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign reorder warehouse', 'REORDER-FOR');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign adhesive', 'ADH-FOR');
        $foreignSupplier = Supplier::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign supplier',
            'is_active' => true,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/advanced-warehouse/auto-reorder/rules', [
                'warehouse_id' => $foreignWarehouse->id,
                'material_id' => $foreignMaterial->id,
                'min_stock_level' => 1,
                'reorder_point' => 2,
                'reorder_quantity' => 3,
                'supplier_id' => $foreignSupplier->id,
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['warehouse_id', 'material_id', 'default_supplier_id']);
        $this->assertDatabaseMissing('auto_reorder_rules', [
            'organization_id' => $context->organization->id,
        ]);
    }

    public function test_advanced_analytics_rejects_foreign_warehouse_and_asset_filters(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign analytics warehouse', 'AN-FOR');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign analytics material', 'AN-MAT-FOR');
        $this->allowAdminAccess();

        $turnoverResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/turnover?' . http_build_query([
                'warehouse_id' => $foreignWarehouse->id,
            ]));

        $turnoverResponse->assertStatus(422);
        $turnoverResponse->assertJsonValidationErrors('warehouse_id');

        $forecastResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/analytics/forecast?' . http_build_query([
                'asset_ids' => [$foreignMaterial->id],
            ]));

        $forecastResponse->assertStatus(422);
        $forecastResponse->assertJsonValidationErrors('asset_ids.0');
    }

    public function test_admin_viewer_cannot_manage_inventory_or_project_allocations_without_warehouse_permissions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $unit = $this->createUnit($context->organization->id);
        $warehouse = $this->createWarehouse($context->organization->id, 'Viewer warehouse', 'VIEW-WH');
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Sand', 'SND-VIEW');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Viewer project',
        ]);
        $this->createBalance($context->organization->id, $warehouse->id, $material->id, 10, 50);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/inventory', [
                'warehouse_id' => $warehouse->id,
                'inventory_date' => '2026-05-12',
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/project-allocations', [
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'project_id' => $project->id,
                'quantity' => 1,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('inventory_acts', [
            'organization_id' => $context->organization->id,
        ]);
        $this->assertDatabaseMissing('warehouse_project_allocations', [
            'organization_id' => $context->organization->id,
        ]);
    }

    private function createUnit(int $organizationId): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Piece',
            'short_name' => 'pcs',
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function createWarehouse(int $organizationId, string $name, string $code): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);
    }

    private function createMaterial(int $organizationId, int $measurementUnitId, string $name, string $code): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'measurement_unit_id' => $measurementUnitId,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
    }

    private function createBalance(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        float $price,
        ?string $locationCode = null,
        ?string $batchNumber = null
    ): WarehouseBalance {
        return WarehouseBalance::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => $quantity,
            'reserved_quantity' => 0,
            'unit_price' => $price,
            'min_stock_level' => 0,
            'max_stock_level' => 0,
            'location_code' => $locationCode,
            'batch_number' => $batchNumber,
            'last_movement_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function availableQuantity(int $organizationId, int $warehouseId, int $materialId): float
    {
        return (float) WarehouseBalance::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->sum('available_quantity');
    }

    private function allowAdminAccess(): void
    {
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
