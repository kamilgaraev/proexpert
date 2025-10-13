<?php

namespace Tests\Unit\AdvancedWarehouse;

use App\BusinessModules\Features\AdvancedWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\AdvancedWarehouse\Models\AutoReorderRule;
use App\BusinessModules\Features\AdvancedWarehouse\Services\AdvancedWarehouseService;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Material;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тесты продвинутых функций склада
 */
class AdvancedWarehouseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AdvancedWarehouseService $service;
    protected Organization $organization;
    protected OrganizationWarehouse $warehouse;
    protected Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AdvancedWarehouseService::class);

        $this->organization = Organization::factory()->create();
        
        $this->warehouse = OrganizationWarehouse::create([
            'organization_id' => $this->organization->id,
            'name' => 'Тестовый склад',
            'type' => 'main',
            'is_active' => true,
        ]);

        $this->material = Material::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Тестовый материал',
        ]);

        // Создаем баланс
        WarehouseBalance::create([
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'available_quantity' => 100,
            'reserved_quantity' => 0,
            'average_price' => 50.00,
        ]);
    }

    /** @test */
    public function it_can_reserve_assets()
    {
        $result = $this->service->reserveAssets(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            30,
            ['reason' => 'Тестовое резервирование']
        );

        $this->assertTrue($result['reserved']);
        $this->assertEquals(30, $result['quantity']);
        $this->assertEquals(70, $result['remaining_available']);

        // Проверяем что создалась резервация
        $this->assertDatabaseHas('asset_reservations', [
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'quantity' => 30,
            'status' => 'active',
        ]);

        // Проверяем что баланс обновился
        $balance = WarehouseBalance::where('warehouse_id', $this->warehouse->id)
            ->where('material_id', $this->material->id)
            ->first();
        
        $this->assertEquals(70, $balance->available_quantity);
        $this->assertEquals(30, $balance->reserved_quantity);
    }

    /** @test */
    public function it_can_unreserve_assets()
    {
        // Резервируем
        $result = $this->service->reserveAssets(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            30
        );

        // Снимаем резервирование
        $unreserved = $this->service->unreserveAssets($result['reservation_id']);

        $this->assertTrue($unreserved);

        // Проверяем что резервация отменена
        $this->assertDatabaseHas('asset_reservations', [
            'id' => $result['reservation_id'],
            'status' => 'cancelled',
        ]);

        // Проверяем что баланс восстановился
        $balance = WarehouseBalance::where('warehouse_id', $this->warehouse->id)
            ->where('material_id', $this->material->id)
            ->first();
        
        $this->assertEquals(100, $balance->available_quantity);
        $this->assertEquals(0, $balance->reserved_quantity);
    }

    /** @test */
    public function it_can_create_auto_reorder_rule()
    {
        $result = $this->service->createAutoReorderRule(
            $this->organization->id,
            $this->material->id,
            [
                'warehouse_id' => $this->warehouse->id,
                'min_stock' => 10,
                'max_stock' => 100,
                'reorder_point' => 20,
                'reorder_quantity' => 50,
            ]
        );

        $this->assertEquals('created', $result['action']);
        $this->assertEquals(10, $result['min_stock']);
        $this->assertEquals(100, $result['max_stock']);

        $this->assertDatabaseHas('auto_reorder_rules', [
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_can_check_auto_reorder()
    {
        // Создаем правило
        AutoReorderRule::create([
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'min_stock' => 10,
            'max_stock' => 100,
            'reorder_point' => 20,
            'reorder_quantity' => 50,
            'is_active' => true,
        ]);

        // Уменьшаем остаток до критического
        $balance = WarehouseBalance::where('warehouse_id', $this->warehouse->id)
            ->where('material_id', $this->material->id)
            ->first();
        $balance->available_quantity = 15;
        $balance->save();

        // Проверяем
        $result = $this->service->checkAutoReorder($this->organization->id);

        $this->assertEquals(1, $result['rules_checked']);
        $this->assertEquals(1, $result['orders_to_generate']);
        $this->assertNotEmpty($result['orders']);
    }

    /** @test */
    public function it_calculates_turnover_analytics()
    {
        // Создаем движения
        for ($i = 0; $i < 5; $i++) {
            WarehouseMovement::create([
                'organization_id' => $this->organization->id,
                'warehouse_id' => $this->warehouse->id,
                'material_id' => $this->material->id,
                'movement_type' => 'write_off',
                'quantity' => 10,
                'price' => 50,
                'movement_date' => now()->subDays($i),
            ]);
        }

        $result = $this->service->getTurnoverAnalytics($this->organization->id, [
            'date_from' => now()->subMonth(),
            'date_to' => now(),
        ]);

        $this->assertArrayHasKey('assets', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertNotEmpty($result['assets']);
    }

    /** @test */
    public function it_generates_forecast()
    {
        // Создаем исторические данные
        for ($i = 0; $i < 30; $i++) {
            WarehouseMovement::create([
                'organization_id' => $this->organization->id,
                'warehouse_id' => $this->warehouse->id,
                'material_id' => $this->material->id,
                'movement_type' => 'write_off',
                'quantity' => 2,
                'price' => 50,
                'movement_date' => now()->subDays($i),
            ]);
        }

        $result = $this->service->getForecastData($this->organization->id, [
            'horizon_days' => 30,
        ]);

        $this->assertArrayHasKey('forecasts', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertNotEmpty($result['forecasts']);
    }

    /** @test */
    public function it_performs_abc_xyz_analysis()
    {
        // Создаем движения с разной стоимостью
        for ($i = 0; $i < 10; $i++) {
            WarehouseMovement::create([
                'organization_id' => $this->organization->id,
                'warehouse_id' => $this->warehouse->id,
                'material_id' => $this->material->id,
                'movement_type' => 'write_off',
                'quantity' => 5,
                'price' => 100,
                'movement_date' => now()->subDays($i * 10),
            ]);
        }

        $result = $this->service->getAbcXyzAnalysis($this->organization->id, [
            'date_from' => now()->subYear(),
            'date_to' => now(),
        ]);

        $this->assertArrayHasKey('assets', $result);
        $this->assertArrayHasKey('abc_distribution', $result);
        $this->assertArrayHasKey('xyz_distribution', $result);
        $this->assertNotEmpty($result['assets']);
    }
}

