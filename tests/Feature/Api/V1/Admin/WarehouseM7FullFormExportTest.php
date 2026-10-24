<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Enums\WarehouseReceiptVatMode;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M7\M7ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseMovementFormExportService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptTaxCalculator;
use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseM7FullFormExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_m7_contains_all_four_form_sections_and_twenty_material_columns(): void
    {
        $movements = $this->fixture();
        [, $book] = $this->export($movements);
        self::assertSame(4, $book->getSheetCount());
        foreach ($book->getAllSheets() as $index => $sheet) {
            self::assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
            self::assertSame($index === 2 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT, $sheet->getPageSetup()->getOrientation());
            self::assertStringContainsString('УЧ-М7-ПРОВЕРКА', (string) $sheet->getCell('A1')->getValue());
            self::assertSame($index === 2 ? [8, 11] : [1, 2], array_map('intval', $sheet->getPageSetup()->getRowsToRepeatAtTop()));
        }
        $all = implode(' ', array_map(static fn ($sheet): string => implode(' ', array_map(static fn (array $row): string => implode(' ', $row), $sheet->toArray())), $book->getAllSheets()));
        foreach (['УТВЕРЖДАЮ', 'Отправитель', 'Поставщик', 'Получатель', 'Страховая компания', 'пломбирован', 'Масса груза',
            'Условия хранения', 'Состояние тары', 'Количество недостающей продукции определено', 'Другие данные',
            'Корреспондирующий счет', 'Брак и бой', 'Недостачи', 'Излишки', 'Номер паспорта', 'картотеке',
            'Заключение комиссии', 'Перечень прилагаемых документов', 'полномочиях', 'Заведующий складом'] as $label) {
            self::assertStringContainsString($label, $all);
        }
        $table = $book->getSheet(2);
        foreach (range(1, 20) as $column) {
            self::assertSame($column, $table->getCell([$column, 11])->getValue());
        }
        self::assertSame('UCH-M7-MATERIAL', $table->getCell('B12')->getValue());
        self::assertSame('л', $table->getCell('D12')->getValue());
        foreach (['E12', 'G12', 'H12', 'I12', 'M12', 'N12', 'O12', 'Q12', 'S12', 'T12'] as $cell) {
            self::assertNull($table->getCell($cell)->getValue(), $cell);
        }
        self::assertStringNotContainsString('Сделано в МОСТ', $all);
    }

    public function test_m7_reads_nested_source_quantity_and_preserves_zero_and_exact_discrepancies(): void
    {
        $movements = $this->fixture();
        $movement = $movements->first();
        foreach ([['0.000', null, '1.250'], ['1.000', null, '0.250'], ['1.250', null, null], ['2.000', '0.750', null]] as [$quantity, $shortage, $surplus]) {
            $movement->metadata = ['metadata' => ['supplier_quantity' => $quantity, 'supplier_name' => 'Поставщик из формы', 'invoice_number' => 'УЧ-СОПР-7']];
            [, $book] = $this->export($movements);
            $sheet = $book->getSheet(2);
            self::assertSame((float) $quantity, (float) $sheet->getCell('F12')->getValue());
            foreach (['P12' => $shortage, 'R12' => $surplus] as $cell => $expected) {
                self::assertSame($expected === null ? null : (float) $expected, $expected === null ? $sheet->getCell($cell)->getValue() : (float) $sheet->getCell($cell)->getValue());
            }
            $first = implode(' ', array_map(static fn (array $row): string => implode(' ', $row), $book->getSheet(0)->toArray()));
            self::assertStringContainsString('Поставщик из формы', $first);
            self::assertStringContainsString('УЧ-СОПР-7', $first);
        }
        $movement->quantity = '999999999999.999';
        $movement->metadata = ['supplier_quantity' => '999999999999.998'];
        [, $book] = $this->export($movements);
        self::assertSame('999 999 999 999,999', $book->getSheet(2)->getCell('J12')->getValue());
        self::assertSame(0.001, (float) $book->getSheet(2)->getCell('R12')->getValue());
        self::assertSame('12 199 999 999 999,99', $book->getSheet(2)->getCell('L12')->getValue());
        foreach (['F12', 'J12', 'L12'] as $cell) {
            self::assertFalse($book->getSheet(2)->getStyle($cell)->getAlignment()->getShrinkToFit());
            self::assertTrue($book->getSheet(2)->getStyle($cell)->getAlignment()->getWrapText());
        }
    }

    public function test_m7_unknown_document_quantity_never_becomes_a_reported_shortage_or_surplus(): void
    {
        $movements = $this->fixture();
        foreach ([null, '', 'неизвестно', -1, true, [], '1e309', '0.0001'] as $unknown) {
            $movements->first()->metadata = ['supplier_quantity' => $unknown];
            [, $book] = $this->export($movements);
            foreach (['F12', 'P12', 'Q12', 'R12', 'S12'] as $cell) {
                self::assertNull($book->getSheet(2)->getCell($cell)->getValue(), $cell);
            }
            self::assertStringContainsString('Налоговый режим не указан', (string) $book->getSheet(2)->getCell('A12')->getValue());
        }
    }

    public function test_m7_uses_business_date_in_all_sections_and_unnumbered_filename(): void
    {
        $movements = $this->fixture();
        $movement = $movements->first();
        $movement->organization->update(['multi_org_settings' => ['default_timezone' => 'Europe/Moscow']]);
        $movement->document_number = null;
        $movement->movement_date = '2026-10-31 22:30:00';
        [$path, $book] = $this->export($movements);
        self::assertStringEndsWith('20261101_013000.xlsx', $path);
        foreach ($book->getAllSheets() as $sheet) {
            self::assertStringContainsString('01.11.2026', (string) $sheet->getCell('A1')->getValue());
        }
    }

    public function test_m7_long_requisites_and_many_lines_keep_identity_and_readable_print_scale(): void
    {
        $movements = $this->fixture();
        $movements->first()->material->name = str_repeat('Материал для учебной проверки длинного наименования ', 4);
        $movements->first()->warehouse->name = str_repeat('Учебный склад материалов ', 5);
        $movements->first()->metadata = ['metadata' => ['supplier_name' => str_repeat('Учебный поставщик ', 8), 'invoice_number' => str_repeat('УЧ-СОПР-', 10), 'supplier_quantity' => '1.500']];
        foreach (range(2, 24) as $index) {
            $line = clone $movements->first();
            $line->id = $index;
            $movements->push($line);
        }
        [, $book] = $this->export($movements);
        $sheet = $book->getSheet(2);
        self::assertSame('A1:T35', $sheet->getPageSetup()->getPrintArea());
        self::assertGreaterThanOrEqual(70, $sheet->getPageSetup()->getScale());
        self::assertFalse($sheet->getPageSetup()->getFitToPage());
        self::assertNotEmpty($sheet->getBreaks());
        self::assertGreaterThan(60, $sheet->getRowDimension(12)->getRowHeight());
        self::assertStringContainsString('Материал для учебной', (string) $sheet->getCell('A35')->getValue());
    }

    public function test_m7_different_receipts_never_overwrite_a_prepared_file(): void
    {
        $first = $this->fixture();
        $first->first()->metadata = ['supplier_name' => 'Поставщик А'];
        [$firstPath] = $this->export($first);
        $firstBytes = Storage::disk('s3')->get($firstPath);
        $second = $first->first()->replicate();
        $second->metadata = ['supplier_name' => 'Поставщик Б'];
        $second->save();
        [$secondPath] = $this->export(new Collection([$second]));
        self::assertNotSame($firstPath, $secondPath);
        self::assertSame($firstBytes, Storage::disk('s3')->get($firstPath));
    }

    public function test_m7_tax_meaning_requires_a_valid_server_snapshot_and_text_never_becomes_a_formula(): void
    {
        $movements = $this->fixture();
        $movement = $movements->first();
        $snapshot = (new WarehouseReceiptTaxCalculator)->calculate('1.250', '12.20', WarehouseReceiptVatMode::Included, '22.00')->toArray();
        $movement->metadata = ['receipt_tax' => $snapshot, 'supplier_name' => '=1+1'];
        $movement->material->code = '=1+1';
        [, $book] = $this->export($movements);
        self::assertStringContainsString('Цена включает НДС 22,00%', (string) $book->getSheet(2)->getCell('A12')->getValue());
        self::assertSame('15,25', $book->getSheet(2)->getCell('L12')->getValue());
        self::assertSame('s', $book->getSheet(2)->getCell('B12')->getDataType());
        $movement->metadata = ['metadata' => ['receipt_tax' => $snapshot]];
        [, $book] = $this->export($movements);
        self::assertStringContainsString('Налоговый режим не указан', (string) $book->getSheet(2)->getCell('A12')->getValue());
        $snapshot['vat_amount'] = '0.01';
        $movement->metadata = ['receipt_tax' => $snapshot];
        [, $book] = $this->export($movements);
        self::assertStringContainsString('Налоговый режим не указан', (string) $book->getSheet(2)->getCell('A12')->getValue());
    }

    public function test_m7_export_groups_only_the_same_supplier_document_and_local_business_day(): void
    {
        $movements = $this->fixture();
        $selected = $movements->first();
        $selected->organization->update(['multi_org_settings' => ['default_timezone' => 'Europe/Moscow']]);
        $identity = ['supplier_name' => 'Учебный поставщик А', 'invoice_number' => 'УЧ-СОПР-А'];
        $selected->update(['movement_date' => '2026-10-31 22:30:00', 'metadata' => $identity]);
        foreach ([
            ['2026-11-01 12:00:00', '2.250', $identity],
            ['2026-10-31 20:59:59', '10.000', $identity],
            ['2026-11-01 21:00:00', '20.000', $identity],
            ['2026-11-01 13:00:00', '30.000', ['supplier_name' => 'Другой поставщик', 'invoice_number' => 'УЧ-СОПР-А']],
            ['2026-11-01 13:00:00', '40.000', ['supplier_name' => 'Учебный поставщик А', 'invoice_number' => 'Другой документ']],
            ['2026-11-01 13:00:00', '3.250', ['metadata' => $identity]],
        ] as [$date, $quantity, $metadata]) {
            $line = $selected->replicate();
            $line->fill(['movement_date' => $date, 'quantity' => $quantity, 'metadata' => $metadata])->save();
        }
        $url = app(WarehouseMovementFormExportService::class)->temporaryUrl('m7', $selected->id, $selected->organization_id);
        $path = rawurldecode(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));
        $temporary = tempnam(sys_get_temp_dir(), 'm7_group_');
        try {
            file_put_contents($temporary, Storage::disk('s3')->get($path));
            $book = IOFactory::load($temporary);
            $sheet = $book->getSheet(2);
            self::assertStringContainsString('01.11.2026', (string) $sheet->getCell('A8')->getValue());
            self::assertSame(14, $sheet->getHighestRow());
            foreach (['J12' => 1.25, 'J13' => 2.25, 'J14' => 3.25] as $cell => $expected) {
                self::assertSame($expected, (float) $sheet->getCell($cell)->getValue());
            }
        } finally {
            unlink($temporary);
        }
    }

    private function fixture(): Collection
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $context->organization->id, 'short_name' => 'л'], ['name' => 'Литр', 'type' => 'material', 'is_system' => false]);
        $material = Material::query()->create(['organization_id' => $context->organization->id, 'name' => 'Учебная грунтовка М-7', 'code' => 'UCH-M7-MATERIAL', 'measurement_unit_id' => $unit->id, 'is_active' => true]);
        $warehouse = OrganizationWarehouse::query()->create(['organization_id' => $context->organization->id, 'name' => 'Учебный склад М-7', 'code' => 'UCH-M7', 'warehouse_type' => 'project', 'is_main' => false, 'is_active' => true]);

        return new Collection([WarehouseMovement::query()->create(['organization_id' => $context->organization->id, 'warehouse_id' => $warehouse->id, 'material_id' => $material->id, 'user_id' => $context->user->id, 'movement_type' => 'receipt', 'quantity' => '1.250', 'price' => '12.20', 'document_number' => 'УЧ-М7-ПРОВЕРКА', 'movement_date' => '2026-10-24 00:30:00', 'metadata' => []])]);
    }

    private function export(Collection $movements): array
    {
        $path = app(M7ExportStrategy::class)->export($movements);
        $content = Storage::disk('s3')->get($path);
        if ($directory = getenv('MOST_WAREHOUSE_PRINT_QA_DIR')) {
            $target = $directory.'/'.pathinfo($path, PATHINFO_FILENAME).'-'.substr(hash('sha256', $content), 0, 12).'.xlsx';
            if (! file_exists($target)) {
                file_put_contents($target, $content);
            }
        }
        $temporary = tempnam(sys_get_temp_dir(), 'm7_full_form_');
        try {
            file_put_contents($temporary, $content);

            return [$path, IOFactory::load($temporary)];
        } finally {
            unlink($temporary);
        }
    }
}
