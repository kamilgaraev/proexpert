<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class SpreadsheetDocumentExtractorTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([
            'estimate-generation' => [
                'ocr' => [
                    'max_spreadsheet_rows' => 2000,
                    'max_spreadsheet_columns' => 80,
                    'max_spreadsheet_cells' => 20_000,
                    'max_spreadsheet_sheets' => 32,
                    'max_spreadsheet_zip_entries' => 2048,
                    'max_spreadsheet_uncompressed_bytes' => 20_000_000,
                    'max_spreadsheet_compression_ratio' => 100,
                    'languages' => ['ru', 'en'],
                ],
            ],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    public function extracts_a_spooled_file_without_a_filename_extension(): void
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setCellValue('A1', 'Фундамент');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'estimate-generation-xlsx-');
        $source = tmpfile();

        try {
            self::assertIsString($xlsxPath);
            self::assertIsResource($source);
            (new Xlsx($workbook))->save($xlsxPath);
            $input = fopen($xlsxPath, 'rb');
            self::assertIsResource($input);
            stream_copy_to_stream($input, $source);
            fclose($input);
            fflush($source);
            $path = stream_get_meta_data($source)['uri'] ?? null;
            self::assertIsString($path);

            $document = new EstimateGenerationDocument([
                'filename' => 'смета.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'meta' => ['original_extension' => 'xlsx'],
            ]);
            $result = (new SpreadsheetDocumentExtractor)->extractFile($document, $path);

            self::assertStringContainsString('Фундамент', $result->pages[0]->text);
        } finally {
            $workbook->disconnectWorksheets();
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_string($xlsxPath) && is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }

    #[Test]
    public function bounded_reader_reports_row_column_and_render_truncation_without_calculating_formulas(): void
    {
        config()->set('estimate-generation.ocr.max_spreadsheet_rows', 2);
        config()->set('estimate-generation.ocr.max_spreadsheet_columns', 2);
        config()->set('estimate-generation.ocr.max_spreadsheet_cells', 3);
        config()->set('estimate-generation.ocr.max_spreadsheet_render_cells', 2);
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->setCellValue('A1', '=1+1');
        $sheet->setCellValue('B1', 'B1');
        $sheet->setCellValue('C1', 'C1');
        $sheet->setCellValue('A2', 'A2');
        $sheet->setCellValue('B2', 'B2');
        $sheet->setCellValue('A3', 'A3');
        $path = tempnam(sys_get_temp_dir(), 'bounded-xlsx-');

        try {
            self::assertIsString($path);
            (new Xlsx($workbook))->save($path);
            $result = (new SpreadsheetDocumentExtractor)->extractFile($this->document(), $path);
            $native = $result->pages[0]->rawPayload['native_structure'];

            self::assertSame('partial', $native['status']);
            self::assertEqualsCanonicalizing(
                ['xlsx_rows_truncated', 'xlsx_columns_truncated', 'xlsx_cells_truncated', 'xlsx_render_truncated'],
                $native['limitations'],
            );
            self::assertSame('=1+1', $native['cells'][0]['value']);
            self::assertSame('=1+1', $native['cells'][0]['formula']);
            self::assertCount(3, $native['cells']);
            self::assertContains('xlsx:sheet:Worksheet!A1', $native['native_reference_registry']);
        } finally {
            $workbook->disconnectWorksheets();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function xlsx_zip_container_is_rejected_before_workbook_loading_when_uncompressed_budget_is_exceeded(): void
    {
        config()->set('estimate-generation.ocr.max_spreadsheet_uncompressed_bytes', 128);
        $path = tempnam(sys_get_temp_dir(), 'unsafe-xlsx-');
        self::assertIsString($path);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', str_repeat('x', 1024));
        $zip->close();

        try {
            try {
                (new SpreadsheetDocumentExtractor)->extractFile($this->document(), $path);
                self::fail('Unsafe XLSX container must be rejected before workbook loading.');
            } catch (OcrProviderException $exception) {
                self::assertSame('spreadsheet_container_limit_exceeded', $exception->providerCode);
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function xlsx_zip_container_rejects_parent_path_entries_before_parser_loading(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'unsafe-xlsx-path-');
        self::assertIsString($path);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('../workbook.xml', '<workbook/>');
        $zip->close();

        try {
            $this->expectException(OcrProviderException::class);
            $this->expectExceptionMessage('estimate_generation.spreadsheet_parse_error');
            (new SpreadsheetDocumentExtractor)->extractFile($this->document(), $path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function xlsx_content_with_csv_metadata_cannot_bypass_zip_preflight(): void
    {
        config()->set('estimate-generation.ocr.max_spreadsheet_zip_entries', 1);
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setCellValue('A1', 'Смета');
        $path = tempnam(sys_get_temp_dir(), 'renamed-xlsx-');

        try {
            self::assertIsString($path);
            (new Xlsx($workbook))->save($path);
            $document = new EstimateGenerationDocument([
                'filename' => 'смета.csv',
                'mime_type' => 'text/csv',
                'meta' => ['original_extension' => 'csv'],
            ]);

            try {
                (new SpreadsheetDocumentExtractor)->extractFile($document, $path);
                self::fail('XLSX content must pass ZIP preflight regardless of filename and MIME metadata.');
            } catch (OcrProviderException $exception) {
                self::assertSame('spreadsheet_container_limit_exceeded', $exception->providerCode);
            }
        } finally {
            $workbook->disconnectWorksheets();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function cell_budget_is_global_across_all_loaded_sheets_and_merges_remain_native(): void
    {
        config()->set('estimate-generation.ocr.max_spreadsheet_rows', 10);
        config()->set('estimate-generation.ocr.max_spreadsheet_columns', 10);
        config()->set('estimate-generation.ocr.max_spreadsheet_cells', 4);
        $workbook = new Spreadsheet;
        $first = $workbook->getActiveSheet()->setTitle('Первая');
        $first->fromArray([['A1', 'B1'], ['A2', 'B2']]);
        $first->mergeCells('A1:B1');
        $second = $workbook->createSheet()->setTitle('Вторая');
        $second->fromArray([['C1', 'D1'], ['C2', 'D2']]);
        $path = tempnam(sys_get_temp_dir(), 'multi-sheet-budget-');

        try {
            self::assertIsString($path);
            (new Xlsx($workbook))->save($path);
            $result = (new SpreadsheetDocumentExtractor)->extractFile($this->document(), $path);
            $native = array_map(
                static fn ($page): array => $page->rawPayload['native_structure'],
                $result->pages,
            );

            self::assertLessThanOrEqual(4, array_sum(array_map(
                static fn (array $sheet): int => count($sheet['cells']),
                $native,
            )));
            self::assertContains('A1:B1', $native[0]['merges']);
            self::assertContains('xlsx_cells_truncated', $native[0]['limitations']);
            self::assertContains('xlsx_cells_truncated', $native[1]['limitations']);
        } finally {
            $workbook->disconnectWorksheets();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function xlsx_cell_budget_loads_exactly_twenty_thousand_cells_globally_and_excludes_the_next_cell(): void
    {
        config()->set('estimate-generation.ocr.max_spreadsheet_rows', 1001);
        config()->set('estimate-generation.ocr.max_spreadsheet_columns', 10);
        config()->set('estimate-generation.ocr.max_spreadsheet_cells', 20_000);
        config()->set('estimate-generation.ocr.max_spreadsheet_render_cells', 20_000);
        $workbook = new Spreadsheet;
        $first = $workbook->getActiveSheet()->setTitle('Первая');
        $second = $workbook->createSheet()->setTitle('Вторая');
        for ($row = 1; $row <= 1000; $row++) {
            for ($column = 1; $column <= 10; $column++) {
                $first->setCellValue([$column, $row], 'first-'.$row.'-'.$column);
                $second->setCellValue([$column, $row], 'second-'.$row.'-'.$column);
            }
        }
        $second->setCellValue('A1001', 'cell-20001');
        $path = tempnam(sys_get_temp_dir(), 'exact-cell-budget-xlsx-');

        try {
            self::assertIsString($path);
            (new Xlsx($workbook))->save($path);
            $result = (new SpreadsheetDocumentExtractor)->extractFile($this->document(), $path);
            $native = array_map(
                static fn ($page): array => $page->rawPayload['native_structure'],
                $result->pages,
            );
            $cells = array_merge($native[0]['cells'], $native[1]['cells']);

            self::assertCount(20_000, $cells);
            self::assertSame(20_000, array_sum(array_column($native, 'loaded_cells')));
            self::assertSame('first-1-1', $cells[0]['value']);
            self::assertNotContains('cell-20001', array_column($cells, 'value'));
            self::assertNotContains('xlsx_cells_truncated', $native[0]['limitations']);
            self::assertContains('xlsx_cells_truncated', $native[1]['limitations']);
            self::assertContains('xlsx_rows_truncated', $native[1]['limitations']);
            self::assertSame('partial', $native[1]['status']);
            self::assertNotContains('xlsx_render_truncated', $native[0]['limitations']);
            self::assertNotContains('xlsx_render_truncated', $native[1]['limitations']);
        } finally {
            $workbook->disconnectWorksheets();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function document(): EstimateGenerationDocument
    {
        return new EstimateGenerationDocument([
            'filename' => 'смета.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'meta' => ['original_extension' => 'xlsx'],
        ]);
    }
}
