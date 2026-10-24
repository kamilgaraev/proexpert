<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\INV3\INV3ExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseINV3CompletenessExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_uses_saved_prices_and_contains_manual_requisites_without_assigning_roles(): void
    {
        $workbook = $this->exportWorkbook();
        $table = $workbook->getSheetByName('Опись 1');
        $this->assertNotNull($table);
        $this->assertEquals(123.45, $table->getCell('F9')->getValue());
        $this->assertEquals(2.333, $table->getCell('G9')->getValue());
        $this->assertEquals(288.01, $table->getCell('H9')->getValue());
        $this->assertEquals(2.501, $table->getCell('I9')->getValue());
        $this->assertEquals(308.75, $table->getCell('J9')->getValue());
        $this->assertStringContainsString('INV3-SNAPSHOT', (string) $table->getCell('B9')->getValue());
        $this->assertStringContainsString('ПАРТИЯ-001', (string) $table->getCell('K9')->getValue());
        $text = $this->documentText($workbook);
        foreach (['Основание проведения', 'РАСПИСКА ДО НАЧАЛА', 'Количество порядковых номеров прописью: Один', 'Две целых триста тридцать три тысячных', 'ОТМЕТКА ПРОВЕРКИ ЦЕН И ИТОГОВ', 'РАСПИСКА МАТЕРИАЛЬНО ОТВЕТСТВЕННОГО ЛИЦА', 'Подпись ________________', 'Учебный акт без реального пересчёта и подписей.'] as $required) {
            $this->assertStringContainsString($required, $text);
        }
        $this->assertStringNotContainsString('Унифицированная форма', $text);
        $this->assertStringNotContainsString('ФИО Автор Учебный', $text);
    }

    public function test_every_table_page_has_its_own_written_totals_and_global_numbering(): void
    {
        $workbook = $this->exportWorkbook(count: 12);
        $tables = array_values(array_filter($workbook->getAllSheets(), static fn ($sheet) => str_starts_with($sheet->getTitle(), 'Опись ')));
        $this->assertGreaterThan(1, count($tables));
        $numbers = [];
        foreach ($tables as $sheet) {
            $text = implode(' ', array_map(static fn ($row) => implode(' ', array_map('strval', $row)), $sheet->toArray()));
            $this->assertStringContainsString('Количество порядковых номеров прописью:', $text);
            $this->assertStringContainsString('независимо от единиц измерения', $text);
            $this->assertSame(1, $sheet->getPageSetup()->getFitToHeight());
            foreach ($sheet->toArray() as $row) {
                if (is_numeric($row[0])) {
                    $numbers[] = (int) $row[0];
                }
            }
        }
        $this->assertSame(range(1, 12), $numbers);
        $this->assertStringContainsString('Всего по факту, руб.: 3456,12', $this->documentText($workbook));
        foreach ($workbook->getAllSheets() as $index => $sheet) {
            $this->assertSame('Лист '.($index + 1).' из '.$workbook->getSheetCount(), $sheet->getCell('A'.$sheet->getHighestRow())->getValue());
        }
    }

    public function test_missing_count_is_blank_and_zero_price_remains_numeric_zero(): void
    {
        $workbook = $this->exportWorkbook(actual: null, price: '0.00');
        $table = $workbook->getSheetByName('Опись 1');
        $this->assertNotNull($table);
        $this->assertEquals(0, $table->getCell('F9')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $table->getCell('F9')->getDataType());
        $this->assertNull($table->getCell('G9')->getValue());
        $this->assertNull($table->getCell('H9')->getValue());
        $this->assertEquals(0, $table->getCell('J9')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $table->getCell('J9')->getDataType());
        $this->assertStringContainsString('не определено: пересчёт не внесён для всех строк', $this->documentText($workbook));
    }

    public function test_text_fields_cannot_become_spreadsheet_formulas(): void
    {
        $workbook = $this->exportWorkbook(name: '=1+1');
        $cell = $workbook->getSheetByName('Опись 1')?->getCell('B9');
        $this->assertNotNull($cell);
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame("=1+1\nINV3-SNAPSHOT", $cell->getValue());
    }

    public function test_missing_fact_explanations_use_the_application_translation_catalog(): void
    {
        app()->setLocale('ru');
        app('translator')->get('warehouse_basic.inv3.cover_requisites_note', [], 'ru');
        app('translator')->addLines([
            'warehouse_basic.inv3.page_units_and_fact_note' => 'Перевод пояснения табличного листа',
            'warehouse_basic.inv3.actual_total_fact_or_price_missing' => 'Перевод отсутствующего итога',
            'warehouse_basic.inv3.quantity_words_missing_facts' => 'Перевод отсутствующего пересчёта',
        ], 'ru');
        $text = $this->documentText($this->exportWorkbook(actual: null));
        $this->assertStringContainsString('Перевод пояснения табличного листа', $text);
        $this->assertStringContainsString('Перевод отсутствующего итога', $text);
        $this->assertStringContainsString('Перевод отсутствующего пересчёта', $text);
        $this->assertStringNotContainsString('warehouse_basic.inv3.', $text);
    }

    public function test_amount_beyond_excel_numeric_precision_is_preserved_as_exact_text(): void
    {
        $workbook = $this->exportWorkbook(price: '9999999999999.99');
        $cell = $workbook->getSheetByName('Опись 1')?->getCell('H9');
        $this->assertNotNull($cell);
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('23 329 999 999 999,98', $cell->getValue());
        $this->assertSame('9 999 999 999 999,99', $workbook->getSheetByName('Опись 1')?->getCell('F9')->getValue());
    }

    public function test_long_notes_and_commission_members_continue_on_identified_readable_pages(): void
    {
        $notes = implode("\n", array_map(static fn ($i) => 'Примечание номер '.$i, range(1, 20)));
        $workbook = $this->exportWorkbook(notes: $notes, commissionCount: 9);
        $text = $this->documentText($workbook);
        $this->assertNotNull($workbook->getSheetByName('Примечания 1'));
        $this->assertNotNull($workbook->getSheetByName('Комиссия 1'));
        foreach (range(1, 20) as $number) {
            $this->assertStringContainsString('Примечание номер '.$number, $text);
        }
        $this->assertSame(9, substr_count($text, '/ Участник Учебный /'));
        $this->assertSame('Итоги и подписи', $workbook->getAllSheets()[array_key_last($workbook->getAllSheets())]->getTitle());
        foreach ($workbook->getAllSheets() as $sheet) {
            $height = 0;
            foreach (range(1, $sheet->getHighestRow()) as $row) {
                $height += max(15, $sheet->getRowDimension($row)->getRowHeight());
            }
            $this->assertLessThan(570, $height, $sheet->getTitle());
        }
    }

    private function documentText(Spreadsheet $workbook): string
    {
        return implode(' ', array_map(static fn ($sheet) => implode(' ', array_map(static fn ($row) => implode(' ', array_map('strval', $row)), $sheet->toArray())), $workbook->getAllSheets()));
    }

    public function test_long_item_notes_are_kept_in_an_annex_without_shrinking_the_inventory_table(): void
    {
        $notes = str_repeat('Примечание строки без настоящего пересчёта. ', 40);
        $workbook = $this->exportWorkbook(itemNotes: $notes);
        $table = $workbook->getSheetByName('Опись 1');
        $annex = $workbook->getSheetByName('ТМЦ 1 прим. 1');
        $this->assertNotNull($table);
        $this->assertNotNull($annex);
        $this->assertLessThan(120, $table->getRowDimension(9)->getRowHeight());
        $this->assertStringContainsString('ТМЦ 1 прим.', (string) $table->getCell('K9')->getValue());
        $parts = [];
        for ($row = 3; $row < $annex->getHighestRow(); $row++) {
            $parts[] = (string) $annex->getCell('A'.$row)->getValue();
        }
        $this->assertStringContainsString($notes, implode('', $parts));
        foreach (array_slice($parts, 2) as $part) {
            if ($part !== '') {
                $this->assertStringEndsWith(' ', $part);
            }
        }
    }

    private function exportWorkbook(int $count = 1, ?string $actual = '2.333', string $price = '123.45', string $name = 'Материал со снимком учётной цены', string $notes = 'Учебный акт без реального пересчёта и подписей.', int $commissionCount = 0, string $itemNotes = 'Учебная строка без фактического пересчёта.'): Spreadsheet
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create(['name' => 'Автор Учебный']);
        $unit = MeasurementUnit::query()->create(['organization_id' => $context->organization->id, 'name' => 'Килограмм', 'short_name' => 'кг-инв3-полный', 'type' => 'material', 'is_default' => false, 'is_system' => false]);
        $material = Material::query()->create(['organization_id' => $context->organization->id, 'name' => $name, 'code' => 'INV3-SNAPSHOT', 'measurement_unit_id' => $unit->id, 'default_price' => 999, 'is_active' => true]);
        $warehouse = OrganizationWarehouse::query()->create(['organization_id' => $context->organization->id, 'name' => 'Учебный склад', 'code' => 'INV3-FULL', 'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL, 'is_main' => false, 'is_active' => true, 'responsible_user_id' => $context->user->id]);
        $members = [];
        for ($i = 0; $i < $commissionCount; $i++) {
            $user = User::factory()->create(['name' => 'Участник Учебный', 'current_organization_id' => $context->organization->id]);
            $context->organization->users()->attach($user->id, ['is_owner' => false, 'is_active' => true, 'settings' => null]);
            $members[] = $user->id;
        }
        $act = InventoryAct::query()->create(['organization_id' => $context->organization->id, 'warehouse_id' => $warehouse->id, 'act_number' => 'INV-REQUIRED-20261024', 'status' => InventoryAct::STATUS_DRAFT, 'inventory_date' => '2026-10-24', 'created_by' => $context->user->id, 'approved_by' => null, 'commission_members' => $members, 'notes' => $notes]);
        for ($i = 0; $i < $count; $i++) {
            InventoryActItem::query()->create(['inventory_act_id' => $act->id, 'material_id' => $material->id, 'expected_quantity' => '2.501', 'actual_quantity' => $actual, 'difference' => $actual === null ? 0 : '-0.168', 'unit_price' => $price, 'total_value' => 0, 'location_code' => 'ЯЧЕЙКА-01', 'batch_number' => 'ПАРТИЯ-'.sprintf('%03d', $i + 1), 'notes' => $itemNotes]);
        }
        $path = app(INV3ExportStrategy::class)->export($act->load(['organization', 'warehouse', 'items.material.measurementUnit']));
        $temporaryPath = tempnam(sys_get_temp_dir(), 'inv3_complete_');
        $this->assertNotFalse($temporaryPath);
        file_put_contents($temporaryPath, Storage::disk('s3')->get($path));
        try {
            return IOFactory::load($temporaryPath);
        } finally {
            unlink($temporaryPath);
        }
    }
}
