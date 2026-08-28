<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseReservationProjectionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fully_reserved_stock_remains_visible_and_keeps_physical_value(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->findKilogramUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $warehouse = $this->createWarehouse($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'unit_price' => 18,
            'min_stock_level' => 0,
            'max_stock_level' => 0,
            'last_movement_at' => now(),
        ]);
        $service = app(WarehouseService::class);

        $before = $service->getStockData($context->organization->id, ['warehouse_id' => $warehouse->id]);
        $this->assertCount(1, $before);
        $this->assertSame(10.0, $before[0]['total_quantity']);
        $this->assertSame(180.0, (float) $before[0]['total_value']);
        $this->assertSame(18.0, $before[0]['average_price']);

        $service->reserveAssets(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            10,
            ['project_id' => $project->id, 'user_id' => $context->user->id]
        );

        $fullyReserved = $service->getStockData(
            $context->organization->id,
            ['warehouse_id' => $warehouse->id]
        );
        $this->assertCount(1, $fullyReserved);
        $this->assertSame(0.0, $fullyReserved[0]['available_quantity']);
        $this->assertSame(10.0, $fullyReserved[0]['reserved_quantity']);
        $this->assertSame(10.0, $fullyReserved[0]['total_quantity']);
        $this->assertSame(180.0, (float) $fullyReserved[0]['total_value']);
        $this->assertSame(18.0, $fullyReserved[0]['average_price']);

        $service->releaseReservedAssets(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            4,
            ['project_id' => $project->id, 'user_id' => $context->user->id]
        );

        $partiallyReserved = $service->getStockData(
            $context->organization->id,
            ['warehouse_id' => $warehouse->id]
        );
        $this->assertCount(1, $partiallyReserved);
        $this->assertSame(4.0, $partiallyReserved[0]['available_quantity']);
        $this->assertSame(6.0, $partiallyReserved[0]['reserved_quantity']);
        $this->assertSame(10.0, $partiallyReserved[0]['total_quantity']);
        $this->assertSame(180.0, (float) $partiallyReserved[0]['total_value']);
        $this->assertSame(18.0, $partiallyReserved[0]['average_price']);
    }

    public function test_reservation_list_uses_material_measurement_unit(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->findKilogramUnit($context->organization->id);
        $material = $this->createMaterial($context->organization->id, $unit->id);
        $warehouse = $this->createWarehouse($context->organization->id);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'unit_price' => 18,
            'min_stock_level' => 0,
            'max_stock_level' => 0,
            'last_movement_at' => now(),
        ]);
        app(WarehouseService::class)->reserveAssets(
            $context->organization->id,
            $warehouse->id,
            $material->id,
            2,
            ['user_id' => $context->user->id]
        );
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/advanced-warehouse/reservations?warehouse_id='.$warehouse->id.'&status=active')
            ->assertOk()
            ->assertJsonPath('data.data.0.material.id', $material->id)
            ->assertJsonPath('data.data.0.material.unit', 'кг');
    }

    private function findKilogramUnit(int $organizationId): MeasurementUnit
    {
        return MeasurementUnit::query()
            ->where('organization_id', $organizationId)
            ->where('short_name', 'кг')
            ->firstOrFail();
    }

    private function createMaterial(int $organizationId, int $measurementUnitId): Material
    {
        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Цемент М500',
            'code' => 'CEM-RESERVATION-REGRESSION',
            'measurement_unit_id' => $measurementUnitId,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
    }

    private function createWarehouse(int $organizationId): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Регрессионный склад резервов',
            'code' => 'RESERVATION-REGRESSION',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
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
