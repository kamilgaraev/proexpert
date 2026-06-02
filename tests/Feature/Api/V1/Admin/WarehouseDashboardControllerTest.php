<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
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

class WarehouseDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_operational_summary_without_foreign_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id);
        $foreignUnit = $this->createUnit($foreignContext->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id, 'Cement', 'CEM-DASH');
        $foreignMaterial = $this->createMaterial($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'F-CEM-DASH');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN-DASH', true);
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOREIGN-DASH');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 4,
            'reserved_quantity' => 1,
            'unit_price' => 25,
            'min_stock_level' => 5,
            'max_stock_level' => 20,
            'last_movement_at' => now(),
            'created_at' => now(),
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'warehouse_id' => $foreignWarehouse->id,
            'material_id' => $foreignMaterial->id,
            'available_quantity' => 1,
            'reserved_quantity' => 0,
            'unit_price' => 100,
            'min_stock_level' => 10,
            'max_stock_level' => 20,
            'last_movement_at' => now(),
            'created_at' => now(),
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 4,
            'price' => 25,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'movement_date' => now(),
        ]);
        WarehouseTask::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'assigned_to_id' => $context->user->id,
            'created_by_id' => $context->user->id,
            'task_number' => 'WT-1',
            'title' => 'Place cement',
            'task_type' => WarehouseTask::TYPE_PLACEMENT,
            'status' => WarehouseTask::STATUS_QUEUED,
            'priority' => WarehouseTask::PRIORITY_HIGH,
            'planned_quantity' => 4,
            'due_at' => now()->addDay(),
        ]);
        AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 1,
            'project_id' => $project->id,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => now(),
            'expires_at' => now()->addHours(2),
            'reason' => 'Project hold',
        ]);
        InventoryAct::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'INV-1',
            'status' => InventoryAct::STATUS_IN_PROGRESS,
            'inventory_date' => now()->toDateString(),
            'created_by' => $context->user->id,
            'commission_members' => [],
        ]);
        ProjectMaterialDelivery::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'material_id' => $material->id,
            'warehouse_id' => $warehouse->id,
            'source_type' => 'warehouse',
            'status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value,
            'requested_quantity' => 4,
            'reserved_quantity' => 4,
            'shipped_quantity' => 2,
            'accepted_quantity' => 0,
            'planned_delivery_date' => now()->addDay()->toDateString(),
        ]);
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Need cement',
            'status' => SiteRequestStatusEnum::PENDING->value,
            'priority' => SiteRequestPriorityEnum::URGENT->value,
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'required_date' => now()->subDay()->toDateString(),
            'material_name' => 'Cement M500',
            'material_quantity' => 12,
            'material_unit' => 'bag',
        ]);
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $context->organization->id,
            'site_request_id' => $siteRequest->id,
            'assigned_to' => $context->user->id,
            'request_number' => 'PR-DASH-1',
            'status' => PurchaseRequestStatusEnum::PENDING->value,
            'needed_by' => now()->addDay()->toDateString(),
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Dashboard supplier',
            'code' => 'DASH-SUP',
            'is_active' => true,
        ]);
        PurchaseOrder::query()->create([
            'organization_id' => $context->organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'order_number' => 'PO-DASH-1',
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED->value,
            'total_amount' => 30000,
            'currency' => 'RUB',
            'delivery_date' => now()->toDateString(),
        ]);

        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$warehouse->id}/dashboard");

        $response->assertOk();
        $response->assertJsonPath('data.stats.total_positions', 5);
        $response->assertJsonPath('data.stats.total_value', 100);
        $response->assertJsonPath('data.stats.unique_items', 1);
        $response->assertJsonPath('data.stats.low_stock_items', 1);
        $response->assertJsonPath('data.stats.active_tasks', 1);
        $response->assertJsonPath('data.stats.active_reservations', 1);
        $response->assertJsonPath('data.stats.active_inventories', 1);
        $response->assertJsonPath('data.materials.0.material_id', $material->id);
        $response->assertJsonPath('data.movements.0.material_id', $material->id);
        $response->assertJsonPath('data.requests.pending_material', 1);
        $response->assertJsonPath('data.requests.urgent_material', 1);
        $response->assertJsonPath('data.requests.overdue_material', 1);
        $response->assertJsonPath('data.requests.latest.0.id', $siteRequest->id);
        $response->assertJsonPath('data.procurement.pending_purchase_requests', 1);
        $response->assertJsonPath('data.procurement.purchase_orders_in_work', 1);
        $response->assertJsonPath('data.procurement.purchase_orders_due', 1);
        $response->assertJsonPath('data.deliveries.active', 1);
        $response->assertJsonPath('data.alerts.low_stock', 1);
        $response->assertJsonPath('data.alerts.expiring_reservations', 1);
        $response->assertJsonPath('data.alerts.overdue_material_requests', 1);
        $response->assertJsonPath('data.operational_queue.0.kind', 'overdue_material_requests');
        $response->assertJsonPath('data.operational_queue.0.severity', 'critical');
        $response->assertJsonPath('data.operational_queue.0.count', 1);
        $response->assertJsonPath('data.operational_queue.0.action_code', 'open_overdue_requests');
        $response->assertJsonPath('data.operational_queue.0.is_blocking', true);
        $response->assertJsonPath('data.operational_queue.1.kind', 'low_stock');
        $response->assertJsonPath('data.operational_queue.2.kind', 'purchase_orders_due');
        $response->assertJsonPath('data.operational_queue.3.kind', 'urgent_material_requests');
        $response->assertJsonPath('data.operational_queue.4.kind', 'expiring_reservations');
        $response->assertJsonPath('data.operational_queue.5.kind', 'missing_locations');
        $response->assertJsonPath('data.operational_queue.6.kind', 'active_inventory');
        $response->assertJsonPath('data.attention.critical', 1);
        $response->assertJsonPath('data.attention.warning', 5);
        $response->assertJsonPath('data.attention.info', 1);
        $response->assertJsonPath('data.attention.total', 7);
        $response->assertJsonPath('data.attention.has_critical', true);
        $response->assertJsonPath('data.workload.urgent_total', 1);
        $response->assertJsonPath('data.workload.active_total', 3);
        $response->assertJsonPath('data.workload.blocked_total', 0);
        $response->assertJsonPath('data.workload.today_total', 3);
        $response->assertJsonPath('data.storage_health.zones_count', 0);
        $response->assertJsonPath('data.storage_health.missing_location_count', 1);
        $response->assertJsonPath('data.storage_health.low_stock_count', 1);
        $response->assertJsonPath('data.storage_health.reserved_quantity', 1);
        $response->assertJsonPath('data.quick_filters.stock.low_stock', 1);
        $response->assertJsonPath('data.quick_filters.stock.missing_locations', 1);
        $response->assertJsonPath('data.quick_filters.stock.reserved_quantity', 1);
        $response->assertJsonPath('data.quick_filters.tasks.active', 1);
        $response->assertJsonPath('data.quick_filters.tasks.blocked', 0);
        $response->assertJsonPath('data.quick_filters.requests.pending', 1);
        $response->assertJsonPath('data.quick_filters.requests.urgent', 1);
        $response->assertJsonPath('data.quick_filters.requests.overdue', 1);
        $response->assertJsonPath('data.quick_filters.procurement.purchase_orders_due', 1);
        $response->assertJsonPath('data.quick_filters.deliveries.active', 1);
        $response->assertJsonPath('data.quick_filters.deliveries.problem', 0);
        $response->assertJsonMissing(['material_id' => $foreignMaterial->id]);
    }

    public function test_dashboard_rejects_foreign_warehouse_id(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->createWarehouse($foreignContext->organization->id, 'Foreign warehouse', 'FOREIGN-404');

        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$foreignWarehouse->id}/dashboard")
            ->assertNotFound();
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

    private function createWarehouse(
        int $organizationId,
        string $name,
        string $code,
        bool $isMain = false
    ): OrganizationWarehouse {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => $code,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => $isMain,
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
}
