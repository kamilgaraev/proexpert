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
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M17\M17ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M4\M4ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M7\M7ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M8\M8ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\WriteOffAct\WriteOffActExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehousePreparedExportIsolationTest extends TestCase
{
    use RefreshDatabase;

    public static function forms(): array
    {
        return [
            'm4' => [M4ExportStrategy::class],
            'm7' => [M7ExportStrategy::class],
            'm8' => [M8ExportStrategy::class],
            'm11' => [M11ExportStrategy::class],
            'm15' => [M15ExportStrategy::class],
            'm17' => [M17ExportStrategy::class],
            'inv3' => [INV3ExportStrategy::class],
            'write-off-act' => [WriteOffActExportStrategy::class],
        ];
    }

    #[DataProvider('forms')]
    public function test_preparing_another_version_preserves_the_previously_prepared_file(string $strategy): void
    {
        Storage::fake('s3');
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(9, 0));
        $context = AdminApiTestContext::create();
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Учебная единица', 'short_name' => 'ед-изол', 'type' => 'material',
            'is_default' => false, 'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Первая учебная версия материала', 'code' => 'QA-FILE-ISOLATION',
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL], 'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Учебный склад', 'code' => 'QA-FILE-ISOLATION',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL, 'is_main' => false, 'is_active' => true,
        ]);
        $movement = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id, 'material_id' => $material->id, 'user_id' => $context->user->id,
            'movement_type' => $strategy === WriteOffActExportStrategy::class ? WarehouseMovement::TYPE_WRITE_OFF : WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 2, 'price' => 10, 'document_number' => 'УЧ-ФАЙЛ-24',
            'reason' => 'Проверка сохранности подготовленного файла', 'metadata' => [],
            'movement_date' => '2026-10-24 09:00:00',
        ]);
        $movement->setRelation('material', $material);
        $reservation = AssetReservation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id, 'material_id' => $material->id,
            'reserved_by' => $context->user->id, 'quantity' => 2, 'status' => AssetReservation::STATUS_ACTIVE,
            'reserved_at' => '2026-10-24 09:00:00', 'expires_at' => '2026-10-25 09:00:00',
            'metadata' => ['document_number' => 'УЧ-ФАЙЛ-24'],
        ]);
        $reservation->setRelation('material', $material);
        $act = InventoryAct::query()->create([
            'organization_id' => $context->organization->id, 'warehouse_id' => $warehouse->id,
            'act_number' => 'УЧ-ФАЙЛ-24', 'status' => InventoryAct::STATUS_COMPLETED,
            'inventory_date' => '2026-10-24', 'created_by' => $context->user->id,
        ]);
        $item = InventoryActItem::query()->create([
            'inventory_act_id' => $act->id, 'material_id' => $material->id,
            'expected_quantity' => 2, 'actual_quantity' => 2, 'difference' => 0, 'unit_price' => 10, 'total_value' => 0,
        ]);
        $item->setRelation('material', $material);
        $act->setRelation('items', new Collection([$item]));
        $input = match ($strategy) {
            M8ExportStrategy::class => ['reservation' => $reservation, 'movements' => collect()],
            M17ExportStrategy::class => ['material' => $material, 'warehouse' => $warehouse, 'movements' => collect([$movement])],
            INV3ExportStrategy::class => $act,
            default => $movement,
        };
        $exporter = app($strategy);
        $firstPath = $exporter->export($input);
        $firstBytes = Storage::disk('s3')->get($firstPath);
        $this->assertStringContainsString('Первая учебная версия материала', $this->workbookText($firstBytes));

        $material->name = 'Вторая учебная версия материала';
        $secondPath = $exporter->export($input);
        $secondBytes = Storage::disk('s3')->get($secondPath);
        $this->assertStringContainsString('Вторая учебная версия материала', $this->workbookText($secondBytes));
        $this->assertNotSame($firstBytes, $secondBytes);
        $this->assertNotSame($firstPath, $secondPath);
        $this->assertSame(basename($firstPath), basename($secondPath));
        foreach ([$firstPath, $secondPath] as $path) {
            $this->assertStringStartsWith("org-{$context->organization->id}/exports/warehouse/", $path);
            Storage::disk('s3')->assertExists($path);
        }
        $this->assertSame($firstBytes, Storage::disk('s3')->get($firstPath));
    }

    private function workbookText(string $bytes): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'warehouse_prepared_');
        $this->assertNotFalse($temporary);
        try {
            file_put_contents($temporary, $bytes);
            $book = IOFactory::load($temporary);
            try {
                $values = [];
                foreach ($book->getAllSheets() as $sheet) {
                    foreach ($sheet->toArray(null, false, false) as $row) {
                        foreach ($row as $value) {
                            if (is_scalar($value)) {
                                $values[] = (string) $value;
                            }
                        }
                    }
                }

                return implode("\n", $values);
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            unlink($temporary);
        }
    }
}
