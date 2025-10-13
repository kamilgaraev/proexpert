<?php

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Models\Material;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тесты операций со складом
 */
class WarehouseOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected WarehouseService $warehouseService;
    protected Organization $organization;
    protected OrganizationWarehouse $warehouse;
    protected Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouseService = app(WarehouseService::class);

        // Создаем тестовые данные
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
            'code' => 'TEST-001',
        ]);
    }

    /** @test */
    public function it_can_receive_assets()
    {
        $result = $this->warehouseService->receiveAsset(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            100,
            50.00,
            ['reason' => 'Тестовое поступление']
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('balance', $result);
        $this->assertArrayHasKey('movement', $result);
        $this->assertEquals(100, $result['new_quantity']);

        // Проверяем что создалась запись движения
        $this->assertDatabaseHas('warehouse_movements', [
            'organization_id' => $this->organization->id,
            'warehouse_id' => $this->warehouse->id,
            'material_id' => $this->material->id,
            'movement_type' => 'receipt',
            'quantity' => 100,
        ]);
    }

    /** @test */
    public function it_can_write_off_assets()
    {
        // Сначала оприходуем
        $this->warehouseService->receiveAsset(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            100,
            50.00
        );

        // Списываем
        $result = $this->warehouseService->writeOffAsset(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            30,
            ['reason' => 'Тестовое списание']
        );

        $this->assertIsArray($result);
        $this->assertEquals(70, $result['remaining_quantity']);

        // Проверяем запись движения
        $this->assertDatabaseHas('warehouse_movements', [
            'material_id' => $this->material->id,
            'movement_type' => 'write_off',
            'quantity' => 30,
        ]);
    }

    /** @test */
    public function it_can_transfer_assets_between_warehouses()
    {
        $warehouse2 = OrganizationWarehouse::create([
            'organization_id' => $this->organization->id,
            'name' => 'Второй склад',
            'type' => 'branch',
            'is_active' => true,
        ]);

        // Оприходуем на первый склад
        $this->warehouseService->receiveAsset(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            100,
            50.00
        );

        // Перемещаем
        $result = $this->warehouseService->transferAsset(
            $this->organization->id,
            $this->warehouse->id,
            $warehouse2->id,
            $this->material->id,
            40,
            ['reason' => 'Тестовое перемещение']
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('from_balance', $result);
        $this->assertArrayHasKey('to_balance', $result);
        $this->assertArrayHasKey('movement_out', $result);
        $this->assertArrayHasKey('movement_in', $result);

        // Проверяем остатки
        $fromBalance = WarehouseBalance::where('warehouse_id', $this->warehouse->id)
            ->where('material_id', $this->material->id)
            ->first();
        $this->assertEquals(60, $fromBalance->available_quantity);

        $toBalance = WarehouseBalance::where('warehouse_id', $warehouse2->id)
            ->where('material_id', $this->material->id)
            ->first();
        $this->assertEquals(40, $toBalance->available_quantity);
    }

    /** @test */
    public function it_throws_exception_when_insufficient_quantity()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->warehouseService->receiveAsset(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            50,
            50.00
        );

        // Пытаемся списать больше чем есть
        $this->warehouseService->writeOffAsset(
            $this->organization->id,
            $this->warehouse->id,
            $this->material->id,
            100
        );
    }
}

