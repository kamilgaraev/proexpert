<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Enums\WarehouseReceiptVatMode;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptTaxCalculator;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M17\M17ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M4\M4ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M7\M7ExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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

    public function test_m4_contains_full_requisites_and_both_signature_blocks(): void
    {
        [, , $movements] = $this->fixture(false, 1);
        $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));
        $content = implode(' ', array_map(static fn (array $row): string => implode(' ', $row), $sheet->toArray()));
        foreach (['Структурное подразделение', 'Код вида операции', 'Страховая компания', 'Корреспондирующий счет',
            'сопроводительного', 'платежного', 'Номенклатурный номер', 'По документу', 'Принято',
            'Сумма НДС', 'Всего с учетом НДС', 'Номер паспорта', 'картотеке', 'Принял', 'Сдал',
            'должность', 'подпись', 'расшифровка подписи'] as $requisite) {
            $this->assertStringContainsString($requisite, $content);
        }
    }

    public function test_m4_does_not_claim_a_tax_exclusive_amount_without_saved_tax_context(): void
    {
        [$material, , $movements] = $this->fixture(false, 1);
        $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));
        $netColumn = null;
        $materialRow = null;
        foreach ($sheet->toArray() as $rowIndex => $values) {
            foreach ($values as $columnIndex => $value) {
                if (is_string($value) && preg_match('/Сумма без (?:учета )?НДС/u', str_replace(["\n", 'ё'], [' ', 'е'], $value))) {
                    $netColumn = $columnIndex + 1;
                }
                if (is_string($value) && str_starts_with($value, $material->name."\n")) $materialRow = $rowIndex + 1;
            }
        }
        $this->assertNotNull($netColumn);
        $this->assertNotNull($materialRow);
        $this->assertNull($sheet->getCell(Coordinate::stringFromColumnIndex($netColumn).$materialRow)->getValue());
    }

    #[DataProvider('printCases')]
    public function test_receipt_forms_print_complete_width_with_wrapped_requisites(bool $long, int $count): void
    {
        [$material, $warehouse, $movements] = $this->fixture($long, $count);

        foreach ([M7ExportStrategy::class => 17] as $strategy => $headerRow) {
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
            $this->assertSame(1.5, (float) $sheet->getCell('F18')->getValue());
            $this->assertSame(1.25, (float) $sheet->getCell('G18')->getValue());
            $this->assertSame(0.25, (float) $sheet->getCell('H18')->getValue());
        }
    }

    #[DataProvider('printCases')]
    public function test_m4_prints_twelve_columns_with_complete_identity_on_continuations(bool $long, int $count): void
    {
        [$material, $warehouse, $movements] = $this->fixture($long, $count);
        $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));
        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
        $this->assertSame(90, $sheet->getPageSetup()->getScale());
        $this->assertFalse($sheet->getPageSetup()->getFitToPage());
        $this->assertSame('A1:L'.(28 + $count), $sheet->getPageSetup()->getPrintArea());
        $this->assertSame([19, 22], array_map('intval', $sheet->getPageSetup()->getRowsToRepeatAtTop()));
        $this->assertSame('0315003', $sheet->getCell('K6')->getValue());
        $this->assertSame($movements->first()->document_number, $sheet->getCell('H11')->getValue());
        $this->assertStringContainsString($warehouse->code, $sheet->getCell('A19')->getValue());
        $this->assertStringStartsWith($material->name, $sheet->getCell('A23')->getValue());
        $this->assertSame($material->code, $sheet->getCell('B23')->getValue());
        $this->assertNull($sheet->getCell('C23')->getValue());
        $this->assertSame(1.5, (float) $sheet->getCell('E23')->getValue());
        $this->assertSame(1.25, (float) $sheet->getCell('F23')->getValue());
        $this->assertSame('n', $sheet->getCell('F23')->getDataType());
        $this->assertSame('100,00', $sheet->getCell('G23')->getValue());
        $this->assertFalse($sheet->getStyle('G23:J23')->getAlignment()->getWrapText());
        $this->assertTrue($sheet->getStyle('G23:J23')->getAlignment()->getShrinkToFit());
        $this->assertNull($sheet->getCell('H23')->getValue());
        $this->assertSame('Принял', $sheet->getCell('A'.(24 + $count))->getValue());
        $this->assertSame('Сдал', $sheet->getCell('A'.(27 + $count))->getValue());
        $this->assertStringContainsString('Страница &P из &N', $sheet->getHeaderFooter()->getOddFooter());
        $this->assertArrayNotHasKey('A22', $sheet->getBreaks());
        if ($count === 1) $this->assertSame([], $sheet->getBreaks());
        foreach (array_keys($sheet->getBreaks()) as $coordinate) {
            [, $breakRow] = Coordinate::coordinateFromString($coordinate);
            $this->assertLessThan(22 + $count, (int) $breakRow);
        }
        if ($long) {
            $this->assertGreaterThan(18, $sheet->getRowDimension(11)->getRowHeight());
            $this->assertGreaterThan(30, $sheet->getRowDimension(23)->getRowHeight());
        }
    }

    public function test_m4_uses_only_a_matching_server_tax_snapshot_and_keeps_entered_price_explicit(): void
    {
        [, , $movements] = $this->fixture(false, 1);
        $movement = $movements->first();
        $movement->quantity = '2.345';
        $movement->price = '12.34';
        foreach ([['included', '24,12', '4,82', '28,94'], ['on_top', '28,94', '5,79', '34,73'], ['none', '28,94', 'Без НДС', '28,94']] as [$mode, $net, $vat, $gross]) {
            $tax = (new WarehouseReceiptTaxCalculator)->calculate('2.345', '12.34', WarehouseReceiptVatMode::from($mode), $mode === 'none' ? null : '20')->toArray();
            $movement->metadata = ['receipt_tax' => $tax];
            $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));
            $this->assertSame('12,34', $sheet->getCell('G23')->getValue());
            $this->assertSame($net, $sheet->getCell('H23')->getValue());
            $this->assertSame($vat, $sheet->getCell('I23')->getValue());
            $this->assertSame($gross, $sheet->getCell('J23')->getValue());
            $this->assertStringNotContainsString('Налоговый режим не указан', $sheet->getCell('A23')->getValue());
        }
        $movement->metadata = ['receipt_tax' => array_replace($tax, ['net_amount' => '999.99'])];
        $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));
        foreach (['H23', 'I23', 'J23'] as $coordinate) $this->assertNull($sheet->getCell($coordinate)->getValue());
        $this->assertStringContainsString('Налоговый режим не указан', $sheet->getCell('A23')->getValue());
        foreach ([['1.000', '0.00', '0', '0,00', '0,00', '0,00'], ['1.000', '0.03', '20', '0,02', '0,01', '0,03'], ['1.000', '9999999999999.99', '20', '8 333 333 333 333,32', '1 666 666 666 666,67', '9 999 999 999 999,99']] as [$quantity, $price, $rate, $net, $vat, $gross]) {
            $movement->quantity = $quantity;
            $movement->price = $price;
            $movement->metadata = ['receipt_tax' => (new WarehouseReceiptTaxCalculator)->calculate($quantity, $price, WarehouseReceiptVatMode::Included, $rate)->toArray()];
            $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));
            $this->assertSame($net, $sheet->getCell('H23')->getValue());
            $this->assertSame($vat, $sheet->getCell('I23')->getValue());
            $this->assertSame($gross, $sheet->getCell('J23')->getValue());
        }
        $movement->quantity = '999999999999.999';
        $movement->price = '0.00';
        $movement->metadata = ['receipt_tax' => (new WarehouseReceiptTaxCalculator)->calculate('999999999999.999', '0.00', WarehouseReceiptVatMode::None)->toArray()];
        $sheet = $this->sheet(app(M4ExportStrategy::class)->export($movements));
        $this->assertSame('999999999999,999', $sheet->getCell('F23')->getValue());
        $this->assertSame('s', $sheet->getCell('F23')->getDataType());
        $this->assertFalse($sheet->getStyle('F23')->getAlignment()->getWrapText());
        $this->assertTrue($sheet->getStyle('F23')->getAlignment()->getShrinkToFit());
    }

    #[DataProvider('printCases')]
    public function test_m17_wraps_long_warehouse_code_and_document_without_changing_balances(bool $long, int $count): void
    {
        [$material, $warehouse, $movements] = $this->fixture($long, $count);
        $sheet = $this->sheet(app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $warehouse, 'movements' => $movements,
        ]));

        $this->assertNull($sheet->getCell('C9')->getValue());
        $this->assertNull($sheet->getCell('A14')->getValue());
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

    public function test_m17_repeats_document_identity_and_page_numbers_on_continuations(): void
    {
        [$material, $warehouse, $movements] = $this->fixture(true, 45);
        $sheet = $this->sheet(app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $warehouse, 'movements' => $movements,
        ]));

        $this->assertSame([19, 25], array_map('intval', $sheet->getPageSetup()->getRowsToRepeatAtTop()));
        $this->assertStringContainsString($material->organization->legal_name ?: $material->organization->name, $sheet->getCell('A19')->getValue());
        $this->assertStringContainsString($material->name, $sheet->getCell('A20')->getValue());
        $this->assertStringContainsString($material->code, $sheet->getCell('A20')->getValue());
        $this->assertStringContainsString($warehouse->name, $sheet->getCell('A21')->getValue());
        $this->assertStringContainsString($warehouse->code, $sheet->getCell('A21')->getValue());
        $this->assertStringContainsString('Страница &P из &N', $sheet->getHeaderFooter()->getOddFooter());
        $this->assertSame('Нач. ост:', $sheet->getCell('M26')->getValue());
        $this->assertSame(56.25, (float) $sheet->getCell('P73')->getValue());
    }

    public static function m17BusinessDateCases(): array
    {
        return [
            'next-business-day' => ['Europe/Moscow', '2026-10-31 22:30:00', '2026-11-01'],
            'previous-business-day' => ['Pacific/Honolulu', '2026-11-01 05:30:00', '2026-10-31'],
        ];
    }

    #[DataProvider('m17BusinessDateCases')]
    public function test_m4_uses_organization_business_date_without_changing_the_movement_instant(string $timezone, string $utc, string $expectedDate): void
    {
        [, , $movements] = $this->fixture(false, 1);
        $movement = $movements->first();
        $movement->loadMissing('organization');
        $movement->organization->forceFill(['multi_org_settings' => ['default_timezone' => $timezone]]);
        $movement->forceFill(['document_number' => null, 'movement_date' => Carbon::parse($utc, 'UTC')]);
        $before = $movement->movement_date->format('c');
        $path = app(M4ExportStrategy::class)->export($movements);
        $sheet = $this->sheet($path);
        $this->assertSame(Carbon::parse($expectedDate)->format('d.m.Y'), $sheet->getCell('J11')->getValue());
        $this->assertStringContainsString(Carbon::parse($expectedDate)->format('d.m.Y'), $sheet->getCell('A19')->getValue());
        $this->assertStringContainsString('_'.str_replace('-', '', $expectedDate).'_', $path);
        $this->assertSame($before, $movement->movement_date->format('c'));
    }

    #[DataProvider('m17BusinessDateCases')]
    public function test_m17_uses_one_organization_business_date_in_header_and_filename(
        string $timezone, string $utc, string $expectedDate,
    ): void {
        [$material, $warehouse, $movements] = $this->fixture(false, 1);
        $material->loadMissing('organization');
        $material->organization->forceFill(['multi_org_settings' => ['default_timezone' => $timezone]]);
        $this->travelTo(Carbon::parse($utc, 'UTC'));

        try {
            $path = app(M17ExportStrategy::class)->export([
                'material' => $material, 'warehouse' => $warehouse, 'movements' => $movements,
            ]);
            $sheet = $this->sheet($path);
            [$year, $month, $day] = explode('-', $expectedDate);

            $this->assertSame($day, (string) $sheet->getCell('Q6')->getValue());
            $this->assertSame($month, (string) $sheet->getCell('R6')->getValue());
            $this->assertSame($year, (string) $sheet->getCell('S6')->getValue());
            $this->assertStringEndsWith('_'.str_replace('-', '', $expectedDate).'.xlsx', $path);
        } finally {
            $this->travelBack();
        }
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

    public function test_m17_reservation_and_placement_do_not_change_physical_turnover(): void
    {
        [$material, $warehouse, $movements] = $this->fixture(false, 1);
        foreach ([
            ['reservation', null], ['unreservation', null],
            [WarehouseMovement::TYPE_TRANSFER_OUT, WarehouseMovement::CATEGORY_PLACEMENT],
            [WarehouseMovement::TYPE_TRANSFER_IN, WarehouseMovement::CATEGORY_PLACEMENT],
        ] as [$type, $category]) {
            $movements->push($movements->first()->replicate()->fill([
                'movement_type' => $type, 'operation_category' => $category, 'quantity' => 0.5,
            ]));
        }

        $sheet = $this->sheet(app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $warehouse, 'movements' => $movements,
        ]));

        $this->assertSame('Всего:', $sheet->getCell('A29')->getValue());
        $this->assertSame(1.25, (float) $sheet->getCell('N29')->getValue());
        $this->assertSame(0.0, (float) $sheet->getCell('O29')->getValue());
        $this->assertSame(1.25, (float) $sheet->getCell('P29')->getValue());
    }

    public function test_m17_counts_only_own_side_of_a_paired_warehouse_transfer(): void
    {
        [$material, $source, $movements] = $this->fixture(false, 1);
        $target = $source->replicate();
        $target->name = 'Другой учебный склад';
        $target->code = 'PRINT-TRANSFER-TARGET';
        $target->save();
        $outgoing = $movements->first()->replicate()->fill([
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT, 'quantity' => 0.25,
            'warehouse_id' => $source->id, 'to_warehouse_id' => $target->id,
        ]);
        $outgoing->save();
        $incoming = $outgoing->replicate()->fill([
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_IN,
            'warehouse_id' => $target->id, 'from_warehouse_id' => $source->id, 'to_warehouse_id' => null,
        ]);
        $incoming->save();
        $movements->push($outgoing, $incoming);

        $sourceSheet = $this->sheet(app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $source, 'movements' => $movements,
        ]));
        $this->assertSame('Всего:', $sourceSheet->getCell('A30')->getValue());
        $this->assertSame(1.0, (float) $sourceSheet->getCell('P30')->getValue());
        $this->assertSame(0.25, (float) $sourceSheet->getCell('O30')->getValue());

        $targetSheet = $this->sheet(app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $target, 'movements' => $movements,
        ]));
        $this->assertSame('Всего:', $targetSheet->getCell('A29')->getValue());
        $this->assertSame(0.25, (float) $targetSheet->getCell('P29')->getValue());
        $this->assertSame(0.0, (float) $targetSheet->getCell('O29')->getValue());
    }

    public static function m17AdjustmentCases(): array
    {
        return [
            'increase' => ['2.125', 3.375, 3.375, 0.0, 30],
            'decrease' => ['-0.125', 1.125, 1.25, 0.125, 30],
            'unchanged' => ['0', 1.25, 1.25, 0.0, 29],
        ];
    }

    #[DataProvider('m17AdjustmentCases')]
    public function test_m17_uses_explicit_signed_adjustment_delta(
        string $delta, float $balance, float $income, float $expense, int $totalRow,
    ): void {
        [$material, $warehouse, $movements] = $this->fixture(false, 1);
        $movements->push($movements->first()->replicate()->fill([
            'movement_type' => WarehouseMovement::TYPE_ADJUSTMENT,
            'quantity' => 99, 'metadata' => ['on_hand_delta' => $delta],
        ]));
        $sheet = $this->sheet(app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $warehouse, 'movements' => $movements,
        ]));

        $this->assertSame('Всего:', $sheet->getCell('A'.$totalRow)->getValue());
        $this->assertSame($balance, (float) $sheet->getCell('P'.$totalRow)->getValue());
        $this->assertSame($income, (float) $sheet->getCell('N'.$totalRow)->getValue());
        $this->assertSame($expense, (float) $sheet->getCell('O'.$totalRow)->getValue());
    }

    public function test_m17_rejects_adjustment_without_a_saved_physical_delta(): void
    {
        [$material, $warehouse, $movements] = $this->fixture(false, 1);
        $movements->push($movements->first()->replicate()->fill([
            'movement_type' => WarehouseMovement::TYPE_ADJUSTMENT,
            'quantity' => 99, 'metadata' => [],
        ]));
        $this->expectException(\UnexpectedValueException::class);

        app(M17ExportStrategy::class)->export([
            'material' => $material, 'warehouse' => $warehouse, 'movements' => $movements,
        ]);
    }

    public static function m4PriceCases(): array
    {
        return [
            'unknown' => [null, null, null],
            'known-zero' => ['0.00', '0,00', null],
            'known-positive' => ['100.00', '100,00', null],
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

        $this->assertSame($expectedPrice, $sheet->getCell('G23')->getValue());
        $this->assertSame($expectedAmount, $sheet->getCell('H23')->getValue());
        $this->assertSame(1.25, (float) $sheet->getCell('F23')->getValue());
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
            $content = Storage::disk('s3')->get($path);
            $target = $directory.'/'.pathinfo($path, PATHINFO_FILENAME).'-'.substr(hash('sha256', $content), 0, 12).'.xlsx';
            if (! file_exists($target)) file_put_contents($target, $content);
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
