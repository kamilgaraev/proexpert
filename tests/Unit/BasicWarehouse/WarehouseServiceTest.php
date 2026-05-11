<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Contracts\WarehouseReportDataProvider;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Models\Material;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class WarehouseServiceTest extends TestCase
{
    protected WarehouseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WarehouseService::class);
    }

    public function test_service_implements_warehouse_report_data_provider(): void
    {
        $this->assertInstanceOf(WarehouseReportDataProvider::class, $this->service);
    }

    public function test_get_stock_data_returns_array(): void
    {
        $data = $this->service->getStockData(1, []);

        $this->assertIsArray($data);
    }

    public function test_get_movements_data_returns_warehouse_movements(): void
    {
        [$organization, $warehouse, $material, $user] = $this->createWarehouseContext();

        $movement = WarehouseMovement::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 12.5,
            'price' => 150,
            'user_id' => $user->id,
            'document_number' => 'RCPT-1',
            'reason' => 'Initial receipt',
            'movement_date' => '2026-05-01 10:00:00',
        ]);

        $data = $this->service->getMovementsData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
        ]);

        $this->assertCount(1, $data);
        $this->assertSame($movement->id, $data[0]['movement_id']);
        $this->assertSame(WarehouseMovement::TYPE_RECEIPT, $data[0]['movement_type']);
        $this->assertSame($warehouse->id, $data[0]['warehouse_id']);
        $this->assertSame($warehouse->name, $data[0]['warehouse_name']);
        $this->assertSame($material->id, $data[0]['material_id']);
        $this->assertSame($material->name, $data[0]['material_name']);
        $this->assertSame(12.5, $data[0]['quantity']);
        $this->assertSame(150.0, $data[0]['price']);
        $this->assertSame(1875.0, $data[0]['total_value']);
        $this->assertSame($user->name, $data[0]['user_name']);
        $this->assertSame('RCPT-1', $data[0]['document_number']);
    }

    public function test_get_inventory_data_returns_inventory_acts(): void
    {
        [$organization, $warehouse, $material, $user] = $this->createWarehouseContext();

        $act = InventoryAct::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'INV-1',
            'status' => 'completed',
            'inventory_date' => '2026-05-02',
            'created_by' => $user->id,
        ]);

        InventoryActItem::create([
            'inventory_act_id' => $act->id,
            'material_id' => $material->id,
            'expected_quantity' => 10,
            'actual_quantity' => 8,
            'difference' => -2,
            'unit_price' => 100,
            'total_value' => -200,
        ]);

        $data = $this->service->getInventoryData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'status' => 'completed',
        ]);

        $this->assertCount(1, $data);
        $this->assertSame($act->id, $data[0]['act_id']);
        $this->assertSame('INV-1', $data[0]['act_number']);
        $this->assertSame($warehouse->id, $data[0]['warehouse_id']);
        $this->assertSame('completed', $data[0]['status']);
        $this->assertSame(1, $data[0]['items_count']);
        $this->assertSame(1, $data[0]['discrepancies_count']);
        $this->assertSame(-200.0, (float) $data[0]['total_difference_value']);
        $this->assertSame($material->id, $data[0]['items'][0]['material_id']);
    }

    public function test_get_turnover_analytics_returns_material_metrics(): void
    {
        [$organization, $warehouse, $material, $user] = $this->createWarehouseContext();
        $this->createBalance($organization->id, $warehouse->id, $material->id, 20);
        $this->createWriteOff($organization->id, $warehouse->id, $material->id, $user->id, 10);

        $data = $this->service->getTurnoverAnalytics($organization->id, [
            'date_from' => now()->subDays(10),
            'date_to' => now(),
        ]);

        $this->assertSame(1, $data['summary']['total_assets_analyzed']);
        $this->assertSame($material->id, $data['assets'][0]['asset_id']);
        $this->assertSame(20.0, $data['assets'][0]['average_stock']);
        $this->assertSame(10.0, $data['assets'][0]['consumption']);
        $this->assertSame(0.5, $data['assets'][0]['turnover_rate']);
    }

    public function test_get_forecast_data_returns_consumption_forecast(): void
    {
        [$organization, $warehouse, $material, $user] = $this->createWarehouseContext();
        $this->createBalance($organization->id, $warehouse->id, $material->id, 30);
        $this->createWriteOff($organization->id, $warehouse->id, $material->id, $user->id, 9);

        $data = $this->service->getForecastData($organization->id, [
            'horizon_days' => 30,
        ]);

        $this->assertSame(30, $data['forecast_period']['horizon_days']);
        $this->assertSame(1, $data['summary']['total_assets_forecasted']);
        $this->assertSame($material->id, $data['forecasts'][0]['asset_id']);
        $this->assertSame(0.1, $data['forecasts'][0]['average_daily_consumption']);
        $this->assertSame(3.0, $data['forecasts'][0]['predicted_consumption']);
    }

    public function test_get_abc_xyz_analysis_returns_consumption_categories(): void
    {
        [$organization, $warehouse, $material, $user] = $this->createWarehouseContext();
        $this->createWriteOff($organization->id, $warehouse->id, $material->id, $user->id, 5, 100);
        $this->createWriteOff($organization->id, $warehouse->id, $material->id, $user->id, 5, 100);

        $data = $this->service->getAbcXyzAnalysis($organization->id, [
            'date_from' => now()->subDays(10),
            'date_to' => now(),
        ]);

        $this->assertSame(1, $data['summary']['total_assets_analyzed']);
        $this->assertSame(1000.0, (float) $data['summary']['total_consumption_value']);
        $this->assertSame($material->id, $data['assets'][0]['asset_id']);
        $this->assertSame('C', $data['assets'][0]['abc_category']);
        $this->assertSame('X', $data['assets'][0]['xyz_category']);
    }

    private function createWarehouseContext(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $warehouse = OrganizationWarehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Main warehouse',
            'code' => 'MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::create([
            'organization_id' => $organization->id,
            'name' => 'Cement M500',
            'code' => 'CEM-500',
            'additional_properties' => ['asset_type' => 'material'],
            'is_active' => true,
        ]);

        return [$organization, $warehouse, $material, $user];
    }

    private function createBalance(int $organizationId, int $warehouseId, int $materialId, float $quantity): WarehouseBalance
    {
        return WarehouseBalance::create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => $quantity,
            'reserved_quantity' => 0,
            'unit_price' => 100,
        ]);
    }

    private function createWriteOff(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        int $userId,
        float $quantity,
        float $price = 100,
    ): WarehouseMovement {
        return WarehouseMovement::create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => $quantity,
            'price' => $price,
            'user_id' => $userId,
            'document_number' => 'WO-' . str_replace('.', '-', (string) microtime(true)),
            'reason' => 'Write off for analytics',
            'movement_date' => now()->subDay(),
        ]);
    }
}
