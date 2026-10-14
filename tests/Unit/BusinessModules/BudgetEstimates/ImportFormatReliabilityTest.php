<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Csv\LocalCsvHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetAiColumnMapper;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use App\Models\ImportSession;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\GrandSmetaRuntimeBridge;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetCostBlockParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class ImportFormatReliabilityTest extends TestCase
{
    public function test_low_confidence_and_wrong_kinds_preserve_safe_rules(): void
    {
        $headers = ['No', 'Code', 'Name', 'Unit', 'Qty', 'Price', 'Total'];
        $mapping = (new SpreadsheetHeaderDetector)->mapHeaders($headers);
        $rows = [['1', 'RATE-1', 'Installation', 'm3', 2, 100, 200]];
        $weak = $this->mapper(['mapping' => ['name' => 'E', 'quantity' => 'C'], 'confidence' => 0.2]);
        self::assertSame($mapping, $weak->improve($headers, $rows, $mapping)['mapping']);
        $wrongKind = $this->mapper(['mapping' => ['quantity' => 'C', 'name' => 'E'], 'confidence' => 0.99]);
        $result = $wrongKind->improve($headers, $rows, $mapping);
        self::assertSame($mapping, $result['mapping']);
        self::assertFalse($result['applied']);
    }

    public function test_ai_adds_a_missing_code_column_without_replacing_other_fields(): void
    {
        $headers = ['No', 'Name', 'Code', 'Unit', 'Qty', 'Price', 'Total'];
        $mapping = (new SpreadsheetHeaderDetector)->mapHeaders($headers);
        unset($mapping['code']);
        $mapper = $this->mapper(['mapping' => ['code' => 'C'], 'confidence' => 0.96]);
        $result = $mapper->improve($headers, [['1', 'Installation', 'RATE-1', 'm3', 2, 100, 200]], $mapping);
        self::assertTrue($result['applied']);
        self::assertSame('C', $result['mapping']['code']);
        unset($result['mapping']['code']);
        self::assertSame($mapping, $result['mapping']);
    }

    public function test_structure_ai_cannot_replace_safe_columns_with_low_confidence(): void
    {
        $rows = [1 => ['No', 'Name', 'Qty', 'Price', 'Total'], 2 => [1, 'Installation', 2, 100, 200]];
        $detection = (new SpreadsheetHeaderDetector)->detect($rows);
        $mapper = $this->mapper(['header_row' => 1, 'mapping' => ['name' => 'C', 'quantity' => 'B', 'unit_price' => 'D', 'total_price' => 'E'], 'confidence' => 0.1]);
        $result = $mapper->detectStructure($rows, $detection);
        self::assertFalse($result['applied']);
        self::assertSame($detection, $result['detection']);
    }

    public function test_structure_ai_can_add_only_the_missing_code_field(): void
    {
        $rows = [1 => ['No', 'Name', 'Code', 'Unit', 'Qty', 'Price', 'Total'], 2 => [1, 'Installation', 'RATE-1', 'm3', 2, 100, 200]];
        $detection = (new SpreadsheetHeaderDetector)->detect($rows);
        unset($detection['column_mapping']['code']);
        $mapper = $this->mapper(['header_row' => 1, 'mapping' => ['code' => 'C'], 'confidence' => 0.96]);
        $result = $mapper->detectStructure($rows, $detection);
        self::assertTrue($result['applied']);
        self::assertSame('C', $result['detection']['column_mapping']['code']);
        self::assertSame('B', $result['detection']['column_mapping']['name']);
    }

    public function test_ai_rejects_text_values_for_a_new_numeric_field(): void
    {
        $mapping = ['name' => 'B', 'unit_price' => 'D'];
        $mapper = $this->mapper(['mapping' => ['quantity' => 'C'], 'confidence' => 0.99]);
        $result = $mapper->improve(['Reference', 'Task', 'Extra', 'Rate'], [[1, 'Installation', 'undetermined', 100]], $mapping);
        self::assertSame($mapping, $result['mapping']);
        self::assertFalse($result['applied']);
    }

    public function test_csv_uses_quoted_delimiters_and_multiline_logical_records(): void
    {
        foreach ([';', ',', "\t"] as $delimiter) {
            $path = tempnam(sys_get_temp_dir(), 'estimate-csv-');
            $handle = fopen($path, 'wb');
            fputcsv($handle, ['No', 'Code', 'Name', 'Unit', 'Qty', 'Price', 'Total'], $delimiter, '"', '');
            $name = "Installation; first, second, third\ncontinued";
            fputcsv($handle, [1, 'RATE-1', $name, 'm3', 2, 100, 200], $delimiter, '"', '');
            fputcsv($handle, [2, 'RATE-2', 'Other work', 'm3', 1, 50, 50], $delimiter, '"', '');
            fclose($handle);
            try {
                $handler = new LocalCsvHandler(new SpreadsheetTableReader, new SpreadsheetHeaderDetector);
                $session = new ImportSession;
                $structure = $handler->detectStructure($session, $path);
                $items = iterator_to_array($handler->streamRows($session, $path, $structure), false);
                self::assertSame($delimiter, $structure->metadata['delimiter']);
                self::assertCount(2, $items);
                self::assertSame(2, $items[0]->rowNumber);
                self::assertSame(3, $items[1]->rowNumber);
                self::assertSame($name, $items[0]->rawData[2]);
                self::assertSame(250.0, array_sum(array_map(static fn ($item) => $item->currentTotalAmount, $items)));
            } finally {
                unlink($path);
            }
        }
    }

    public function test_csv_delimiter_uses_table_records_after_empty_or_single_column_preambles(): void
    {
        foreach ([',', "\t", ';'] as $delimiter) {
            foreach (['', 'Synthetic estimate caption'] as $preamble) {
                $path = tempnam(sys_get_temp_dir(), 'estimate-csv-preamble-');
                $handle = fopen($path, 'wb');
                fwrite($handle, $preamble."\n");
                fputcsv($handle, ['No', 'Code', 'Name', 'Unit', 'Qty', 'Price', 'Total'], $delimiter, '"', '');
                $name = 'Work '.str_repeat('part,detail;value' . "\t", 20)."\ncontinued";
                fputcsv($handle, [1, 'RATE-1', $name, 'm3', 2, 100, 200], $delimiter, '"', '');
                fputcsv($handle, [2, 'RATE-2', $name, 'm3', 1, 50, 50], $delimiter, '"', '');
                fclose($handle);
                try {
                    $handler = new LocalCsvHandler(new SpreadsheetTableReader, new SpreadsheetHeaderDetector);
                    self::assertSame($delimiter, (new \ReflectionMethod($handler, 'detectDelimiter'))->invoke($handler, $path));
                    $session = new ImportSession;
                    $structure = $handler->detectStructure($session, $path);
                    $items = iterator_to_array($handler->streamRows($session, $path, $structure), false);
                    self::assertSame(2, $structure->headerRow);
                    self::assertSame($delimiter, $structure->metadata['delimiter']);
                    self::assertCount(2, $items);
                    self::assertSame(3, $items[0]->rowNumber);
                    self::assertSame(4, $items[1]->rowNumber);
                    self::assertSame($name, $items[0]->rawData[2]);
                    self::assertSame(250.0, array_sum(array_map(static fn ($item) => $item->currentTotalAmount, $items)));
                } finally {
                    unlink($path);
                }
            }
        }
    }

    public function test_worksheets_keep_their_individual_mapping_and_selected_override(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('First');
        $book->getActiveSheet()->fromArray([
            ['No', 'Code', 'Name', 'Unit', 'Qty', 'Price', 'Total'],
            [1, 'RATE-1', 'First work', 'm3', 2, 100, 200],
        ]);
        $book->createSheet()->setTitle('Second')->fromArray([
            ['No', 'Name', 'Code', 'Unit', 'Price', 'Qty', 'Total'],
            [1, 'Second work', 'RATE-2', 'm3', 50, 3, 150],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'estimate-sheets-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        try {
            $handler = new CustomExcelHandler(new SpreadsheetTableReader, new SpreadsheetHeaderDetector);
            $session = new ImportSession;
            $structure = $handler->detectStructure($session, $path);
            $items = iterator_to_array($handler->streamRows($session, $path, $structure), false);
            self::assertCount(2, $items);
            self::assertSame('First work', $items[0]->itemName);
            self::assertSame('Second work', $items[1]->itemName);
            self::assertSame('RATE-2', $items[1]->code);
            self::assertSame(3.0, $items[1]->quantity);
            self::assertSame(50.0, $items[1]->unitPrice);
            self::assertSame(1, $items[1]->worksheetIndex);
            self::assertSame('Second', $items[1]->worksheetName);
            $roundTrip = EstimateImportRowDTO::fromArray($items[1]->toArray());
            self::assertSame(1, $roundTrip->worksheetIndex);
            self::assertSame('Second', $roundTrip->worksheetName);
            self::assertSame(1, $structure->metadata['worksheets'][1]['header_preview_rows'][0]['row_number']);
            $session->options = [
                'worksheet_index' => 1,
                'worksheet_mappings' => [[
                    'worksheet_index' => 1,
                    'header_row' => 1,
                    'column_mapping' => ['position_number' => 'A', 'name' => 'B', 'code' => 'C', 'unit' => 'D', 'unit_price' => 'E', 'quantity' => 'F', 'total_price' => 'G'],
                ]],
            ];
            $selected = iterator_to_array($handler->streamRows($session, $path, $structure), false);
            self::assertCount(1, $selected);
            self::assertSame('Second work', $selected[0]->itemName);
            self::assertSame(150.0, $selected[0]->currentTotalAmount);
            $selectedStructure = $handler->detectStructure($session, $path);
            self::assertSame(1, $selectedStructure->metadata['worksheet_index']);
            self::assertSame([1], $selectedStructure->metadata['worksheet_indices']);
            self::assertCount(2, $selectedStructure->metadata['worksheets']);
            self::assertSame([0, 1], array_values(array_unique(array_column($selectedStructure->metadata['worksheets'], 'worksheet_index'))));
            $manualOptions = $session->options;
            unset($manualOptions['worksheet_mappings'][0]['column_mapping']['total_price']);
            $manualOptions['worksheet_mappings'][0]['manual_mapping'] = true;
            $session->options = $manualOptions;
            $reopened = $handler->detectStructure($session, $path);
            self::assertTrue($reopened->metadata['manual_mapping']);
            self::assertArrayNotHasKey('total_price', $reopened->columnMapping);
            $manualOptions['worksheet_index'] = 0;
            $session->options = $manualOptions;
            self::assertFalse($handler->detectStructure($session, $path)->metadata['manual_mapping']);
            $manualOptions['worksheet_index'] = 1;
            $session->options = $manualOptions;
            $reopened = $handler->detectStructure($session, $path);
            self::assertTrue($reopened->metadata['manual_mapping']);
            self::assertArrayNotHasKey('total_price', $reopened->columnMapping);
            $items = iterator_to_array($handler->streamRows($session, $path, $reopened), false);
            self::assertSame(150.0, $items[0]->currentTotalAmount);
            $manualOptions['worksheet_mappings'][0]['column_mapping'] = [];
            $session->options = $manualOptions;
            self::assertSame([], $handler->detectStructure($session, $path)->columnMapping);
        } finally {
            unlink($path);
        }
    }

    public function test_grandsmeta_marker_needs_the_numbered_identity_header(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'ГРАНД-Смета');
        $sheet->fromArray([[4, 7, 10, 12]], null, 'A5');
        $handler = new GrandSmetaHandler;
        self::assertFalse($handler->findHeaderAndMapping($sheet)['header_confirmed']);
        self::assertSame(0.0, $handler->canHandle($book, 'xlsx')->confidence);
        $sheet->fromArray([[1, 2, 3, 4, null, null, 7, 8, 9, 10, null, 12]], null, 'A20');
        $detection = $handler->findHeaderAndMapping($sheet);
        self::assertTrue($detection['header_confirmed']);
        self::assertSame(20, $detection['header_row']);
        self::assertSame(1.0, $handler->canHandle($book, 'xlsx')->confidence);
        $book->disconnectWorksheets();
    }

    public function test_composite_and_numeric_headers_restore_normative_codes_in_the_actual_column(): void
    {
        $handler = new CustomExcelHandler(new SpreadsheetTableReader, new SpreadsheetHeaderDetector);
        $mapping = ['position_number' => 'A', 'name' => 'B', 'quantity' => 'C', 'unit_price' => 'E', 'total_price' => 'F'];
        $rows = [
            1 => ['No', 'Name', 'Qty', "Шифр и номер позиции\nиз норматива", 'Price', 'Total'],
            2 => [1, 3, 7, 2, 10, 12],
            3 => [1, 'Installation', 2, 'ФЕР01-01-001-01', 100, 200],
            4 => [null, null, null, 'ВСЕГО на физобъем (2)', 200],
            5 => [null, null, null, 'Накладные расходы', 20],
            6 => [null, null, null, 'Сметная прибыль', 10],
            7 => [null, null, null, 'Итого с накладными и см. прибылью', 230],
            8 => [null, null, null, 'Всего с учетом "СМР=2"', 460],
            9 => [null, 'Итого по смете', null, null, null, 460],
        ];
        $resolve = new \ReflectionMethod($handler, 'mappingForStructure');
        $composite = $resolve->invoke($handler, new ImportStructureResult('custom_excel', 1, $mapping, rawHeaders: $rows[1]));
        self::assertSame('D', $composite['code']);
        $numeric = $resolve->invoke($handler, new ImportStructureResult('custom_excel', 2, $mapping, rawHeaders: $rows[2]), $rows);
        self::assertSame('D', $numeric['code']);
        $analysis = (new SpreadsheetCostBlockParser)->analyze($rows, $numeric, 2);
        self::assertFalse($analysis['total_mismatch']);
        self::assertSame(460.0, $analysis['positions'][3]['total']);
        self::assertSame(40.0, $analysis['positions'][3]['overhead']);
        self::assertSame(20.0, $analysis['positions'][3]['profit']);
        $automatic = (new \ReflectionMethod($handler, 'structureFromRows'))->invoke($handler, $rows);
        self::assertSame('D', $automatic->columnMapping['code']);
        self::assertSame('A', $automatic->columnMapping['position_number']);
    }

    public function test_normative_samples_restore_code_without_a_named_header_or_a_fixed_column(): void
    {
        $handler = new CustomExcelHandler(new SpreadsheetTableReader, new SpreadsheetHeaderDetector);
        $mapping = ['position_number' => 'A', 'name' => 'B', 'quantity' => 'C', 'unit_price' => 'E', 'total_price' => 'F'];
        $structure = new ImportStructureResult('custom_excel', 1, $mapping, rawHeaders: [1, 3, 7, 2, 10, 12], sampleRows: [
            [1, 'Work one', 1, 'ФЕР01-01-001-01', 100, 100],
            [2, 'Work two', 2, 'ТЕР02-02-002-02', 50, 100],
        ]);
        $resolved = (new \ReflectionMethod($handler, 'mappingForStructure'))->invoke($handler, $structure);
        self::assertSame('D', $resolved['code']);
    }

    public function test_explicit_manual_omission_does_not_restore_a_bad_total_column(): void
    {
        $handler = new CustomExcelHandler(new SpreadsheetTableReader, new SpreadsheetHeaderDetector);
        $mapping = ['position_number' => 'A', 'name' => 'B', 'quantity' => 'C', 'unit_price' => 'D'];
        $structure = new ImportStructureResult('custom_excel', 1, $mapping, rawHeaders: ['No', 'Name', 'Qty', 'Price', 'Total'], metadata: ['manual_mapping' => true]);
        $resolved = (new \ReflectionMethod($handler, 'mappingForStructure'))->invoke($handler, $structure);
        self::assertSame($mapping, $resolved);
        $analysis = (new SpreadsheetCostBlockParser)->analyze([2 => [1, 'Work', 2, 100, 999]], $resolved, 1);
        self::assertSame(200.0, $analysis['positions'][2]['total']);
    }

    public function test_grandsmeta_stream_rejects_an_unconfirmed_fallback(): void
    {
        $parser = $this->createMock(GrandSmetaParser::class);
        $parser->expects(self::never())->method('getStream');
        $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, $parser, new GrandSmetaXMLParser);
        $structure = new ImportStructureResult('grandsmeta', headerRow: 44, columnMapping: ['name' => 'C', 'quantity' => 'G'], metadata: ['header_confirmed' => false]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('import_grand_structure_unconfirmed');
        iterator_to_array($bridge->streamRows(new ImportSession, 'unconfirmed.xlsx', $structure));
    }

    public function test_grandsmeta_stream_accepts_an_explicit_manual_header_and_mapping(): void
    {
        $parser = $this->createMock(GrandSmetaParser::class);
        $parser->expects(self::once())->method('getStream')->willReturnCallback(static function (): \Generator {
            yield new EstimateImportRowDTO(itemName: 'Manually mapped', currentTotalAmount: 100);
        });
        $bridge = new GrandSmetaRuntimeBridge(new GrandSmetaHandler, $parser, new GrandSmetaXMLParser);
        $session = new ImportSession;
        $session->options = ['manual_structure_confirmed' => true, 'manual_header_row' => 2];
        $structure = new ImportStructureResult('grandsmeta', headerRow: 2, columnMapping: ['name' => 'B', 'total_price' => 'E'], metadata: ['header_confirmed' => false]);
        $items = iterator_to_array($bridge->streamRows($session, 'manual.xlsx', $structure));
        self::assertCount(1, $items);
        self::assertSame('Manually mapped', $items[0]->itemName);
    }

    private function mapper(array $payload): SpreadsheetAiColumnMapper
    {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('getModel')->willReturn('test');
        $provider->method('chat')->willReturn(['content' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        return new SpreadsheetAiColumnMapper($provider);
    }
}
