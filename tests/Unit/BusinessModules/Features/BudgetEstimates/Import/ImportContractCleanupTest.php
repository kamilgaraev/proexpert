<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates\Import;

use App\BusinessModules\Features\BudgetEstimates\Contracts\EstimateImportParserInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\EstimateAdapterInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\ProhelperAdapter;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportMappingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\ExcelSimpleTableParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\UniversalXmlParser;
use Generator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportContractCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('config', new Repository());
        $container->instance('log', new class {
            public function debug(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
        });

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_prohelper_adapter_implements_estimate_adapter_contract(): void
    {
        $adapter = new ProhelperAdapter();
        $dto = new EstimateImportDTO(
            fileName: 'prohelper.xlsx',
            fileSize: 123,
            fileFormat: 'prohelper',
            sections: [],
            items: [
                new EstimateImportRowDTO(
                    rowNumber: 1,
                    sectionNumber: '1',
                    itemName: 'Montazh',
                    unit: 'sht',
                    quantity: 2.0,
                    unitPrice: 100.0,
                    rawData: ['prohelper_metadata' => ['source_version' => '1']],
                    currentTotalAmount: 200.0
                ),
            ],
            totals: ['total_amount' => 200.0],
            metadata: ['source' => 'prohelper'],
            estimateType: 'prohelper'
        );

        self::assertInstanceOf(EstimateAdapterInterface::class, $adapter);
        self::assertSame($dto, $adapter->adapt($dto, ['source' => 'prohelper']));
        self::assertContains('source_version', $adapter->getSpecificFields());
    }

    public function test_excel_simple_table_parser_implements_contract_and_reads_content(): void
    {
        $filePath = $this->createTemporarySpreadsheet([
            ['No', 'Name', 'Unit', 'Qty', 'Price', 'Total'],
            ['1', 'Montazh', 'sht', 2, 100, 200],
        ]);

        try {
            $parser = new ExcelSimpleTableParser(importMappingService: new ImportMappingService());

            self::assertInstanceOf(EstimateImportParserInterface::class, $parser);
            self::assertSame(
                [
                    ['No', 'Name', 'Unit', 'Qty', 'Price', 'Total'],
                    [1, 'Montazh', 'sht', 2, 100, 200],
                ],
                $parser->readContent($filePath, 2)
            );
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
}
