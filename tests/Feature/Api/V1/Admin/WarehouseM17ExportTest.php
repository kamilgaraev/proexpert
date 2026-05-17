<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M17\M17ExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseM17ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_m17_export_is_landscape_official_material_card(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Штука',
            'short_name' => 'шт-м17',
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => '45783 952700-35154000093',
            'code' => 'У0000000022',
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'первый',
            'code' => 'MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);

        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 5,
            'price' => 100,
            'document_number' => 'УК000000001',
            'reason' => 'Оприходование товаров',
            'metadata' => [],
            'movement_date' => '2026-05-01 10:00:00',
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => 3,
            'price' => 100,
            'document_number' => 'УТУК0003768',
            'reason' => 'Выдано в ООО Подрядчик',
            'metadata' => [],
            'movement_date' => '2026-05-02 10:00:00',
        ]);

        $path = app(M17ExportStrategy::class)->export([
            'material' => $material,
            'warehouse' => $warehouse,
            'warehouse_id' => $warehouse->id,
            'movements' => WarehouseMovement::query()->orderBy('movement_date')->get(),
        ]);

        Storage::disk('s3')->assertExists($path);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'm17_') . '.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get($path));

        $spreadsheet = IOFactory::load($temporaryPath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
        $this->assertSame('КАРТОЧКА №', $sheet->getCell('F4')->getValue());
        $this->assertSame('учета материалов', $sheet->getCell('F5')->getValue());
        $this->assertSame('0315008', (string) $sheet->getCell('Q4')->getValue());
        $this->assertSame('первый', $sheet->getCell('E14')->getValue());
        $this->assertSame('45783 952700-35154000093', $sheet->getCell('D17')->getValue());
        $this->assertSame('УК000000001', $sheet->getCell('B28')->getValue());
        $this->assertSame(5.0, (float) $sheet->getCell('N28')->getValue());
        $this->assertSame(2.0, (float) $sheet->getCell('P29')->getValue());
        $this->assertContains('D22:L24', $sheet->getMergeCells());
    }
}
