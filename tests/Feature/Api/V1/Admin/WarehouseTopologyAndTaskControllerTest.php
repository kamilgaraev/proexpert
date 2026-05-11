<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseLogisticUnit;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseStorageCell;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseTopologyAndTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_warehouse_topology_and_tasks_without_foreign_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOREIGN');
        $foreignZone = $this->createZone($foreignWarehouse->id, 'Foreign zone', 'F-ZONE');
        $foreignCell = $this->createCell($foreignContext->organization->id, $foreignWarehouse->id, $foreignZone->id, 'Foreign cell', 'F-CELL');
        $foreignUnit = $this->createLogisticUnit(
            $foreignContext->organization->id,
            $foreignWarehouse->id,
            $foreignZone->id,
            $foreignCell->id,
            'Foreign pallet',
            'F-PALLET'
        );
        $this->allowAdminAccess();

        $zoneResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/zones", [
                'name' => 'Receiving zone',
                'code' => 'Z-REC',
                'zone_type' => WarehouseZone::TYPE_RECEIVING,
                'rack_number' => 'A',
                'shelf_number' => '01',
                'cell_number' => '001',
                'capacity' => 100,
                'is_active' => true,
            ]);

        $zoneResponse->assertCreated();
        $zoneResponse->assertJsonPath('data.code', 'Z-REC');
        $zoneResponse->assertJsonPath('data.current_utilization', 0);
        $zone = WarehouseZone::query()->where('warehouse_id', $warehouse->id)->where('code', 'Z-REC')->firstOrFail();

        $cellResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/cells", [
                'zone_id' => $zone->id,
                'name' => 'Receiving cell A1',
                'code' => 'CELL-A1',
                'cell_type' => WarehouseStorageCell::TYPE_RECEIVING,
                'status' => WarehouseStorageCell::STATUS_AVAILABLE,
                'rack_number' => 'A',
                'shelf_number' => '01',
                'bin_number' => '001',
                'capacity' => 50,
                'is_active' => true,
            ]);

        $cellResponse->assertCreated();
        $cellResponse->assertJsonPath('data.zone.id', $zone->id);
        $cell = WarehouseStorageCell::query()->where('warehouse_id', $warehouse->id)->where('code', 'CELL-A1')->firstOrFail();

        $unitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/logistic-units", [
                'zone_id' => $zone->id,
                'cell_id' => $cell->id,
                'name' => 'Pallet A1',
                'code' => 'PALLET-A1',
                'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
                'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
                'capacity' => 20,
                'current_load' => 5,
            ]);

        $unitResponse->assertCreated();
        $unitResponse->assertJsonPath('data.storage_address', 'Z-REC-RA-S01-B001');
        $unitResponse->assertJsonPath('data.current_utilization', 25);
        $unit = WarehouseLogisticUnit::query()->where('warehouse_id', $warehouse->id)->where('code', 'PALLET-A1')->firstOrFail();

        $taskResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks", [
                'zone_id' => $zone->id,
                'cell_id' => $cell->id,
                'logistic_unit_id' => $unit->id,
                'assigned_to_id' => $context->user->id,
                'title' => 'Place pallet A1',
                'task_type' => WarehouseTask::TYPE_PLACEMENT,
                'status' => WarehouseTask::STATUS_QUEUED,
                'priority' => WarehouseTask::PRIORITY_HIGH,
                'planned_quantity' => 10,
            ]);

        $taskResponse->assertCreated();
        $taskResponse->assertJsonPath('data.status', WarehouseTask::STATUS_QUEUED);
        $taskResponse->assertJsonPath('data.zone.id', $zone->id);
        $taskResponse->assertJsonPath('data.cell.id', $cell->id);
        $taskResponse->assertJsonPath('data.logistic_unit.id', $unit->id);
        $task = WarehouseTask::query()->where('warehouse_id', $warehouse->id)->where('title', 'Place pallet A1')->firstOrFail();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/zones")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $zone->id);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks?status=queued")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $task->id);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$foreignWarehouse->id}/zones")
            ->assertNotFound();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/cells", [
                'zone_id' => $foreignZone->id,
                'name' => 'Bad cell',
                'code' => 'BAD-CELL',
                'cell_type' => WarehouseStorageCell::TYPE_STORAGE,
                'status' => WarehouseStorageCell::STATUS_AVAILABLE,
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/logistic-units", [
                'zone_id' => $zone->id,
                'cell_id' => $foreignCell->id,
                'name' => 'Bad unit',
                'code' => 'BAD-UNIT',
                'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
                'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
            ])
            ->assertStatus(422);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks", [
                'zone_id' => $zone->id,
                'cell_id' => $cell->id,
                'logistic_unit_id' => $foreignUnit->id,
                'title' => 'Bad task',
                'task_type' => WarehouseTask::TYPE_PLACEMENT,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('warehouse_storage_cells', [
            'organization_id' => $context->organization->id,
            'code' => 'BAD-CELL',
        ]);
        $this->assertDatabaseMissing('warehouse_logistic_units', [
            'organization_id' => $context->organization->id,
            'code' => 'BAD-UNIT',
        ]);
        $this->assertDatabaseMissing('warehouse_tasks', [
            'organization_id' => $context->organization->id,
            'title' => 'Bad task',
        ]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_IN_PROGRESS,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarehouseTask::STATUS_IN_PROGRESS);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}/status", [
                'status' => WarehouseTask::STATUS_COMPLETED,
                'completed_quantity' => 8,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WarehouseTask::STATUS_COMPLETED)
            ->assertJsonPath('data.completed_quantity', 8)
            ->assertJsonPath('data.progress_percent', 80)
            ->assertJsonPath('data.completed_by.id', $context->user->id);

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/warehouses/{$warehouse->id}/tasks/{$task->id}")
            ->assertStatus(422);
    }

    public function test_custom_admin_without_advanced_zone_permission_cannot_manage_warehouse_topology(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'custom_warehouse_viewer');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->denyAdvancedZonePermission();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/zones", [
                'name' => 'Restricted zone',
                'code' => 'REST',
                'zone_type' => WarehouseZone::TYPE_STORAGE,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('warehouse_zones', [
            'warehouse_id' => $warehouse->id,
            'code' => 'REST',
        ]);
    }

    public function test_web_admin_system_role_has_warehouse_topology_permissions_from_role_definition(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->activateBasicWarehouseModule($context->organization->id);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/warehouses/{$warehouse->id}/zones", [
                'name' => 'System role zone',
                'code' => 'SYS-ZONE',
                'zone_type' => WarehouseZone::TYPE_STORAGE,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('warehouse_zones', [
            'warehouse_id' => $warehouse->id,
            'code' => 'SYS-ZONE',
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

    private function createZone(int $warehouseId, string $name, string $code): WarehouseZone
    {
        return WarehouseZone::query()->create([
            'warehouse_id' => $warehouseId,
            'name' => $name,
            'code' => $code,
            'zone_type' => WarehouseZone::TYPE_STORAGE,
            'is_active' => true,
        ]);
    }

    private function createCell(
        int $organizationId,
        int $warehouseId,
        int $zoneId,
        string $name,
        string $code
    ): WarehouseStorageCell {
        return WarehouseStorageCell::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'zone_id' => $zoneId,
            'name' => $name,
            'code' => $code,
            'cell_type' => WarehouseStorageCell::TYPE_STORAGE,
            'status' => WarehouseStorageCell::STATUS_AVAILABLE,
            'is_active' => true,
        ]);
    }

    private function createLogisticUnit(
        int $organizationId,
        int $warehouseId,
        int $zoneId,
        int $cellId,
        string $name,
        string $code
    ): WarehouseLogisticUnit {
        return WarehouseLogisticUnit::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'zone_id' => $zoneId,
            'cell_id' => $cellId,
            'name' => $name,
            'code' => $code,
            'unit_type' => WarehouseLogisticUnit::TYPE_PALLET,
            'status' => WarehouseLogisticUnit::STATUS_AVAILABLE,
            'is_active' => true,
        ]);
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

    private function denyAdvancedZonePermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')
                ->andReturnUsing(
                    static fn (User $user, string $permission): bool => $permission !== 'warehouse.advanced.zones'
                );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['custom_warehouse_viewer']);
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

    private function activateBasicWarehouseModule(int $organizationId): void
    {
        $module = Module::query()->create([
            'name' => 'Basic warehouse',
            'slug' => 'basic-warehouse',
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'free',
            'category' => 'operations',
            'is_active' => true,
            'is_system_module' => false,
            'can_deactivate' => true,
        ]);

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
            'is_bundled_with_plan' => false,
            'is_auto_renew_enabled' => false,
        ]);
    }
}
