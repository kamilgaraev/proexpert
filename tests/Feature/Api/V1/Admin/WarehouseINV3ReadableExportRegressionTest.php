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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseINV3ReadableExportRegressionTest extends TestCase
{
    use RefreshDatabase;

    // Regression: ISSUE-127 — обязательные реквизиты ИНВ-3 обрезались узкими одиночными ячейками
    // Found by /qa on 2026-09-01
    // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
    public function test_inv3_export_spans_mandatory_requisites_and_accounting_columns(): void
    {
        $sheet = $this->exportSheet('Анна Петрова');
        $mergedRanges = $sheet->getMergeCells();

        $this->assertContains('D10:F10', $mergedRanges);
        $this->assertContains('D11:F11', $mergedRanges);
        $this->assertContains('G10:I10', $mergedRanges);
        $this->assertContains('G11:I11', $mergedRanges);
        $this->assertContains('B15:D15', $mergedRanges);
        $this->assertContains('F15:G15', $mergedRanges);
        $this->assertContains('H15:I15', $mergedRanges);
        $this->assertContains('J15:L15', $mergedRanges);
        $this->assertContains('B16:D16', $mergedRanges);
        $this->assertContains('J16:L16', $mergedRanges);
        $this->assertSame('INV-20260901-0005', $sheet->getCell('D11')->getValue());
        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        $this->assertSame(0, $sheet->getPageSetup()->getFitToHeight());
        $this->assertGreaterThanOrEqual(18.0, $sheet->getColumnDimension('H')->getWidth());
    }

    // Regression: ISSUE-127 — официальный документ показывал технический логин вместо ФИО
    // Found by /qa on 2026-09-01
    // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
    public function test_inv3_export_replaces_login_like_commission_name_with_clear_missing_name_label(): void
    {
        $sheet = $this->exportSheet('kamilgaraev');
        $documentText = implode(' ', array_map(
            static fn (array $row): string => implode(' ', array_map('strval', $row)),
            $sheet->toArray()
        ));

        $this->assertStringContainsString('ФИО не указано (Директор)', $documentText);
        $this->assertStringNotContainsString('kamilgaraev', $documentText);
    }

    public function test_inv3_export_preserves_complete_name_written_in_latin_letters(): void
    {
        $sheet = $this->exportSheet('John Smith');
        $documentText = implode(' ', array_map(
            static fn (array $row): string => implode(' ', array_map('strval', $row)),
            $sheet->toArray()
        ));

        $this->assertStringContainsString('John Smith', $documentText);
        $this->assertStringNotContainsString('ФИО не указано', $documentText);
    }

    private function exportSheet(string $commissionMemberName): Worksheet
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create([
            'name' => $commissionMemberName,
            'position' => 'Директор',
        ]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Килограмм',
            'short_name' => 'кг-инв3',
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Арматура А500С, диаметр 12 мм',
            'code' => 'INV3-REBAR',
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Основной склад тестовой организации',
            'code' => 'INV3-MAIN',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $act = InventoryAct::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'act_number' => 'INV-20260901-0005',
            'status' => InventoryAct::STATUS_APPROVED,
            'inventory_date' => '2026-09-01',
            'created_by' => $context->user->id,
            'approved_by' => $context->user->id,
            'commission_members' => [$context->user->id],
        ]);
        InventoryActItem::query()->create([
            'inventory_act_id' => $act->id,
            'material_id' => $material->id,
            'expected_quantity' => 600,
            'actual_quantity' => 600,
            'difference' => 0,
            'unit_price' => 100,
            'total_value' => 0,
            'location_code' => 'A-01',
            'batch_number' => 'INV3-001',
        ]);

        $path = app(INV3ExportStrategy::class)->export(
            $act->load(['organization', 'warehouse', 'items.material.measurementUnit'])
        );
        $temporaryPath = tempnam(sys_get_temp_dir(), 'inv3_').'.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get($path));

        try {
            return IOFactory::load($temporaryPath)->getActiveSheet();
        } finally {
            @unlink($temporaryPath);
        }
    }
}
