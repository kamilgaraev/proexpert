<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates\Import;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;
use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Csv\LocalCsvHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Pdf\PdfEstimateHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\UniversalXmlParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateOcrExtractor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTableNormalizer;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTableQualityAnalyzer;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTextExtractor;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetAiColumnMapper;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ImportSession;
use App\Models\Organization;
use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionClass;
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

    public function test_custom_excel_handler_uses_table_from_non_active_worksheet(): void
    {
        $filePath = $this->createTemporarySpreadsheetWithCover([
            ['No', 'Name', 'Unit', 'Qty', 'Price', 'Total'],
            ['1', 'Montazh', 'sht', 2, 100, 200],
        ]);

        try {
            $handler = new CustomExcelHandler(new SpreadsheetTableReader(), new SpreadsheetHeaderDetector());
            $session = new ImportSession();
            $detection = $handler->detect($session, $filePath);
            $structure = $handler->detectStructure($session, $filePath);
            $preview = $handler->preview($session, $filePath, $structure);

            self::assertSame('custom_excel', $detection->formatSlug);
            self::assertGreaterThan(0.5, $detection->confidence);
            self::assertSame(1, $structure->metadata['worksheet_index'] ?? null);
            self::assertSame('Works', $structure->metadata['worksheet_name'] ?? null);
            self::assertSame(1, $structure->headerRow);
            self::assertCount(1, $preview->items);
            self::assertSame('Montazh', $preview->items[0]['item_name'] ?? null);
            self::assertSame(200.0, $preview->totals['total_amount'] ?? null);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_custom_excel_handler_maps_commercial_estimate_columns(): void
    {
        $filePath = $this->createTemporarySpreadsheet([
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [null, '№', 'Наименование работ', 'ед.изм.', 'кол-во', 'Расценка, ед., руб.', 'ВСЕГО, руб.', 'Примечания'],
            [null, 'Работы', null, null, null, null, null, null],
            [null, 'Раздел №1. Земляные работы.', null, null, null, null, null, null],
            [null, 1, 'Геодезические работы по привязке и разбивке осей дома', 'м2', 40, 180, 7200, null],
            [null, 2, 'Планировка площадей бульдозером', 'м2', 40, 250, 10000, null],
            [null, 'Итого по разделу 1:', null, null, null, null, 17200, null],
            [null, 'Раздел №3. Монтаж каркаса.', null, null, null, null, null, null],
            [null, 5, 'Монтаж стоек каркаса', 'м3', 0.464, 4500, 2088, null],
            [null, 7, 'Устройство обвязки из брусьев', 'м3', 2.154, 4500, 9693, null],
        ]);

        try {
            $handler = new CustomExcelHandler(new SpreadsheetTableReader(), new SpreadsheetHeaderDetector());
            $session = new ImportSession();
            $structure = $handler->detectStructure($session, $filePath);
            $preview = $handler->preview($session, $filePath, $structure);

            self::assertSame(9, $structure->headerRow);
            self::assertSame('B', $structure->columnMapping['position_number'] ?? null);
            self::assertSame('C', $structure->columnMapping['name'] ?? null);
            self::assertSame('D', $structure->columnMapping['unit'] ?? null);
            self::assertSame('E', $structure->columnMapping['quantity'] ?? null);
            self::assertSame('F', $structure->columnMapping['unit_price'] ?? null);
            self::assertSame('G', $structure->columnMapping['total_price'] ?? null);
            self::assertArrayNotHasKey('code', $structure->columnMapping);
            self::assertCount(4, $preview->items);
            self::assertSame('Геодезические работы по привязке и разбивке осей дома', $preview->items[0]['item_name'] ?? null);
            self::assertSame(40.0, $preview->items[0]['quantity'] ?? null);
            self::assertSame(180.0, $preview->items[0]['unit_price'] ?? null);
            self::assertSame(7200.0, $preview->items[0]['current_total_amount'] ?? null);
            self::assertSame(28981.0, $preview->totals['total_amount'] ?? null);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_custom_excel_handler_preserves_numbered_sections_for_arbitrary_tables(): void
    {
        $filePath = $this->createTemporarySpreadsheet([
            ['№', 'Наименование работ', 'Ед. изм.', 'Кол-во', 'Цена', 'Сумма'],
            ['1', 'Земляные работы', null, null, null, null],
            ['1.1', 'Разработка грунта вручную', 'м3', 2, 100, 200],
            ['2', 'Фундамент', null, null, null, null],
            ['2.1', 'Устройство основания', 'м2', 3, 300, 900],
        ]);

        try {
            $handler = new CustomExcelHandler(new SpreadsheetTableReader(), new SpreadsheetHeaderDetector());
            $session = new ImportSession();
            $structure = $handler->detectStructure($session, $filePath);
            $preview = $handler->preview($session, $filePath, $structure);

            self::assertCount(2, $preview->sections);
            self::assertSame('1', $preview->sections[0]['section_number'] ?? null);
            self::assertSame('1', $preview->sections[0]['section_path'] ?? null);
            self::assertSame('Земляные работы', $preview->sections[0]['item_name'] ?? null);
            self::assertSame('1', $preview->items[0]['section_path'] ?? null);
            self::assertSame('2', $preview->sections[1]['section_number'] ?? null);
            self::assertSame('2', $preview->items[1]['section_path'] ?? null);
            self::assertSame(1100.0, $preview->totals['total_amount'] ?? null);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_spreadsheet_ai_column_mapper_overrides_price_column_misclassified_as_code(): void
    {
        $provider = new class implements LLMProviderInterface {
            public function chat(array $messages, array $options = []): array
            {
                return [
                    'content' => json_encode([
                        'mapping' => [
                            'position_number' => 'B',
                            'code' => 'F',
                            'name' => 'C',
                            'unit' => 'D',
                            'quantity' => 'E',
                            'unit_price' => 'F',
                            'total_price' => 'G',
                        ],
                        'confidence' => 0.96,
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }

            public function countTokens(string $text): int
            {
                return strlen($text);
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'test-model';
            }
        };

        $mapper = new SpreadsheetAiColumnMapper($provider);
        $result = $mapper->improve(
            [null, '№', 'Наименование работ', 'ед.изм.', 'кол-во', 'Расценка, ед., руб.', 'ВСЕГО, руб.'],
            [[null, 1, 'Монтаж стоек каркаса', 'м3', 0.464, 4500, 2088]],
            ['code' => 'F', 'name' => 'C', 'unit' => 'D', 'quantity' => 'E'],
        );

        self::assertTrue($result['applied'] ?? false);
        self::assertSame('F', $result['mapping']['unit_price'] ?? null);
        self::assertSame('G', $result['mapping']['total_price'] ?? null);
        self::assertArrayNotHasKey('code', $result['mapping']);
        self::assertSame('test-model', $result['model'] ?? null);
    }

    public function test_custom_excel_handler_uses_ai_to_find_header_row_in_arbitrary_table(): void
    {
        $provider = new class implements LLMProviderInterface {
            public int $calls = 0;

            public function chat(array $messages, array $options = []): array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return [
                        'content' => json_encode([
                            'header_row' => 3,
                            'mapping' => [
                                'position_number' => 'A',
                                'description' => 'B',
                                'measure' => 'C',
                                'volume' => 'D',
                                'amount' => 5,
                            ],
                            'confidence' => 0.94,
                        ], JSON_UNESCAPED_UNICODE),
                    ];
                }

                return [
                    'content' => json_encode([
                        'mapping' => [
                            'position_number' => 'A',
                            'name' => 'B',
                            'unit' => 'C',
                            'quantity' => 'D',
                            'total_price' => 'E',
                        ],
                        'confidence' => 0.96,
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }

            public function countTokens(string $text): int
            {
                return strlen($text);
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'test-model';
            }
        };
        $filePath = $this->createTemporarySpreadsheet([
            ['Project custom estimate'],
            ['Generated from contractor table'],
            ['Pos', 'Task title', 'Measure', 'Vol', 'Line amount'],
            ['1', 'Install steel frame posts', 'm3', 0.464, 2088],
        ]);

        try {
            $handler = new CustomExcelHandler(
                new SpreadsheetTableReader(),
                new SpreadsheetHeaderDetector(),
                new SpreadsheetAiColumnMapper($provider),
            );
            $structure = $handler->detectStructure(new ImportSession(), $filePath);
            $preview = $handler->preview(new ImportSession(), $filePath, $structure);

            self::assertTrue($structure->aiMappingApplied);
            self::assertSame('ai', $structure->metadata['column_mapping_source'] ?? null);
            self::assertSame(3, $structure->headerRow);
            self::assertSame('B', $structure->columnMapping['name'] ?? null);
            self::assertSame('E', $structure->columnMapping['total_price'] ?? null);
            self::assertCount(1, $preview->items);
            self::assertSame('Install steel frame posts', $preview->items[0]['item_name'] ?? null);
            self::assertSame(2088.0, $preview->totals['total_amount'] ?? null);
            self::assertGreaterThanOrEqual(1, $provider->calls);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_custom_excel_handler_accepts_frontend_total_and_section_mapping_aliases(): void
    {
        $filePath = $this->createTemporarySpreadsheet([
            ['№', 'Работа', 'Ед.', 'Кол-во', 'Цена', 'Сумма'],
            [1, 'Монтаж стоек каркаса', 'м3', 0.464, 4500, 2088],
        ]);

        try {
            $handler = new CustomExcelHandler(new SpreadsheetTableReader(), new SpreadsheetHeaderDetector());
            $session = new ImportSession();
            $structure = new \App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult(
                formatSlug: 'custom_excel',
                headerRow: 1,
                columnMapping: [
                    'section_number' => 'A',
                    'name' => 'B',
                    'unit' => 'C',
                    'quantity' => 'D',
                    'unit_price' => 'E',
                    'current_total_amount' => 'F',
                ],
                rawHeaders: ['№', 'Работа', 'Ед.', 'Кол-во', 'Цена', 'Сумма'],
            );
            $preview = $handler->preview($session, $filePath, $structure);

            self::assertCount(1, $preview->items);
            self::assertSame('1', $preview->items[0]['section_number'] ?? null);
            self::assertSame(2088.0, $preview->items[0]['current_total_amount'] ?? null);
            self::assertSame(2088.0, $preview->totals['total_amount'] ?? null);
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

    public function test_pdf_table_normalizer_extracts_wrapped_rows_from_estimate_print_forms(): void
    {
        $text = implode("\n", [
            'Цена Попра- Стои- ПунктКоэффи- Стои-',
            '№ Шифр ЕдиницаКол-во за ед.',
            '1 2 3 4 5 6 7 8 9 10 11',
            'Локальная смета',
            '1 ФЕР01-01-001-01 Разработка грунта вручную',
            'м3 2,5 1000,00 - 2500,00 1 5,0 12500,00',
        ]);

        $rows = (new PdfEstimateTableNormalizer())->normalize($text);

        self::assertCount(1, $rows);
        self::assertSame('1', $rows[0]->sectionNumber);
        self::assertSame('ФЕР01-01-001-01', $rows[0]->code);
        self::assertSame('Разработка грунта вручную', $rows[0]->itemName);
        self::assertSame('м3', $rows[0]->unit);
        self::assertSame(2.5, $rows[0]->quantity);
        self::assertSame(1000.0, $rows[0]->unitPrice);
        self::assertSame(12500.0, $rows[0]->currentTotalAmount);
    }

    public function test_pdf_table_normalizer_preserves_numbered_sections(): void
    {
        $rows = (new PdfEstimateTableNormalizer())->normalize(implode("\n", [
            '1 Земляные работы',
            '1.1 Разработка грунта вручную м3 2 100 200',
            '2 Фундамент',
            '2.1 Устройство основания м2 3 300 900',
        ]));

        self::assertCount(4, $rows);
        self::assertTrue($rows[0]->isSection);
        self::assertSame('1', $rows[0]->sectionNumber);
        self::assertSame('1', $rows[0]->sectionPath);
        self::assertSame('Земляные работы', $rows[0]->itemName);
        self::assertSame('1', $rows[1]->sectionPath);
        self::assertTrue($rows[2]->isSection);
        self::assertSame('2', $rows[2]->sectionNumber);
        self::assertSame('2', $rows[3]->sectionPath);
    }

    public function test_estimate_import_translation_keys_exist_in_primary_language_file(): void
    {
        self::assertSame(
            'В смете найдены ошибки, исправьте их перед импортом',
            trans_message('estimate.import_validation_failed')
        );
        self::assertSame(
            'В PDF не найдены строки сметы для импорта',
            trans_message('estimate.import_pdf_no_rows')
        );
        self::assertSame(
            'Не удалось надежно разобрать таблицу PDF. Загрузите исходный Excel/XML или проверьте смету вручную перед импортом.',
            trans_message('estimate.import_pdf_table_quality_failed')
        );
    }

    public function test_pdf_handler_blocks_low_quality_rows_from_scrambled_text_layer(): void
    {
        $rows = [
            new EstimateImportRowDTO(
                rowNumber: 1,
                sectionNumber: '1',
                itemName: '2 3 4 5 6 7 8 9 10 11 Материальные ресурсы',
                unit: 'ресурсы',
                quantity: 170.77,
                unitPrice: 170.77,
                currentTotalAmount: 434.47,
            ),
        ];
        $preview = new ImportPreviewResult(
            formatSlug: 'pdf_estimate',
            items: array_map(static fn ($row): array => $row->toArray(), $rows),
        );
        $handler = new PdfEstimateHandler(
            new PdfEstimateTextExtractor(
                new PdfEstimateOcrExtractor(new class implements OcrClientInterface {
                    public function recognize(OcrDocumentInput $input): OcrRecognitionResult
                    {
                        return new OcrRecognitionResult(
                            provider: 'budget_import_test',
                            model: 'page',
                            pages: [],
                        );
                    }
                })
            ),
            new PdfEstimateTableNormalizer(),
            new PdfEstimateTableQualityAnalyzer(),
        );
        $validation = $handler->validate(new ImportSession(), $preview);

        self::assertNotEmpty($rows);
        self::assertFalse($validation->isValid());
        self::assertContains(trans_message('estimate.import_pdf_table_quality_failed'), $validation->errors);
    }

    public function test_pdf_table_normalizer_ignores_resource_and_total_noise_from_text_layer(): void
    {
        $rows = (new PdfEstimateTableNormalizer())->normalize(implode("\n", [
            '"СОГЛАСОВАНО"',
            'Цена Попра- Стои- ПунктКоэффи- Стои-',
            '№ Шифр ЕдиницаКол-во за ед. вочные мость коэфф.циенты мость в',
            'п/прасценкиНаименование работ и затрат изме-единиц изм. коэффи- в ценах пере- пере- текущих',
            '1 2 3 4 5 6 7 8 9 10 11',
            '1000 м34,9875 6 406,61 01-01- 014-5 Зарплата 244,30 1 218,45 19,69 23 991,21',
            '1 2 3 4 5 6 7 8 9 10 11 Материальные ресурсы 170,77 170,77 8,4 1 434,47',
            '1000 м3 0,09 465,88 01-01- 033-1 Эксплуатация машин 465,88 41,93 9,24 387,43',
            '100 м трубопр овода 4,57 293,70',
            '1 2 3 4 5 6 7 8 9 10 11 Сметная прибыль % 65,00 196,81((*0.8)) 52,00 3 100,23',
            '7 455 315,732 390 861,40 Дом №2 Итого 7 455 315,73',
        ]));

        self::assertSame([], array_map(static fn ($row): array => $row->toArray(), $rows));
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
                new PdfEstimateTableQualityAnalyzer(),
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

    public function test_import_pipeline_prunes_empty_leaf_sections_for_non_grand_smeta_handlers(): void
    {
        $estimate = $this->createEstimate();
        $emptyRoot = $this->createSection($estimate, '1', 'Empty root');
        $emptyChild = $this->createSection($estimate, '1.1', 'Empty child', $emptyRoot->id);
        $keptRoot = $this->createSection($estimate, '2', 'Kept root');
        $keptChild = $this->createSection($estimate, '2.1', 'Kept child', $keptRoot->id);

        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $keptChild->id,
            'position_number' => '1',
            'name' => 'Kept work',
            'quantity' => 1,
            'unit_price' => 100,
            'direct_costs' => 100,
            'total_amount' => 100,
            'is_manual' => true,
        ]);

        $removed = $this->invokePruneEmptyLeafSections($estimate, 'custom_excel');

        self::assertSame(2, $removed);
        self::assertFalse(EstimateSection::query()->whereKey($emptyChild->id)->exists());
        self::assertFalse(EstimateSection::query()->whereKey($emptyRoot->id)->exists());
        self::assertTrue(EstimateSection::query()->whereKey($keptRoot->id)->exists());
        self::assertTrue(EstimateSection::query()->whereKey($keptChild->id)->exists());
    }

    public function test_import_pipeline_keeps_empty_grand_smeta_sections(): void
    {
        $estimate = $this->createEstimate();
        $root = $this->createSection($estimate, '1', 'GrandSmeta root');
        $child = $this->createSection($estimate, '1.1', 'GrandSmeta child', $root->id);

        $removed = $this->invokePruneEmptyLeafSections($estimate, 'grandsmeta');

        self::assertSame(0, $removed);
        self::assertTrue(EstimateSection::query()->whereKey($root->id)->exists());
        self::assertTrue(EstimateSection::query()->whereKey($child->id)->exists());
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

    private function createTemporarySpreadsheetWithCover(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $cover = $spreadsheet->getActiveSheet();
        $cover->setTitle('Cover');
        $cover->setCellValue('A1', 'Estimate cover');
        $cover->setCellValue('A2', 'No import table here');

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Works');

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $cell = Coordinate::stringFromColumnIndex($columnIndex + 1) . ($rowIndex + 1);
                $sheet->setCellValue($cell, $value);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
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

    private function createEstimate(): Estimate
    {
        $organization = Organization::factory()->create();

        return Estimate::query()->create([
            'organization_id' => $organization->id,
            'number' => 'IMP-' . uniqid(),
            'name' => 'Import cleanup test',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->format('Y-m-d'),
            'vat_rate' => 20,
            'overhead_rate' => 15,
            'profit_rate' => 12,
        ]);
    }

    private function createSection(
        Estimate $estimate,
        string $number,
        string $name,
        ?int $parentSectionId = null
    ): EstimateSection {
        return EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'parent_section_id' => $parentSectionId,
            'section_number' => $number,
            'full_section_number' => $number,
            'name' => $name,
            'sort_order' => (int) str_replace('.', '', $number),
        ]);
    }

    private function invokePruneEmptyLeafSections(Estimate $estimate, string $handlerSlug): int
    {
        $reflection = new ReflectionClass(ImportPipelineService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('pruneEmptyLeafSections');

        return $method->invoke($service, $estimate, $handlerSlug);
    }
}
