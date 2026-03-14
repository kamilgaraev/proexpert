<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseItemGallery;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Models\File;
use App\Models\Material;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WarehousePhotoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_stock_data_returns_balance_and_asset_photo_galleries(): void
    {
        Storage::fake('s3');

        $organization = Organization::factory()->create();

        $warehouse = OrganizationWarehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Основной склад',
            'code' => 'MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);

        $material = Material::create([
            'organization_id' => $organization->id,
            'name' => 'Цемент М500',
            'code' => 'CEM-500',
            'additional_properties' => ['asset_type' => 'material'],
            'is_active' => true,
        ]);

        WarehouseBalance::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 15,
            'reserved_quantity' => 2,
            'unit_price' => 320,
        ]);

        $gallery = WarehouseItemGallery::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
        ]);

        File::create([
            'organization_id' => $organization->id,
            'fileable_id' => $gallery->id,
            'fileable_type' => WarehouseItemGallery::class,
            'name' => 'balance-photo.jpg',
            'original_name' => 'balance-photo.jpg',
            'path' => 'org-' . $organization->id . '/warehouse/balance-photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'disk' => 's3',
            'type' => 'photo',
            'category' => 'warehouse_balance',
        ]);

        File::create([
            'organization_id' => $organization->id,
            'fileable_id' => $material->id,
            'fileable_type' => Material::class,
            'name' => 'asset-photo.jpg',
            'original_name' => 'asset-photo.jpg',
            'path' => 'org-' . $organization->id . '/warehouse/asset-photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'disk' => 's3',
            'type' => 'photo',
            'category' => 'warehouse_asset',
        ]);

        $data = app(WarehouseService::class)->getStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
        ]);

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['photo_gallery']);
        $this->assertCount(1, $data[0]['asset_photo_gallery']);
        $this->assertSame('Цемент М500', $data[0]['material_name']);
    }
}
