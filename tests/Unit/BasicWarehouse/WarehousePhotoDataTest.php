<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseItemGallery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Models\File;
use App\Models\Material;
use App\Models\Organization;
use App\Models\User;
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
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

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
            'user_id' => $user->id,
            'name' => 'balance-photo.jpg',
            'original_name' => 'balance-photo.jpg',
            'path' => 'org-'.$organization->id.'/warehouse/balance-photo.jpg',
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
            'user_id' => $user->id,
            'name' => 'asset-photo.jpg',
            'original_name' => 'asset-photo.jpg',
            'path' => 'org-'.$organization->id.'/warehouse/asset-photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'disk' => 's3',
            'type' => 'photo',
            'category' => 'warehouse_asset',
        ]);

        $movement = WarehouseMovement::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 15,
            'price' => 320,
            'user_id' => $user->id,
            'movement_date' => now(),
        ]);

        File::create([
            'organization_id' => $organization->id,
            'fileable_id' => $movement->id,
            'fileable_type' => WarehouseMovement::class,
            'user_id' => $user->id,
            'name' => 'receipt-photo.jpg',
            'original_name' => 'receipt-photo.jpg',
            'path' => 'org-'.$organization->id.'/warehouse/receipt-photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 4096,
            'disk' => 's3',
            'type' => 'photo',
            'category' => 'receipt',
        ]);

        WarehouseIdentifier::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'identifier_type' => WarehouseIdentifier::TYPE_QR,
            'code' => 'QR-CEM-500',
            'entity_type' => 'asset',
            'entity_id' => $material->id,
            'label' => 'Cement QR',
            'status' => WarehouseIdentifier::STATUS_ACTIVE,
            'is_primary' => true,
            'assigned_at' => now(),
        ]);

        $data = app(WarehouseService::class)->getStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
        ]);

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['photo_gallery']);
        $this->assertCount(1, $data[0]['receipt_photo_gallery']);
        $this->assertCount(1, $data[0]['asset_photo_gallery']);
        $this->assertSame('QR-CEM-500', $data[0]['qr_code']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $data[0]['qr_code_image_url']);
        $this->assertSame('Цемент М500', $data[0]['material_name']);
    }

    public function test_warehouse_photo_upload_success_message_is_translated(): void
    {
        $this->assertSame('Фотографии успешно загружены', trans_message('warehouse_basic.photo_upload_success'));
    }

    public function test_get_stock_data_returns_receipt_photos_when_balance_gallery_is_empty(): void
    {
        Storage::fake('s3');

        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $warehouse = OrganizationWarehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Main warehouse',
            'code' => 'MAIN-RECEIPT-PHOTO',
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

        WarehouseBalance::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 15,
            'reserved_quantity' => 0,
            'unit_price' => 320,
        ]);

        $movement = WarehouseMovement::create([
            'organization_id' => $organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 15,
            'price' => 320,
            'metadata' => [],
            'movement_date' => now(),
        ]);

        File::create([
            'organization_id' => $organization->id,
            'fileable_id' => $movement->id,
            'fileable_type' => WarehouseMovement::class,
            'user_id' => $user->id,
            'name' => 'receipt-photo.jpg',
            'original_name' => 'receipt-photo.jpg',
            'path' => 'org-'.$organization->id.'/warehouse/receipt-photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'disk' => 's3',
            'type' => 'photo',
            'category' => 'receipt',
        ]);

        $data = app(WarehouseService::class)->getStockData($organization->id, [
            'warehouse_id' => $warehouse->id,
        ]);

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['photo_gallery']);
        $this->assertSame('receipt-photo.jpg', $data[0]['photo_gallery'][0]['original_name']);
    }
}
