<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates\Import;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Csv\LocalCsvHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Pdf\PdfEstimateHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\UniversalXmlParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateOcrExtractor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTableNormalizer;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTextExtractor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use App\Models\ImportSession;
use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportContractCleanupTest extends TestCase
{
    public function test_custom_excel_handler_implements_runtime_contract_and_builds_preview(): void
    {
        $filePath = $this->createTemporarySpreadsheet([
            ['No', 'Name', 'Unit', 'Qty', 'Price', 'Total'],
            ['1', 'Montazh', 'sht', 2, 100, 200],
        ]);

        try {
            $handler = new CustomExcelHandler(new SpreadsheetTableReader(), new SpreadsheetHeaderDetector());
            $session = new ImportSession();
            $structure = $handler->detectStructure($session, $filePath);
            $preview = $handler->preview($session, $filePath, $structure);

            self::assertInstanceOf(RuntimeImportFormatHandlerInterface::class, $handler);
            self::assertSame('custom_excel', $structure->formatSlug);
            self::assertSame('B', $structure->columnMapping['name'] ?? null);
            self::assertNotEmpty($preview->items);
            self::assertSame(200.0, $preview->totals['total_amount'] ?? null);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_universal_xml_parser_stream_returns_rows(): void
    {
        $filePath = $this->createTemporaryXml(
            '<?xml version="1.0" encoding="UTF-8"?><Estimate><Item Number="1" Name="Montazh" Measure="sht" Quant="2" Price="100"><Cost Value="200"/></Item></Estimate>'
        );

        try {
            $stream = (new UniversalXmlParser())->getStream($filePath);

            self::assertInstanceOf(Generator::class, $stream);
            self::assertNotEmpty(iterator_to_array($stream, false));
        } finally {
            @unlink($filePath);
        }
    }

    public function test_local_csv_handler_implements_runtime_contract_and_builds_preview(): void
    {
        $filePath = $this->createTemporaryTextFile(
            "No;Name;Unit;Qty;Price;Total\n1;Montazh;sht;2;100;200\n",
            'csv'
        );

        try {
            $handler = new LocalCsvHandler(new SpreadsheetTableReader(), new SpreadsheetHeaderDetector());
            $session = new ImportSession();
            $structure = $handler->detectStructure($session, $filePath);
            $preview = $handler->preview($session, $filePath, $structure);

            self::assertInstanceOf(RuntimeImportFormatHandlerInterface::class, $handler);
            self::assertSame('local_csv', $structure->formatSlug);
            self::assertSame('B', $structure->columnMapping['name'] ?? null);
            self::assertNotEmpty($preview->items);
            self::assertSame(200.0, $preview->totals['total_amount'] ?? null);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_pdf_table_normalizer_extracts_items_from_text_layer_rows(): void
    {
        $rows = (new PdfEstimateTableNormalizer())->normalize("1 Montazh sht 2 100 200\n");

        self::assertCount(1, $rows);
        self::assertSame('Montazh', $rows[0]->itemName);
        self::assertSame(2.0, $rows[0]->quantity);
        self::assertSame(200.0, $rows[0]->currentTotalAmount);
    }

    public function test_pdf_handler_uses_ocr_when_text_layer_is_unusable(): void
    {
        config(['estimate-generation.ocr.enabled' => true]);

        $filePath = $this->createTemporaryTextFile("%PDF-1.4\n%%EOF\n", 'pdf');

        try {
            $handler = new PdfEstimateHandler(
                new PdfEstimateTextExtractor(
                    new PdfEstimateOcrExtractor(new class implements OcrClientInterface {
                        public function recognize(OcrDocumentInput $input): OcrRecognitionResult
                        {
                            return new OcrRecognitionResult(
                                provider: 'budget_import_test',
                                model: 'page',
                                pages: [
                                    new OcrPageResult(
                                        pageNumber: 1,
                                        text: '1 Montazh sht 2 100 200',
                                        confidence: 0.92,
                                    ),
                                ],
                            );
                        }
                    })
                ),
                new PdfEstimateTableNormalizer(),
            );
            $session = new ImportSession();
            $detection = $handler->detect($session, $filePath);
            $structure = $handler->detectStructure($session, $filePath);
            $preview = $handler->preview($session, $filePath, $structure);

            self::assertInstanceOf(RuntimeImportFormatHandlerInterface::class, $handler);
            self::assertSame('pdf_estimate', $detection->formatSlug);
            self::assertContains('ocr_budget_import_test', $detection->indicators);
            self::assertSame('ocr_budget_import_test', $structure->metadata['extraction_method'] ?? null);
            self::assertSame('budget_import_test', $structure->metadata['ocr_provider'] ?? null);
            self::assertNotEmpty($preview->items);
            self::assertSame(200.0, $preview->totals['total_amount'] ?? null);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_grand_smeta_xml_parser_uses_existing_recursive_collector(): void
    {
        $filePath = $this->createTemporaryXml(
            '<?xml version="1.0" encoding="UTF-8"?><Estimate><Section Number="1" Name="Section"><Item Number="1" Name="Montazh" Measure="sht" Quant="2" Price="100"><Cost Value="200"/></Item></Section></Estimate>'
        );

        try {
            $dto = (new GrandSmetaXMLParser())->parse($filePath);

            self::assertInstanceOf(EstimateImportDTO::class, $dto);
            self::assertNotEmpty($dto->items);
        } finally {
            @unlink($filePath);
        }
    }

    private function createTemporarySpreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $cell = Coordinate::stringFromColumnIndex($columnIndex + 1) . ($rowIndex + 1);
                $sheet->setCellValue($cell, $value);
            }
        }

        $filePath = tempnam(sys_get_temp_dir(), 'estimate-import-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        return $filePath;
    }

    private function createTemporaryXml(string $content): string
    {
        $filePath = tempnam(sys_get_temp_dir(), 'estimate-import-') . '.xml';
        file_put_contents($filePath, $content);

        return $filePath;
    }

    private function createTemporaryTextFile(string $content, string $extension): string
    {
        $filePath = tempnam(sys_get_temp_dir(), 'estimate-import-') . '.' . $extension;
        file_put_contents($filePath, $content);

        return $filePath;
    }
}
