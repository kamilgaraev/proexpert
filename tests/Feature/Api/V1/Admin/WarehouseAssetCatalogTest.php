<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseAssetCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_assets_are_primary_material_catalog_without_organization_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id, 'Kilogram', 'kg');
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign kilogram', 'fkg');
        $foreignAsset = $this->createAsset($foreignContext->organization->id, $foreignUnit->id, 'Foreign cement', 'CEM-F');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/assets', [
                'name' => 'Cement M500',
                'code' => 'CEM-500',
                'measurement_unit_id' => $unit->id,
                'asset_type' => Asset::TYPE_MATERIAL,
                'asset_category' => 'Concrete',
                'default_price' => 4100,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.name', 'Cement M500');
        $createResponse->assertJsonPath('data.asset_type', Asset::TYPE_MATERIAL);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);

        $asset = Material::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $asset->organization_id);
        $this->assertSame($unit->id, $asset->measurement_unit_id);
        $this->assertSame(Asset::TYPE_MATERIAL, $asset->additional_properties['asset_type']);

        $foreignUnitResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/assets', [
                'name' => 'Bad unit asset',
                'measurement_unit_id' => $foreignUnit->id,
                'asset_type' => Asset::TYPE_MATERIAL,
            ]);

        $foreignUnitResponse->assertStatus(422);
        $this->assertDatabaseMissing('materials', [
            'organization_id' => $context->organization->id,
            'name' => 'Bad unit asset',
        ]);

        $searchResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/assets?q=cem&per_page=20');

        $searchResponse->assertOk();
        $searchResponse->assertJsonPath('success', true);
        $searchResponse->assertJsonPath('data.0.id', $asset->id);
        $this->assertSame(1, $searchResponse->json('meta.total'));

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/assets/{$foreignAsset->id}");

        $foreignShowResponse->assertNotFound();

        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/assets/{$foreignAsset->id}", [
                'name' => 'Hijacked asset',
            ]);

        $foreignUpdateResponse->assertNotFound();
        $this->assertSame('Foreign cement', $foreignAsset->fresh()->name);

        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/assets/{$foreignAsset->id}");

        $foreignDeleteResponse->assertNotFound();
        $this->assertTrue((bool) $foreignAsset->fresh()->is_active);
    }

    public function test_receipt_created_asset_is_available_for_legacy_material_consumers(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Main warehouse',
            'code' => 'MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $this->createAsset($context->organization->id, $unit->id, 'Steel bar', 'STL-1');
        $this->allowAdminAccess();

        $receiptResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $warehouse->id,
                'material' => [
                    'name' => 'Pilot cement',
                    'code' => 'PILOT-CEM',
                    'measurement_unit_id' => $unit->id,
                    'asset_type' => Asset::TYPE_MATERIAL,
                ],
                'quantity' => 15,
                'price' => 250,
                'reason' => 'Initial stock',
            ]);

        $receiptResponse->assertCreated();

        $material = Material::query()
            ->where('organization_id', $context->organization->id)
            ->where('code', 'PILOT-CEM')
            ->firstOrFail();

        $assetSearchResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/assets?q=pilot&per_page=20');

        $assetSearchResponse->assertOk();
        $assetSearchResponse->assertJsonPath('data.0.id', $material->id);
        $this->assertSame(1, $assetSearchResponse->json('meta.total'));

        $legacySearchResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/materials/search?q=pilot&per_page=20');

        $legacySearchResponse->assertOk();
        $legacyIds = collect($legacySearchResponse->json('data'))->pluck('id')->all();
        $this->assertSame([$material->id], $legacyIds);
    }

    public function test_receipt_rejects_foreign_measurement_unit_before_creating_asset(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign piece', 'fpcs');
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Main warehouse',
            'code' => 'MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/warehouses/operations/receipt', [
                'warehouse_id' => $warehouse->id,
                'material' => [
                    'name' => 'Foreign unit receipt',
                    'code' => 'FOREIGN-UNIT',
                    'measurement_unit_id' => $foreignUnit->id,
                    'asset_type' => Asset::TYPE_MATERIAL,
                ],
                'quantity' => 15,
                'price' => 250,
                'reason' => 'Initial stock',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('materials', [
            'organization_id' => $context->organization->id,
            'code' => 'FOREIGN-UNIT',
        ]);
    }

    public function test_assets_can_be_filtered_by_selected_warehouse_balance(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $mainWarehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $reserveWarehouse = $this->createWarehouse($context->organization->id, 'Reserve warehouse', 'RES');
        $mainAsset = $this->createAsset($context->organization->id, $unit->id, 'Main cement', 'MAIN-CEM');
        $reserveAsset = $this->createAsset($context->organization->id, $unit->id, 'Reserve cement', 'RES-CEM');
        $this->createBalance($context->organization->id, $mainWarehouse->id, $mainAsset->id, 10, 100);
        $this->createBalance($context->organization->id, $reserveWarehouse->id, $reserveAsset->id, 5, 200);
        $this->allowAdminAccess();

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/assets?warehouse_id={$mainWarehouse->id}&per_page=20");

        $listResponse->assertOk();
        $this->assertSame(1, $listResponse->json('meta.total'));
        $this->assertSame([$mainAsset->id], collect($listResponse->json('data'))->pluck('id')->all());

        $statsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/assets/statistics?warehouse_id={$mainWarehouse->id}");

        $statsResponse->assertOk();
        $statsResponse->assertJsonPath('data.material.count', 1);
        $this->assertSame(1000.0, (float) $statsResponse->json('data.material.total_value'));
    }

    public function test_asset_created_from_selected_warehouse_remains_visible_in_that_warehouse_catalog(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $warehouse = $this->createWarehouse($context->organization->id, 'Main warehouse', 'MAIN');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/assets', [
                'name' => 'Warehouse-local asset',
                'code' => 'WH-LOCAL',
                'measurement_unit_id' => $unit->id,
                'asset_type' => Asset::TYPE_MATERIAL,
                'warehouse_id' => $warehouse->id,
                'default_price' => 125,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.warehouse_balances.0.warehouse_id', $warehouse->id);
        $createResponse->assertJsonPath('data.warehouse_balances.0.available_quantity', '0.000');
        $createResponse->assertJsonPath('data.warehouse_balances.0.unit_price', '125.00');

        $assetId = (int) $createResponse->json('data.id');
        $this->assertDatabaseHas('warehouse_balances', [
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $assetId,
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'unit_price' => 125,
        ]);

        $listResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/assets?warehouse_id={$warehouse->id}&per_page=20");

        $listResponse->assertOk();
        $this->assertSame([$assetId], collect($listResponse->json('data'))->pluck('id')->all());
    }

    public function test_asset_label_export_rejects_foreign_asset_ids_before_export(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign piece', 'fpcs');
        $foreignAsset = $this->createAsset($foreignContext->organization->id, $foreignUnit->id, 'Foreign label asset', 'LBL-FOR');
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/assets/export-labels-pdf', [
                'layout' => '4',
                'asset_ids' => [$foreignAsset->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('asset_ids.0');
    }

    private function createUnit(int $organizationId, string $name, string $shortName): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'short_name' => $shortName,
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function createAsset(int $organizationId, int $measurementUnitId, string $name, string $code): Material
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

    private function createBalance(
        int $organizationId,
        int $warehouseId,
        int $assetId,
        float $quantity,
        float $price
    ): WarehouseBalance {
        return WarehouseBalance::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $assetId,
            'available_quantity' => $quantity,
            'reserved_quantity' => 0,
            'unit_price' => $price,
            'min_stock_level' => 0,
            'max_stock_level' => 0,
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
