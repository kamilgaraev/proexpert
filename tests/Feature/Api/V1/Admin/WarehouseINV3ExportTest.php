<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\INV3\INV3ExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseINV3ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_inv3_export_prints_only_current_organization_commission_member_names(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create([
            'name' => 'Анна Петрова',
        ]);
        $foreignContext = AdminApiTestContext::create([
            'name' => 'Чужой Пользователь',
        ]);
        $employee = User::factory()->create([
            'name' => 'Борис Сидоров',
            'current_organization_id' => $context->organization->id,
        ]);
        $context->organization->users()->attach($employee->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Штука',
            'short_name' => 'шт-инв3',
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Кабель силовой',
            'code' => 'INV3-CABLE',
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Основной склад',
            'code' => 'INV3-MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $act = InventoryAct::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'INV-TEST-0001',
            'status' => InventoryAct::STATUS_APPROVED,
            'inventory_date' => '2026-08-31',
            'created_by' => $context->user->id,
            'approved_by' => $context->user->id,
            'commission_members' => [
                $employee->id,
                $foreignContext->user->id,
                $context->user->id,
                $employee->id,
            ],
        ]);
        InventoryActItem::query()->create([
            'inventory_act_id' => $act->id,
            'material_id' => $material->id,
            'expected_quantity' => 10,
            'actual_quantity' => 10,
            'difference' => 0,
            'unit_price' => 100,
            'total_value' => 0,
            'location_code' => 'A-01',
            'batch_number' => 'INV3-001',
        ]);

        $path = app(INV3ExportStrategy::class)->export(
            $act->load(['organization', 'warehouse', 'items.material.measurementUnit'])
        );

        Storage::disk('s3')->assertExists($path);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'inv3_').'.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get($path));

        $sheet = IOFactory::load($temporaryPath)->getActiveSheet();
        $commissionHeaderRow = null;
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if ($sheet->getCell("A{$row}")->getValue() === 'Члены комиссии:') {
                $commissionHeaderRow = $row;
                break;
            }
        }

        $this->assertNotNull($commissionHeaderRow);
        $this->assertSame(
            '- ____________________ / Борис Сидоров /',
            $sheet->getCell('A'.($commissionHeaderRow + 1))->getValue()
        );
        $this->assertSame(
            '- ____________________ / Анна Петрова /',
            $sheet->getCell('A'.($commissionHeaderRow + 2))->getValue()
        );
        $documentText = '';
        $signatureRows = [];
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $cellValue = (string) $sheet->getCell("A{$row}")->getValue();
            $documentText .= ' '.$cellValue;
            if (str_starts_with($cellValue, '- ____________________ /')) {
                $signatureRows[] = $cellValue;
            }
        }

        $this->assertSame([
            '- ____________________ / Борис Сидоров /',
            '- ____________________ / Анна Петрова /',
        ], $signatureRows);
        $this->assertStringNotContainsString('Чужой Пользователь', $documentText);
        $this->assertStringNotContainsString("/ {$employee->id} /", $documentText);
        $this->assertStringNotContainsString("/ {$foreignContext->user->id} /", $documentText);
        $this->assertStringNotContainsString("/ {$context->user->id} /", $documentText);
    }
}
