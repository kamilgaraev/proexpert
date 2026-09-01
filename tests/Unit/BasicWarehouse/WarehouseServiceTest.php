<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Contracts\WarehouseReportDataProvider;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    public function test_get_stock_data_keeps_same_material_separate_across_warehouses(): void
    {
        [$organization, $mainWarehouse, $material] = $this->createWarehouseContext();
        $secondaryWarehouse = OrganizationWarehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Secondary warehouse',
            'code' => 'SECONDARY',
            'warehouse_type' => OrganizationWarehouse::TYPE_EXTERNAL,
            'is_main' => false,
            'is_active' => true,
        ]);

        $this->createBalance($organization->id, $mainWarehouse->id, $material->id, 10);
        $this->createBalance($organization->id, $secondaryWarehouse->id, $material->id, 20);

        $stockByWarehouse = collect($this->service->getStockData($organization->id))
            ->keyBy('warehouse_id');

        $this->assertCount(2, $stockByWarehouse);
        $this->assertSame('Main warehouse', $stockByWarehouse[$mainWarehouse->id]['warehouse_name']);
        $this->assertSame(10.0, $stockByWarehouse[$mainWarehouse->id]['available_quantity']);
        $this->assertSame('Secondary warehouse', $stockByWarehouse[$secondaryWarehouse->id]['warehouse_name']);
        $this->assertSame(20.0, $stockByWarehouse[$secondaryWarehouse->id]['available_quantity']);
    }

    public function test_get_stock_data_replaces_custody_login_with_readable_person_name(): void
    {
        [$organization, , $material, $user] = $this->createWarehouseContext();
        $user->forceFill(['name' => 'technical_owner_login'])->save();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Жилой комплекс Северный',
        ]);
        $warehouse = OrganizationWarehouse::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $user->id,
            'name' => 'Ответственное хранение: Жилой комплекс Северный, technical_owner_login',
            'code' => 'CUSTODY-READABLE-STOCK',
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_main' => false,
            'is_active' => true,
        ]);
        WorkforceEmployee::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'personnel_number' => 'QA-STOCK-NAME-001',
            'last_name' => 'Гараев',
            'first_name' => 'Камиль',
            'middle_name' => 'Тестович',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
        ]);
        $this->createBalance($organization->id, $warehouse->id, $material->id, 10);

        $stock = $this->service->getStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
        ]);

        $this->assertCount(1, $stock);
        $this->assertSame(
            'Ответственное хранение: Жилой комплекс Северный, Гараев Камиль Тестович',
            $stock[0]['warehouse_name']
        );
        $this->assertStringNotContainsString('technical_owner_login', $stock[0]['warehouse_name']);
    }

    public function test_get_stock_data_project_filter_returns_only_allocated_warehouse_position(): void
    {
        [$organization, $mainWarehouse, $material, $user] = $this->createWarehouseContext();
        $secondaryWarehouse = OrganizationWarehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Allocated warehouse',
            'code' => 'ALLOCATED',
            'warehouse_type' => OrganizationWarehouse::TYPE_EXTERNAL,
            'is_main' => false,
            'is_active' => true,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->createBalance($organization->id, $mainWarehouse->id, $material->id, 10);
        $this->createBalance($organization->id, $secondaryWarehouse->id, $material->id, 20);
        WarehouseProjectAllocation::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $secondaryWarehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'allocated_quantity' => 5,
            'allocated_by_user_id' => $user->id,
            'allocated_at' => now(),
        ]);

        $stock = $this->service->getStockData($organization->id, [
            'project_id' => $project->id,
        ]);

        $this->assertCount(1, $stock);
        $this->assertSame($secondaryWarehouse->id, $stock[0]['warehouse_id']);
        $this->assertSame(20.0, $stock[0]['available_quantity']);
        $this->assertSame(5.0, $stock[0]['allocated_total']);
    }

    public function test_paginated_stock_data_paginates_positions_and_keeps_full_summary(): void
    {
        [$organization, $warehouse, $material] = $this->createWarehouseContext();
        $secondMaterial = $material->replicate()->fill([
            'name' => 'Sand',
            'code' => 'SAND',
        ]);
        $secondMaterial->save();
        $thirdMaterial = $material->replicate()->fill([
            'name' => 'Brick',
            'code' => 'BRICK',
        ]);
        $thirdMaterial->save();
        $sufficientMaterial = $material->replicate()->fill([
            'name' => 'Aggregate sufficient stock',
            'code' => 'ENOUGH',
        ]);
        $sufficientMaterial->save();

        WarehouseBalance::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 3,
            'reserved_quantity' => 2,
            'unit_price' => 100,
            'min_stock_level' => 10,
            'batch_number' => 'PAGE-A',
        ]);
        WarehouseBalance::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 4,
            'reserved_quantity' => 1,
            'unit_price' => 100,
            'min_stock_level' => 10,
            'batch_number' => 'PAGE-B',
        ]);
        $this->createBalance($organization->id, $warehouse->id, $secondMaterial->id, 20);
        $this->createBalance($organization->id, $warehouse->id, $thirdMaterial->id, 30);
        foreach (['ENOUGH-A', 'ENOUGH-B'] as $batchNumber) {
            WarehouseBalance::create([
                'organization_id' => $organization->id,
                'warehouse_id' => $warehouse->id,
                'material_id' => $sufficientMaterial->id,
                'available_quantity' => 6,
                'reserved_quantity' => 0,
                'unit_price' => 100,
                'min_stock_level' => 10,
                'batch_number' => $batchNumber,
            ]);
        }

        $firstPage = $this->service->getPaginatedStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
        ], 1, 2);
        $secondPage = $this->service->getPaginatedStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
        ], 2, 2);
        $lowStockPage = $this->service->getPaginatedStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'low_stock' => true,
        ], 1, 25);

        $this->assertCount(2, $firstPage['items']);
        $this->assertSame(4, $firstPage['pagination']['total']);
        $this->assertSame(2, $firstPage['pagination']['last_page']);
        $this->assertSame(4, $firstPage['summary']['total_items']);
        $this->assertSame(1, $firstPage['summary']['low_stock_count']);
        $this->assertSame(7200.0, $firstPage['summary']['total_value']);
        $this->assertSame(10.0, $firstPage['items'][0]['total_quantity']);
        $this->assertCount(2, $secondPage['items']);
        $this->assertSame(1, $lowStockPage['pagination']['total']);
        $this->assertSame($material->id, $lowStockPage['items'][0]['material_id']);
    }

    public function test_paginated_stock_data_applies_search_and_missing_location_to_full_positions(): void
    {
        [$organization, $warehouse, $material] = $this->createWarehouseContext();
        $material->update(['name' => 'Special cement', 'code' => 'SPECIAL-500']);
        $locatedMaterial = $material->replicate()->fill([
            'name' => 'Special located cement',
            'code' => 'SPECIAL-LOCATED',
        ]);
        $locatedMaterial->save();
        $otherMaterial = $material->replicate()->fill([
            'name' => 'Other material',
            'code' => 'OTHER',
        ]);
        $otherMaterial->save();

        $this->createBalance($organization->id, $warehouse->id, $material->id, 10);
        $locatedBalance = $this->createBalance($organization->id, $warehouse->id, $locatedMaterial->id, 20);
        $locatedBalance->update(['location_code' => 'LOCATED-A']);
        WarehouseBalance::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $locatedMaterial->id,
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'unit_price' => 100,
            'batch_number' => 'EMPTY-UNLOCATED',
        ]);
        $this->createBalance($organization->id, $warehouse->id, $otherMaterial->id, 30);

        $page = $this->service->getPaginatedStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'search' => 'special',
            'missing_location' => true,
        ], 1, 25);

        $this->assertSame(1, $page['pagination']['total']);
        $this->assertSame($material->id, $page['items'][0]['material_id']);
        $this->assertSame(10.0, $page['items'][0]['total_quantity']);
    }

    public function test_get_movements_data_returns_warehouse_movements(): void
    {
        [$organization, $warehouse, $material, $user] = $this->createWarehouseContext();
        $user->forceFill(['name' => 'technical_login'])->save();
        DB::table('organization_user')->insert([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'is_owner' => true,
            'is_active' => true,
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'project_access_mode' => 'all',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'personnel_number' => 'WAREHOUSE-AUTHOR-001',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'middle_name' => 'Иванович',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
        ]);
        $relatedUser = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $movement = WarehouseMovement::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
            'quantity' => 12.5,
            'price' => 150,
            'user_id' => $user->id,
            'related_user_id' => $relatedUser->id,
            'document_number' => 'RCPT-1',
            'reason' => 'Initial receipt',
            'movement_date' => '2026-05-01 10:00:00',
            'metadata' => ['transfer_pair_key' => 'PAIR-001'],
        ]);
        $otherMaterial = $material->replicate();
        $otherMaterial->name = 'Другой материал';
        $otherMaterial->code = 'OTHER-MATERIAL';
        $otherMaterial->save();
        WarehouseMovement::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $otherMaterial->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 1,
            'price' => 10,
            'user_id' => $user->id,
            'movement_date' => '2026-05-01 11:00:00',
        ]);

        $data = $this->service->getMovementsData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
        ]);

        $this->assertCount(1, $data);
        $this->assertSame($movement->id, $data[0]['movement_id']);
        $this->assertSame(WarehouseMovement::TYPE_RECEIPT, $data[0]['movement_type']);
        $this->assertSame('PAIR-001', $data[0]['transfer_pair_key']);
        $this->assertSame(WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE, $data[0]['operation_category']);
        $this->assertSame(trans_message('basic_warehouse.operation_categories.responsible_issue'), $data[0]['operation_category_label']);
        $this->assertSame($warehouse->id, $data[0]['warehouse_id']);
        $this->assertSame($warehouse->name, $data[0]['warehouse_name']);
        $this->assertSame($material->id, $data[0]['material_id']);
        $this->assertSame($material->name, $data[0]['material_name']);
        $this->assertSame(12.5, $data[0]['quantity']);
        $this->assertSame(150.0, $data[0]['price']);
        $this->assertSame(1875.0, $data[0]['total_value']);
        $this->assertSame('Иванов Иван Иванович', $data[0]['user_name']);
        $this->assertNotSame('technical_login', $data[0]['user_name']);

        WorkforceEmployee::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'personnel_number' => 'WAREHOUSE-AUTHOR-HISTORICAL',
            'last_name' => 'Петров',
            'first_name' => 'Пётр',
            'middle_name' => 'Петрович',
            'employment_status' => 'dismissed',
            'hire_date' => '2025-01-01',
            'dismissal_date' => '2025-12-31',
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $otherMaterial->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 1,
            'price' => 10,
            'user_id' => $user->id,
            'movement_date' => '2025-12-31 23:59:59',
        ]);

        $this->assertCount(2, $this->service->getMovementsData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'search' => 'Иванов Иван',
        ]));
        $historicalAuthorMovements = $this->service->getMovementsData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'search' => 'Петров Пётр',
        ]);
        $this->assertCount(1, $historicalAuthorMovements);
        $this->assertSame('Петров Пётр Петрович', $historicalAuthorMovements[0]['user_name']);
        $this->assertCount(0, $this->service->getMovementsData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'search' => 'technical_login',
        ]));
        $this->assertSame($relatedUser->id, $data[0]['related_user_id']);
        $this->assertSame($relatedUser->name, $data[0]['related_user_name']);
        $this->assertSame($relatedUser->id, $data[0]['related_user']['id']);
        $this->assertSame('RCPT-1', $data[0]['document_number']);

        $employee->delete();
        $withoutPersonnel = $this->service->getMovementsData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
        ]);

        $this->assertSame('Владелец организации', $withoutPersonnel[0]['user_name']);
        $this->assertNotSame('technical_login', $withoutPersonnel[0]['user_name']);

        $systemMovement = WarehouseMovement::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_ADJUSTMENT,
            'quantity' => 1,
            'price' => 0,
            'user_id' => null,
            'movement_date' => '2026-05-02 10:00:00',
        ]);
        $systemMovements = collect($this->service->getMovementsData($organization->id, [
            'warehouse_id' => $warehouse->id,
            'movement_type' => WarehouseMovement::TYPE_ADJUSTMENT,
        ]))->keyBy('movement_id');

        $this->assertSame('ФИО не указано', $systemMovements[$systemMovement->id]['user_name']);
    }

    public function test_get_movements_data_returns_readable_transfer_route(): void
    {
        [$organization, $sourceWarehouse, $material] = $this->createWarehouseContext();
        $targetWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Склад назначения',
            'code' => 'TARGET',
            'warehouse_type' => OrganizationWarehouse::TYPE_EXTERNAL,
            'is_main' => false,
            'is_active' => true,
        ]);

        $movement = WarehouseMovement::query()->create([
            'organization_id' => $organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'to_warehouse_id' => $targetWarehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'quantity' => 2,
            'price' => 100,
            'movement_date' => '2026-05-03 10:00:00',
        ]);

        $movements = collect($this->service->getMovementsData($organization->id, [
            'warehouse_id' => $sourceWarehouse->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
        ]))->keyBy('movement_id');

        $this->assertSame($sourceWarehouse->id, $movements[$movement->id]['warehouse_id']);
        $this->assertNull($movements[$movement->id]['from_warehouse_id']);
        $this->assertNull($movements[$movement->id]['from_warehouse_name']);
        $this->assertSame($targetWarehouse->id, $movements[$movement->id]['to_warehouse_id']);
        $this->assertSame($targetWarehouse->name, $movements[$movement->id]['to_warehouse_name']);
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

        $report = $this->service->getTurnoverAnalyticsReport($organization->id, [
            'date_from' => now()->subDays(10),
            'date_to' => now(),
            'warehouse_id' => $warehouse->id,
        ]);

        $this->assertSame('кг', $report['materials'][0]['measurement_unit']);
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
        $this->assertSame('кг', $data['forecasts'][0]['measurement_unit']);
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

    public function test_get_abc_xyz_analysis_accepts_string_date_filters(): void
    {
        [$organization, $warehouse, $material, $user] = $this->createWarehouseContext();
        $this->createWriteOff($organization->id, $warehouse->id, $material->id, $user->id, 5, 100);

        $data = $this->service->getAbcXyzAnalysis($organization->id, [
            'date_from' => now()->subDays(10)->toDateString(),
            'date_to' => now()->toDateString(),
            'warehouse_id' => (string) $warehouse->id,
        ]);

        $this->assertSame(now()->subDays(10)->toDateString(), $data['analysis_period']['date_from']);
        $this->assertSame(now()->toDateString(), $data['analysis_period']['date_to']);
        $this->assertSame(1, $data['summary']['total_assets_analyzed']);
        $this->assertSame($material->id, $data['assets'][0]['asset_id']);
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
        $measurementUnit = MeasurementUnit::query()
            ->where('organization_id', $organization->id)
            ->where('short_name', 'кг')
            ->firstOrFail();
        $material = Material::create([
            'organization_id' => $organization->id,
            'name' => 'Cement M500',
            'code' => 'CEM-500',
            'measurement_unit_id' => $measurementUnit->id,
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
            'document_number' => 'WO-'.str_replace('.', '-', (string) microtime(true)),
            'reason' => 'Write off for analytics',
            'movement_date' => now()->subDay(),
        ]);
    }
}
