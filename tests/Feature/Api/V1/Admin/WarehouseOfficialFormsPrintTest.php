<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M17\M17ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M4\M4ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M7\M7ExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseOfficialFormsPrintTest extends TestCase
{
    use RefreshDatabase;

    public static function printCases(): array
    {
        return ['short' => [false, 1], 'long' => [true, 1], 'many' => [true, 45]];
    }

    #[DataProvider('printCases')]
    public function test_receipt_forms_print_complete_width_with_wrapped_requisites(bool $long, int $count): void
    {
        [$material, $warehouse, $movements] = $this->fixture($long, $count);

        foreach ([M4ExportStrategy::class => 16, M7ExportStrategy::class => 17] as $strategy => $headerRow) {
            $sheet = $this->sheet(app($strategy)->export($movements));
            $lastRow = $headerRow + $count + ($headerRow === 16 ? 2 : 3);

            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
            $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
            $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
            $this->assertSame(0, $sheet->getPageSetup()->getFitToHeight());
            $this->assertSame("A1:I{$lastRow}", $sheet->getPageSetup()->getPrintArea());
            $this->assertSame([$headerRow, $headerRow], array_map('intval', $sheet->getPageSetup()->getRowsToRepeatAtTop()));
            $this->assertNotEmpty($sheet->getCell('G1')->getValue());
            $this->assertNull($sheet->getCell('J1')->getValue());
            $this->assertSame($movements->first()->document_number, $sheet->getCell('H11')->getValue());
            $this->assertStringContainsString($warehouse->name, $sheet->getCell('A13')->getValue());
            $this->assertSame($material->name, $sheet->getCell('A'.($headerRow + 1))->getValue());
            $this->assertContains('A'.($headerRow + 1).':D'.($headerRow + 1), $sheet->getMergeCells());
            $this->assertNotEmpty($sheet->getCell('A'.$lastRow)->getValue());
            if ($long) {
                $this->assertGreaterThan(30, $sheet->getRowDimension(11)->getRowHeight());
                $this->assertGreaterThan(18, $sheet->getRowDimension($headerRow + 1)->getRowHeight());
            }
            if ($headerRow === 16) {
                $this->assertSame(1.25, (float) $sheet->getCell('F17')->getValue());
                $this->assertSame('100,00', $sheet->getCell('H17')->getValue());
                $this->assertSame('125,00', $sheet->getCell('I17')->getValue());
            } else {
                $this->assertSame(1.5, (float) $sheet->getCell('F18')->getValue());
                $this->assertSame(1.25, (float) $sheet->getCell('G18')->getValue());
                $this->assertSame(0.25, (float) $sheet->getCell('H18')->getValue());
            }
        }
    }

    #[DataProvider('printCases')]
    public function test_m17_wraps_long_warehouse_code_and_document_without_changing_balances(bool $long, int $count): void
    {
        [$material, $warehouse, $movements] = $this->fixture($long, $count);
        $sheet = $this->sheet(app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $warehouse, 'movements' => $movements,
        ]));

        $this->assertSame($warehouse->name, $sheet->getCell('E14')->getValue());
        $this->assertSame($material->code, $sheet->getCell('M14')->getValue());
        $this->assertSame($movements->first()->document_number, $sheet->getCell('B28')->getValue());
        if ($long) {
            $this->assertGreaterThan(16, $sheet->getRowDimension(14)->getRowHeight());
            $this->assertGreaterThan(16, $sheet->getRowDimension(28)->getRowHeight());
            $this->assertGreaterThanOrEqual(64, $sheet->getRowDimension(28)->getRowHeight());
        }
        $this->assertTrue($sheet->getStyle('B28')->getAlignment()->getWrapText());
        $this->assertSame(1.25, (float) $sheet->getCell('N28')->getValue());
        $this->assertSame(1.25 * $count, (float) $sheet->getCell('P'.(27 + $count))->getValue());
        $this->assertSame('Всего:', $sheet->getCell('A'.(28 + $count))->getValue());
        $this->assertSame(0, $sheet->getPageSetup()->getFitToHeight());
    }

    public function test_m7_leaves_document_quantity_and_discrepancies_blank_without_valid_source_data(): void
    {
        [, , $movements] = $this->fixture(false, 1);
        $movement = $movements->first();
        $metadataCases = [
            'missing' => [],
            'null' => ['supplier_quantity' => null],
            'empty' => ['supplier_quantity' => ''],
            'text' => ['supplier_quantity' => 'не указано'],
            'negative' => ['supplier_quantity' => -1],
            'array' => ['supplier_quantity' => [1]],
            'boolean' => ['supplier_quantity' => true],
            'infinite' => ['supplier_quantity' => '1e309'],
        ];

        foreach ($metadataCases as $case => $metadata) {
            $movement->metadata = $metadata;
            $sheet = $this->sheet(app(M7ExportStrategy::class)->export($movements));

            $this->assertNull($sheet->getCell('F18')->getValue(), $case);
            $this->assertSame(1.25, (float) $sheet->getCell('G18')->getValue(), $case);
            $this->assertEmpty($sheet->getCell('H18')->getValue(), $case);
            $this->assertEmpty($sheet->getCell('I18')->getValue(), $case);
        }
    }

    public static function m4PriceCases(): array
    {
        return [
            'unknown' => [null, null, null],
            'known-zero' => ['0.00', '0,00', '0,00'],
            'known-positive' => ['100.00', '100,00', '125,00'],
        ];
    }

    #[DataProvider('m4PriceCases')]
    public function test_m4_preserves_unknown_price_and_amount_instead_of_substituting_zero(
        ?string $price,
        ?string $expectedPrice,
        ?string $expectedAmount,
    ): void {
        [, , $movements] = $this->fixture(false, 1);
        $movement = $movements->first();
        $movement->price = $price;
        $movement->save();
        $movement->refresh();
        $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));

        $this->assertSame($expectedPrice, $sheet->getCell('H17')->getValue());
        $this->assertSame($expectedAmount, $sheet->getCell('I17')->getValue());
        $this->assertSame(1.25, (float) $sheet->getCell('F17')->getValue());
        $this->assertSame($price, $movement->fresh()->price);
    }

    public function test_m7_preserves_known_document_quantities_including_zero_and_calculates_differences(): void
    {
        [, , $movements] = $this->fixture(false, 1);
        $movement = $movements->first();
        $cases = [
            [0, 0.0, null, 1.25],
            ['0', 0.0, null, 1.25],
            ['1.00', 1.0, null, 0.25],
            [1.25, 1.25, null, null],
            [2, 2.0, 0.75, null],
        ];

        foreach ($cases as [$source, $quantity, $shortage, $surplus]) {
            $movement->metadata = ['supplier_quantity' => $source];
            $sheet = $this->sheet(app(M7ExportStrategy::class)->export($movements));

            $this->assertSame($quantity, (float) $sheet->getCell('F18')->getValue());
            $this->assertSame(1.25, (float) $sheet->getCell('G18')->getValue());
            foreach (['H18' => $shortage, 'I18' => $surplus] as $coordinate => $expected) {
                $value = $sheet->getCell($coordinate)->getValue();
                if ($expected === null) {
                    $this->assertEmpty($value);
                } else {
                    $this->assertSame($expected, (float) $value);
                }
            }
        }
    }

    private function fixture(bool $long, int $count): array
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id, 'name' => 'Литр',
            'short_name' => 'л-печать', 'type' => 'material', 'is_system' => false,
        ]);
        $suffix = ($long ? 'long' : 'short').'-'.$count;
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => $long ? str_repeat('Учебная грунтовка для внутренней отделки стен и потолка ', 3) : 'Учебная грунтовка',
            'code' => 'UCH-102-MATERIAL-PRINT-'.$suffix,
            'measurement_unit_id' => $unit->id, 'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => $long ? str_repeat('Учебный склад материалов для ремонта офиса ', 3) : 'Учебный склад',
            'code' => 'PRINT-'.$suffix, 'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT,
            'is_main' => false, 'is_active' => true,
        ]);
        $movements = new Collection;
        for ($i = 0; $i < $count; $i++) {
            $movements->push(WarehouseMovement::query()->create([
                'organization_id' => $context->organization->id, 'warehouse_id' => $warehouse->id,
                'material_id' => $material->id, 'user_id' => $context->user->id,
                'movement_type' => WarehouseMovement::TYPE_RECEIPT, 'quantity' => 1.25, 'price' => 100,
                'document_number' => ($long ? 'УЧ-ПРИХОД-102-ПРОВЕРКА-ПЕЧАТИ-ДОКУМЕНТА-' : 'УЧ-ПРИХОД-').$suffix,
                'movement_date' => now()->setDate(2026, 10, 22)->startOfDay()->addMinutes($i),
                'reason' => 'Учебная проверка печати без фактической поставки',
                'metadata' => ['supplier_name' => $long ? str_repeat('Учебный поставщик материалов ', 4) : 'Учебный поставщик', 'supplier_quantity' => 1.5],
            ]));
        }

        return [$material, $warehouse, $movements];
    }

    private function sheet(string $path): Worksheet
    {
        if ($directory = getenv('MOST_WAREHOUSE_PRINT_QA_DIR')) {
            file_put_contents($directory.'/'.basename($path), Storage::disk('s3')->get($path));
        }
        $temporaryPath = tempnam(sys_get_temp_dir(), 'warehouse_print_');
        try {
            file_put_contents($temporaryPath, Storage::disk('s3')->get($path));

            return IOFactory::load($temporaryPath)->getActiveSheet();
        } finally {
            unlink($temporaryPath);
        }
    }
}
