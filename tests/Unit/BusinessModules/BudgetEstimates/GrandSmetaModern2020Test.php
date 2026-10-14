<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\StatefulGrandSmetaProcessor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\GrandSmetaRuntimeBridge;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\Models\ImportSession;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GrandSmetaModern2020Test extends TestCase
{
    private mixed $previousApplication;
    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 4));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $app->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 4).'/lang'), 'ru'));
        $app->instance('log', new NullLogger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        Container::setInstance($this->previousContainer);
    }

    private function mapping(): array
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('J1', 'Сметная стоимость в базисном уровне цен');
        $sheet->setCellValue('M1', 'Индексы');
        $sheet->setCellValue('N1', 'Сметная стоимость в текущем уровне цен');
        $sheet->fromArray([1, 2, 3, null, null, 4, 5, 6, 7, 8, 9, 10, 11, 12], null, 'A3', true);
        $mapping = (new GrandSmetaHandler)->findHeaderAndMapping($sheet)['mapping'];
        $book->disconnectWorksheets();

        return $mapping;
    }

    public function test_modern_mapping_uses_index_eleven_and_position_amount_ten(): void
    {
        $mapping = $this->mapping();
        self::assertSame('M', $mapping['price_index']);
        self::assertSame('L', $mapping['base_total_price']);
        self::assertSame('K', $mapping['price_coefficient']);
        self::assertSame('I', $mapping['quantity']);
    }

    public function test_legacy_number_ten_is_current_price_when_index_header_is_on_number_nine(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('J1', 'Сметная стоимость в базисном уровне цен');
        $sheet->setCellValue('K1', 'Индексы');
        $sheet->setCellValue('L1', 'Стоимость в текущем уровне цен');
        $sheet->fromArray([1, 2, 3, null, null, 4, 5, 6, 7, 8, 9, 10, 11, 12], null, 'A3', true);
        $mapping = (new GrandSmetaHandler)->findHeaderAndMapping($sheet)['mapping'];
        self::assertSame('K', $mapping['price_index']);
        self::assertSame('L', $mapping['current_unit_price']);
        self::assertArrayNotHasKey('base_total_price', $mapping);
        $book->disconnectWorksheets();
    }

    public function test_legacy_footer_does_not_reduce_declared_position_charges_to_match_source(): void
    {
        $parser = $this->createMock(GrandSmetaParser::class);
        $parser->method('getStream')->willReturnCallback(function (): \Generator {
            yield new EstimateImportRowDTO(itemName: 'Работа', quantity: 1, currentTotalAmount: 125, overheadAmount: 20, profitAmount: 5);
        });
        $parser->method('getFooterData')->willReturn(['total_estimate_cost' => 115, 'overhead_cost' => 10, 'profit_cost' => 5]);
        $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, $parser, new GrandSmetaXMLParser);
        $preview = $bridge->preview(new ImportSession, 'source.xlsx', new ImportStructureResult('grandsmeta', metadata: ['header_confirmed' => true]));
        self::assertSame(125.0, $preview->totals['total_amount']);
        self::assertFalse($preview->validation['is_valid']);
    }

    public function test_declared_signed_totals_zero_and_repeated_numbers_preserve_physical_identity(): void
    {
        $processor = new StatefulGrandSmetaProcessor;
        $mapping = $this->mapping();
        foreach ([4 => 80, 5 => -20, 6 => 0] as $row => $amount) {
            $processor->processRow(['A' => 1, 'B' => 'MAT-1', 'C' => 'Материал', 'I' => 2, 'J' => 10, 'K' => 99, 'L' => 20, 'M' => 4, 'N' => $amount], $mapping, $row);
        }
        $result = $processor->getResult();
        self::assertCount(3, $result['items']);
        self::assertSame([80.0, -20.0, 0.0], array_map(fn ($row) => $row->currentTotalAmount, $result['items']));
        self::assertSame([40.0, -10.0, 0.0], array_map(fn ($row) => $row->currentUnitPrice, $result['items']));
        self::assertSame([4, 5, 6], array_map(fn ($row) => $row->rowNumber, $result['items']));
        self::assertSame(4.0, $result['items'][0]->priceIndex);
        self::assertSame(99.0, $result['items'][0]->priceCoefficient);
    }

    public function test_complete_components_exclude_machine_labor_subset_and_include_local_charges(): void
    {
        $processor = new StatefulGrandSmetaProcessor;
        $mapping = $this->mapping();
        $processor->processRow(['A' => 1, 'B' => 'ГЭСН-1', 'C' => 'Работа', 'I' => 2], $mapping, 4);
        foreach ([5 => ['ОТ', 200], 6 => ['ЭМ', 120], 7 => ['в т.ч. ОТм', 20], 8 => ['М', 90], 9 => ['НР 10%', 20], 10 => ['СП 5%', 10]] as $row => [$name, $amount]) {
            $processor->processRow(['C' => $name, 'N' => $amount], $mapping, $row);
        }
        $root = $processor->getResult()['items'][0];
        self::assertSame(440.0, $root->currentTotalAmount);
        self::assertSame(20.0, $root->overheadAmount);
        self::assertSame(10.0, $root->profitAmount);
        self::assertSame(205.0, $root->unitPrice);
        self::assertSame(220.0, $root->laborCost);
        self::assertTrue($root->rawData['financial_structure']['labor_cost_declared']);
        self::assertSame(220.0, $root->rawData['financial_structure']['effective_unit_price']);
    }

    public function test_missing_component_is_explicitly_unresolved_and_footer_separates_vat(): void
    {
        $processor = new StatefulGrandSmetaProcessor;
        $mapping = $this->mapping();
        $processor->processRow(['A' => 1, 'C' => 'Работа', 'I' => 2], $mapping, 4);
        $processor->processRow(['C' => 'ОТ', 'N' => 200], $mapping, 5);
        $processor->processRow(['C' => 'ЭМ', 'L' => 30], $mapping, 6);
        foreach ([7 => ['Итоги по смете:', null], 8 => ['Всего', 500], 9 => ['НДС22%', 110], 10 => ['ВСЕГО по смете', 610]] as $row => [$name, $amount]) {
            $processor->processRow(['C' => $name, 'N' => $amount], $mapping, $row);
        }
        $result = $processor->getResult();
        self::assertNull($result['items'][0]->currentTotalAmount);
        self::assertNotEmpty($result['errors']);
        self::assertSame(500.0, $result['footer']['total_estimate_cost']);
        self::assertSame(110.0, $result['footer']['vat_amount']);
        self::assertSame(22.0, $result['footer']['vat_rate']);
        self::assertSame(610.0, $result['footer']['total_with_vat']);
    }

    public function test_section_summaries_do_not_replace_position_charges_and_nonaccounted_amount_is_not_blanket_excluded(): void
    {
        $processor = new StatefulGrandSmetaProcessor;
        $mapping = $this->mapping();
        $processor->processRow(['A' => 1, 'C' => 'Работа', 'I' => 2, 'N' => 100], $mapping, 4);
        $processor->processRow(['A' => 'Н', 'C' => 'Материал без стоимости', 'I' => 1], $mapping, 5);
        $processor->processRow(['A' => 'Н', 'C' => 'Добавленный материал', 'I' => 1, 'N' => 10], $mapping, 6);
        $processor->processRow(['C' => 'НР 10%', 'N' => 20], $mapping, 7);
        $processor->processRow(['C' => 'Итоги по разделу 1'], $mapping, 8);
        $processor->processRow(['C' => 'Накладные расходы', 'N' => 999], $mapping, 9);
        $result = $processor->getResult();
        self::assertCount(3, $result['items']);
        self::assertSame(20.0, $result['items'][0]->overheadAmount);
        self::assertSame(40.0, $result['items'][0]->unitPrice);
        self::assertTrue($result['items'][1]->isNotAccounted);
        self::assertFalse($result['items'][2]->isNotAccounted);
    }

    public function test_partial_footer_groups_do_not_replace_overall_component_totals(): void
    {
        $processor = new StatefulGrandSmetaProcessor;
        $mapping = $this->mapping();
        $processor->processRow(['C' => 'Итоги по смете:'], $mapping, 4);
        $processor->processRow(['C' => 'Материалы', 'N' => 100], $mapping, 5);
        $processor->processRow(['C' => 'Накладные расходы', 'N' => 20], $mapping, 6);
        $processor->processRow(['C' => 'Монтажные работы', 'N' => 30], $mapping, 7);
        $processor->processRow(['C' => 'Материалы', 'N' => 10], $mapping, 8);
        $processor->processRow(['C' => 'Накладные расходы', 'N' => 5], $mapping, 9);
        self::assertSame(100.0, $processor->getResult()['footer']['materials_cost']);
        self::assertSame(20.0, $processor->getResult()['footer']['overhead_cost']);
    }

    public function test_preview_reconciles_independent_roots_and_stream_keeps_physical_parents(): void
    {
        $this->withWorkbook(false, 500, 110, function ($bridge, $path, $structure): void {
            $preview = $bridge->preview(new ImportSession, $path, $structure);
            self::assertTrue($preview->validation['is_valid']);
            self::assertTrue($bridge->validate(new ImportSession, $preview)->isValid());
            self::assertSame(500.0, $preview->totals['total_amount']);
            self::assertSame(610.0, $preview->metadata['footer']['total_with_vat']);
            self::assertSame(22.0, $preview->metadata['footer']['vat_rate']);
            $rows = iterator_to_array($bridge->streamRows(new ImportSession, $path, $structure));
            $roots = array_values(array_filter($rows, fn ($row) => !$row->isSubItem));
            self::assertSame([80.0, 440.0, -20.0, 0.0], array_map(fn ($row) => $row->currentTotalAmount, $roots));
            self::assertSame(['0:4', '0:5', '0:12', '0:13'], array_map(fn ($row) => $row->rawData['source_identity'], $roots));
            self::assertSame('0:5', $rows[2]->rawData['parent_source_identity']);
            self::assertSame('Расчет', $rows[2]->worksheetName);
        });
    }

    public function test_group_index_rounding_uses_proven_base_sum_and_never_final_footer_fitting(): void
    {
        foreach ([0.10, 0.11] as $groupBase) {
            $processor = new StatefulGrandSmetaProcessor;
            $mapping = $this->mapping();
            foreach ([4, 7] as $rowNumber) {
                $processor->processRow(['A' => 1, 'B' => 'ГЭСН-1', 'C' => 'Работа', 'I' => 1], $mapping, $rowNumber);
                $processor->processRow(['C' => 'ЭМ', 'L' => 0.05], $mapping, $rowNumber + 1);
                $processor->processRow(['C' => 'Итого по расценке', 'L' => 0.05], $mapping, $rowNumber + 2);
            }
            $processor->processRow(['C' => 'Итоги по смете:'], $mapping, 10);
            $processor->processRow(['C' => 'Строительные работы', 'N' => 0.11], $mapping, 11);
            $processor->processRow(['C' => 'Эксплуатация машин и механизмов', 'L' => $groupBase, 'M' => 1.1, 'N' => round($groupBase * 1.1, 2)], $mapping, 12);
            $processor->processRow(['C' => 'Всего', 'N' => 0.11], $mapping, 13);
            $result = $processor->getResult();
            $roots = array_values(array_filter($result['items'], fn ($row) => !$row->isSubItem));
            if ($groupBase === 0.10) {
                self::assertSame([], $result['errors']);
                self::assertSame([0.06, 0.05], array_map(fn ($row) => $row->currentTotalAmount, $roots));
                self::assertSame('0.055', $roots[0]->rawData['index_provenance']['machinery']['exact_amount']);
            } else {
                self::assertNotEmpty($result['errors']);
                self::assertNull($roots[0]->currentTotalAmount);
                self::assertNull($roots[1]->currentTotalAmount);
            }
        }
    }

    public function test_missing_components_or_control_mismatch_block_stream_before_first_row(): void
    {
        foreach ([[true, 500, 110], [false, 499, 110], [false, 500, 111]] as [$missing, $net, $vat]) {
            $this->withWorkbook($missing, $net, $vat, function ($bridge, $path, $structure): void {
                $preview = $bridge->preview(new ImportSession, $path, $structure);
                self::assertFalse($preview->validation['is_valid']);
                self::assertNotEmpty($preview->validation['errors']);
                $validation = $bridge->validate(new ImportSession, $preview);
                self::assertFalse($validation->isValid());
                self::assertSame($preview->validation['errors'], $validation->errors);
                $yielded = false;
                try {
                    foreach ($bridge->streamRows(new ImportSession, $path, $structure) as $row) {
                        $yielded = true;
                    }
                    self::fail('Invalid estimate must be blocked before persistence');
                } catch (\InvalidArgumentException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                    self::assertFalse($yielded);
                }
            });
        }
    }

    public function test_two_declared_groups_keep_their_individual_rounding(): void
    {
        $result = $this->twoGroupResult([0.06, 0.06]);
        $roots = array_values(array_filter($result['items'], fn ($row) => !$row->isSubItem));
        self::assertSame([], $result['errors']);
        self::assertSame([0.06, 0.06], array_map(fn ($row) => $row->currentTotalAmount, $roots));
        self::assertSame(0.12, array_sum(array_map(fn ($row) => $row->currentTotalAmount, $roots)));
    }

    public function test_group_errors_cannot_compensate_each_other_in_the_total(): void
    {
        $result = $this->twoGroupResult([0.05, 0.07]);
        $roots = array_values(array_filter($result['items'], fn ($row) => !$row->isSubItem));
        self::assertNotEmpty($result['errors']);
        self::assertSame([null, null], array_map(fn ($row) => $row->currentTotalAmount, $roots));
    }

    public function test_wrong_groups_matching_the_merged_rounding_are_rejected(): void
    {
        $result = $this->twoGroupResult([0.04, 0.07]);
        $roots = array_values(array_filter($result['items'], fn ($row) => !$row->isSubItem));
        self::assertNotEmpty($result['errors']);
        self::assertSame([null, null], array_map(fn ($row) => $row->currentTotalAmount, $roots));
    }

    private function twoGroupResult(array $currents): array
    {
        $processor = new StatefulGrandSmetaProcessor;
        $mapping = $this->mapping();
        foreach ([4, 7] as $rowNumber) {
            $processor->processRow(['A' => 1, 'B' => 'ГЭСН-1', 'C' => 'Работа', 'I' => 1], $mapping, $rowNumber);
            $processor->processRow(['C' => 'ЭМ', 'L' => 0.05], $mapping, $rowNumber + 1);
            $processor->processRow(['C' => 'Итого по расценке', 'L' => 0.05], $mapping, $rowNumber + 2);
        }
        $processor->processRow(['C' => 'Итоги по смете:'], $mapping, 10);
        foreach (['Строительные работы', 'Монтажные работы'] as $offset => $name) {
            $processor->processRow(['C' => $name, 'N' => $currents[$offset]], $mapping, 11 + $offset * 2);
            $processor->processRow(['C' => 'Эксплуатация машин и механизмов', 'L' => 0.05, 'M' => 1.1, 'N' => $currents[$offset]], $mapping, 12 + $offset * 2);
        }

        return $processor->getResult();
    }

    public function test_manual_mapping_cannot_disable_confirmed_modern_validation(): void
    {
        $this->withWorkbook(true, 500, 110, function ($bridge, $path, $structure): void {
            $mapping = $structure->columnMapping;
            unset($mapping['base_total_price']);
            $manual = new ImportStructureResult('grandsmeta', headerRow: $structure->headerRow, columnMapping: $mapping, metadata: $structure->metadata);
            $preview = $bridge->preview(new ImportSession, $path, $manual);
            self::assertFalse($preview->validation['is_valid']);
            self::assertNotEmpty($preview->metadata['source_errors']);
        });
    }

    public function test_revalidation_preserves_internal_warnings_and_errors(): void
    {
        $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, new GrandSmetaParser(new StatefulGrandSmetaProcessor), new GrandSmetaXMLParser);
        $preview = new ImportPreviewResult('grandsmeta', items: [['item_name' => 'Работа']], validation: [
            'errors' => ['Не подтверждена стоимость позиции'],
            'warnings' => ['Проверьте исходную строку'],
        ]);
        $validation = $bridge->validate(new ImportSession, $preview);
        self::assertFalse($validation->isValid());
        self::assertSame($preview->validation['errors'], $validation->errors);
        self::assertSame($preview->validation['warnings'], $validation->warnings);
    }

    public function test_export_inactive_rows_are_preserved_without_blocking_verified_group_indices(): void
    {
        $this->withInactiveWorkbook(true, true, true, function ($bridge, $path, $structure): void {
            $preview = $bridge->preview(new ImportSession, $path, $structure);
            self::assertTrue($preview->validation['is_valid'], json_encode($preview->validation));
            self::assertCount(6, $preview->items);
            self::assertSame(0.11, $preview->totals['total_amount']);
            $allRows = iterator_to_array($bridge->streamRows(new ImportSession, $path, $structure), false);
            $rows = array_values(array_filter($allRows, fn ($row) => !$row->isSection && !$row->isSubItem));
            self::assertSame([false, true, false], array_map(fn ($row) => $row->isNotAccounted, $rows));
            self::assertSame([0.06, 0.06, 0.05], array_map(fn ($row) => $row->currentTotalAmount, $rows));
            self::assertSame('grand_export_inactive', $rows[1]->rawData['source_accounting']['marker']);
            self::assertSame('group_largest_remainder_v1', $rows[0]->rawData['index_provenance']['machinery']['rounding_strategy']);
            self::assertSame('position_half_up_v1', $rows[1]->rawData['index_provenance']['machinery']['rounding_strategy']);
            $children = array_values(array_filter($allRows, fn ($row) => $row->isSubItem));
            self::assertSame([0.06, 0.06, 0.05], array_map(fn ($row) => $row->currentTotalAmount, $children));
            self::assertTrue($children[1]->isNotAccounted);
            self::assertSame('parent_indexed_component', $children[0]->rawData['total_source']);
        });
    }

    public function test_inactive_modern_root_does_not_add_source_charges_twice(): void
    {
        $row = new EstimateImportRowDTO(
            rowNumber: 1, sectionNumber: '1', itemName: 'Неучтённая работа', quantity: 1,
            unitPrice: 0.1, currentTotalAmount: 0.15, overheadAmount: 0.03, profitAmount: 0.02,
            isNotAccounted: true, rawData: ['financial_structure' => ['layout' => 'grand_modern_2020']],
        );
        self::assertSame(0.15, (new ImportRowPolicy)->totalAmount($row));
    }

    public function test_export_accounting_state_supports_an_omitted_optional_quantity_coefficient(): void
    {
        $this->withInactiveWorkbook(true, true, true, function ($bridge, $path, $structure): void {
            self::assertSame('', $structure->columnMapping['quantity_coefficient']);
            $preview = $bridge->preview(new ImportSession, $path, $structure);
            self::assertTrue($preview->validation['is_valid'], json_encode($preview->validation));
            self::assertSame(0.11, $preview->totals['total_amount']);
            $roots = array_values(array_filter($preview->items, fn ($row) => !$row['is_sub_item']));
            self::assertSame([false, true, false], array_column($roots, 'is_not_accounted'));
        }, quantityCoefficient: false);
    }

    public function test_gray_font_or_helper_mirror_alone_cannot_exclude_positions(): void
    {
        foreach ([[true, false, true], [false, true, true], ['partial', true, true], [true, true, false]] as [$gray, $mirrors, $producer]) {
            $this->withInactiveWorkbook($gray, $mirrors, $producer, function ($bridge, $path, $structure): void {
                $preview = $bridge->preview(new ImportSession, $path, $structure);
                self::assertFalse($preview->validation['is_valid']);
                self::assertCount(3, $preview->items);
                self::assertSame([false, false, false], array_column($preview->items, 'is_not_accounted'));
            });
        }
    }

    private function withInactiveWorkbook(bool|string $gray, bool $mirrors, bool $producer, \Closure $assert, bool $quantityCoefficient = true): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', $producer ? 'ГРАНД-Смета 2026.2' : 'Расчёт');
        $sheet->setCellValue('J1', 'Сметная стоимость в базисном уровне цен');
        $sheet->setCellValue('M1', 'Индексы');
        $sheet->setCellValue('N1', 'Сметная стоимость в текущем уровне цен');
        $sheet->fromArray([1, 2, 3, null, null, 4, 5, $quantityCoefficient ? 6 : null, 7, 8, 9, 10, 11, 12], null, 'A3', true);
        foreach ([4 => 'Работа один', 7 => 'Неучтённая работа', 10 => 'Работа два'] as $row => $name) {
            foreach (['A' => $row === 4 ? 1 : 2, 'B' => 'ГЭСН-'.$row, 'C' => $name, 'I' => 1] as $column => $value) {
                $sheet->setCellValue($column.$row, $value);
            }
            $sheet->setCellValue('C'.($row + 1), 'ЭМ');
            $sheet->setCellValue('L'.($row + 1), 0.05);
            $sheet->setCellValue('C'.($row + 2), 'Итого по расценке');
            $sheet->setCellValue('L'.($row + 2), 0.05);
            $sheet->setCellValue(($mirrors && $row === 7 ? 'Q' : 'P').$row, $name);
        }
        if ($gray) {
            $sheet->getStyle($gray === 'partial' ? 'C7' : 'A7:C7')->getFont()->getColor()->setARGB('FF7F7F7F');
        }
        foreach ([13 => ['C' => 'Итоги по смете:'], 14 => ['C' => 'Строительные работы'], 15 => ['C' => 'Эксплуатация машин и механизмов', 'L' => 0.1, 'M' => 1.1, 'N' => 0.11], 16 => ['C' => 'Всего', 'N' => 0.11]] as $row => $values) {
            foreach ($values as $column => $value) $sheet->setCellValue($column.$row, $value);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'grand-accounting-');
        $path = $temporary.'.xlsx';
        unlink($temporary);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        try {
            $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, new GrandSmetaParser(new StatefulGrandSmetaProcessor), new GrandSmetaXMLParser);
            $assert($bridge, $path, $bridge->detectStructure(new ImportSession, $path));
        } finally {
            unlink($path);
        }
    }

    private function withWorkbook(bool $missing, float $net, float $vat, \Closure $assert): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Расчет');
        $sheet->setCellValue('J1', 'Сметная стоимость в базисном уровне цен');
        $sheet->setCellValue('M1', 'Индексы');
        $sheet->setCellValue('N1', 'Сметная стоимость в текущем уровне цен');
        $sheet->fromArray([1, 2, 3, null, null, 4, 5, 6, 7, 8, 9, 10, 11, 12], null, 'A3', true);
        $data = [
            4 => ['A' => 1, 'C' => 'Материал', 'I' => 2, 'J' => 10, 'K' => 99, 'L' => 20, 'M' => 4, 'N' => 80],
            5 => ['A' => 1, 'B' => 'ГЭСН-1', 'C' => 'Работа', 'I' => 2],
            6 => ['C' => 'ОТ', 'N' => 200],
            7 => ['C' => 'ЭМ', 'L' => 30, 'N' => $missing ? null : 120],
            8 => ['C' => 'в т.ч. ОТм', 'N' => 20],
            9 => ['C' => 'М', 'N' => 90],
            10 => ['C' => 'НР 10%', 'N' => 20],
            11 => ['C' => 'СП 5%', 'N' => 10],
            12 => ['A' => 2, 'C' => 'Возврат', 'I' => -2, 'N' => -20],
            13 => ['A' => 3, 'C' => 'Нулевая корректировка', 'I' => 0, 'N' => 0],
            14 => ['C' => 'Итоги по смете:'],
            15 => ['C' => 'Накладные расходы', 'N' => 20],
            16 => ['C' => 'Сметная прибыль', 'N' => 10],
            17 => ['C' => 'Всего', 'N' => $net],
            18 => ['C' => 'НДС22%', 'N' => $vat],
            19 => ['C' => 'ВСЕГО по смете', 'N' => $net + $vat],
        ];
        foreach ($data as $number => $values) {
            foreach ($values as $column => $value) {
                $sheet->setCellValue($column.$number, $value);
            }
        }
        $temporary = tempnam(sys_get_temp_dir(), 'grand-modern-');
        $path = $temporary.'.xlsx';
        unlink($temporary);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        try {
            $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, new GrandSmetaParser(new StatefulGrandSmetaProcessor), new GrandSmetaXMLParser);
            $structure = $bridge->detectStructure(new ImportSession, $path);
            $assert($bridge, $path, $structure);
        } finally {
            unlink($path);
        }
    }
}
