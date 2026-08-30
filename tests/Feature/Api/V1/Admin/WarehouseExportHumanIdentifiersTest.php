<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\INV3\INV3ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M11\M11ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M15\M15ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M4\M4ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M7\M7ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M8\M8ExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseExportHumanIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_forms_never_use_database_ids_as_document_numbers_or_filenames(): void
    {
        Storage::fake('s3');

        [$context, $material, $warehouse] = $this->warehouseContext();

        $movement = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'user_id' => $context->user->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 5,
            'price' => 100,
            'document_number' => null,
            'reason' => 'Приёмка материалов',
            'metadata' => ['supplier_name' => 'Поставщик'],
            'movement_date' => '2026-08-30 10:11:12',
        ]);

        $forms = [
            [M4ExportStrategy::class, 'H11'],
            [M7ExportStrategy::class, 'H11'],
            [M11ExportStrategy::class, 'H11'],
            [M15ExportStrategy::class, 'H12'],
        ];

        foreach ($forms as [$strategy, $numberCell]) {
            $path = app($strategy)->export($movement);
            $sheet = $this->sheetFromStorage($path);

            $this->assertSame('б/н', $sheet->getCell($numberCell)->getValue());
            $this->assertStringContainsString('20260830_101112', $path);
            $this->assertStringNotContainsString('_'.$movement->id.'.xlsx', $path);
        }

        $act = InventoryAct::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => '',
            'status' => InventoryAct::STATUS_COMPLETED,
            'inventory_date' => '2026-08-30',
            'created_by' => $context->user->id,
        ]);
        InventoryActItem::query()->create([
            'inventory_act_id' => $act->id,
            'material_id' => $material->id,
            'expected_quantity' => 5,
            'actual_quantity' => 5,
            'difference' => 0,
            'unit_price' => 100,
            'total_value' => 0,
        ]);

        $inventoryPath = app(INV3ExportStrategy::class)->export($act);
        $inventorySheet = $this->sheetFromStorage($inventoryPath);

        $this->assertSame('б/н', $inventorySheet->getCell('D11')->getValue());
        $this->assertStringContainsString('20260830', $inventoryPath);
        $this->assertStringNotContainsString('_'.$act->id.'.xlsx', $inventoryPath);
    }

    public function test_m8_uses_material_data_and_human_readable_numbers(): void
    {
        Storage::fake('s3');

        [$context, $material, $warehouse] = $this->warehouseContext();
        $reservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 8,
            'reserved_by' => $context->user->id,
            'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => '2026-08-30 09:00:00',
            'expires_at' => '2026-08-31 09:00:00',
            'reason' => 'Работы на объекте',
            'metadata' => [],
        ]);
        $movement = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'user_id' => $context->user->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => 3,
            'price' => 100,
            'document_number' => null,
            'reason' => 'Выдача по лимиту',
            'metadata' => [],
            'movement_date' => '2026-08-30 12:00:00',
        ]);

        $path = app(M8ExportStrategy::class)->export([
            'reservation' => $reservation,
            'movements' => collect([$movement]),
        ]);
        $sheet = $this->sheetFromStorage($path);

        $this->assertSame('ЛИМИТНО-ЗАБОРНАЯ КАРТА № б/н', $sheet->getCell('A9')->getValue());
        $this->assertSame('Материал: Кабель ВВГ', $sheet->getCell('A11')->getValue());
        $this->assertSame('Лимит: 8.000 м', $sheet->getCell('A12')->getValue());
        $this->assertSame('б/н', $sheet->getCell('B16')->getValue());
        $this->assertStringContainsString('kab-vvg_', $path);
        $this->assertStringContainsString('20260830_090000', $path);
        $this->assertStringNotContainsString('Res'.$reservation->id, $path);
    }

    private function warehouseContext(): array
    {
        $context = AdminApiTestContext::create();
        $unit = MeasurementUnit::query()
            ->where('organization_id', $context->organization->id)
            ->whereRaw('LOWER(short_name) = ?', ['м'])
            ->firstOrFail();
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Кабель ВВГ',
            'code' => 'КАБ-ВВГ',
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Центральный склад',
            'code' => 'CENTRAL',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);

        return [$context, $material, $warehouse];
    }

    private function sheetFromStorage(string $path): Worksheet
    {
        Storage::disk('s3')->assertExists($path);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'warehouse_export_').'.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get($path));

        return IOFactory::load($temporaryPath)->getActiveSheet();
    }
}
