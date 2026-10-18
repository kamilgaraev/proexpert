<?php

declare(strict_types=1);

namespace Tests\Unit\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\ExcelEstimateBuilder;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\PDFEstimateBuilder;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Most\MostTemplateHandler;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ImportSession;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class EstimateExportReadbackTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_uninitialized_total_quantity_falls_back_without_inventing_volume_from_money(): void
    {
        $estimate = $this->fixture();
        $items = $estimate->sections->first()->items;
        $items[0]->quantity_total = 24;
        $items[1]->quantity_total = -9;
        $items[2]->quantity = 0;
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate);
        self::assertSame([24.0, -9.0, 0.0], array_column($data['sections'][0]['items'], 'quantity_total'));
    }

    public function test_repeated_export_reads_changes_immediately(): void
    {
        $estimate = $this->fixture();
        $service = $this->app->make(EstimateExportService::class);
        self::assertSame(20.0, $service->prepareExportData($estimate)['sections'][0]['items'][0]['quantity_total']);
        $estimate->sections->first()->items[0]->quantity = 21;
        $estimate->total_amount = 30480;
        $data = $service->prepareExportData($estimate);
        self::assertSame(21.0, $data['sections'][0]['items'][0]['quantity_total']);
        self::assertSame(30480.0, $data['totals']['total_amount']);
    }

    public function test_excel_rows_and_import_metadata_preserve_material_quantities(): void
    {
        $estimate = $this->fixture();
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate);
        $result = (new ExcelEstimateBuilder)->build($estimate, $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'pto-export-');
        try {
            file_put_contents($path, $result['content']);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Смета');
            $expected = [[20, 180, 3600], [900, 18, 16200], [25, 420, 10500]];
            $found = [];
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                if (str_contains((string) $sheet->getCell("C{$row}")->getValue(), 'учебная ведомость')) {
                    $found[] = array_map(fn ($column) => (float) $sheet->getCell("{$column}{$row}")->getValue(), ['E', 'F', 'G']);
                }
            }
            self::assertEquals($expected, $found);
            $metadata = json_decode((string) $book->getSheetByName('_METADATA_')->getCell('A1')->getValue(), true);
            self::assertEquals([20, 900, 25], array_column($metadata['items'], 'quantity_total'));
            self::assertEquals(30300, $metadata['sections'][0]['section_total_amount']);
            self::assertEquals(180, $metadata['items'][0]['unit_price']);
            if ($directory = getenv('PTO_EXPORT_FIXTURE_DIR')) {
                file_put_contents($directory.'/estimate-with-prices.xlsx', $result['content']);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_excel_without_prices_omits_totals_and_financial_import_metadata(): void
    {
        $estimate = $this->fixture();
        $item = $estimate->sections->first()->items[0];
        $item->custom_resources = [['name' => 'Учебный ресурс', 'unit_price' => 9876]];
        $item->metadata = ['supplier_quote' => ['total_amount' => 9876]];
        $child = clone $item;
        $child->id = 999;
        $child->name = 'Вложенный учебный ресурс';
        $child->setRelation('childItems', new Collection);
        $item->setRelation('childItems', new Collection([$child]));
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate, [
            'show_prices' => false,
            'include_resources' => true,
        ]);
        $result = (new ExcelEstimateBuilder)->build($estimate, $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'pto-unpriced-');
        try {
            file_put_contents($path, $result['content']);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Смета');
            $values = array_merge(...$sheet->toArray());
            self::assertNotContains('Цена', $values);
            self::assertNotContains('Сумма', $values);
            self::assertNotContains('ИТОГОВАЯ СВОДКА', $values);
            self::assertNotContains('ВСЕГО С НДС:', $values);
            self::assertContains(20.0, array_map('floatval', array_filter($values, 'is_numeric')));
            $metadata = json_decode((string) $book->getSheetByName('_METADATA_')->getCell('A1')->getValue(), true);
            self::assertSame([], $metadata['calculation_settings']);
            self::assertEquals(0, $metadata['sections'][0]['section_total_amount']);
            self::assertEquals([20, 20, 900, 25], array_column($metadata['items'], 'quantity_total'));
            foreach ($metadata['items'] as $exportedItem) {
                self::assertEquals(0, $exportedItem['unit_price']);
                self::assertEquals(0, $exportedItem['total_amount']);
                self::assertNull($exportedItem['custom_resources']);
                self::assertNull($exportedItem['metadata']);
            }
            self::assertStringNotContainsString('30300', json_encode($metadata));
            self::assertStringNotContainsString('9876', json_encode($metadata));
            if ($directory = getenv('PTO_EXPORT_FIXTURE_DIR')) {
                file_put_contents($directory.'/estimate-without-prices.xlsx', $result['content']);
            }
        } finally {
            unlink($path);
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function test_own_excel_roundtrip_uses_visible_edits_and_excludes_signature_footer(bool $showPrices): void
    {
        $estimate = $this->fixture();
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate, ['show_prices' => $showPrices]);
        $result = (new ExcelEstimateBuilder)->build($estimate, $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'pto-roundtrip-');
        try {
            file_put_contents($path, $result['content']);
            $handler = $this->app->make(MostTemplateHandler::class);
            $session = new ImportSession(['options' => []]);
            self::assertSame(1.0, $handler->detect($session, $path)->confidence);
            $structure = $handler->detectStructure($session, $path);
            self::assertTrue($structure->metadata['most_export'] ?? false);

            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Смета');
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                if (str_contains((string) $sheet->getCell("C{$row}")->getValue(), 'учебная ведомость')) {
                    $sheet->setCellValue("E{$row}", 23);
                    if ($showPrices) {
                        $sheet->setCellValue("G{$row}", 23 * (float) $sheet->getCell("F{$row}")->getValue());
                    }
                    break;
                }
            }
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();

            foreach ([$structure, $handler->detectStructureFromHeaderRow($session, $path, $structure->headerRow)] as $detectedStructure) {
                $preview = $handler->preview($session, $path, $detectedStructure);
                self::assertCount(1, $preview->sections);
                self::assertCount(3, $preview->items);
                self::assertEquals(23, $preview->items[0]['quantity']);
                self::assertNotContains('ПОДПИСИ ОТВЕТСТВЕННЫХ ЛИЦ', array_column($preview->sections, 'item_name'));
                $streamed = iterator_to_array($handler->streamRows($session, $path, $detectedStructure), false);
                self::assertCount(4, $streamed);
            }
        } finally {
            unlink($path);
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['MOST_TEMPLATE_V1', 1.0])]
    #[\PHPUnit\Framework\Attributes\TestWith(['PROHELPER_TEMPLATE_V1', 1.0])]
    #[\PHPUnit\Framework\Attributes\TestWith(['MOST_ESTIMATE_EXPORT_V1 edited', 0.4])]
    public function test_other_template_markers_do_not_change_section_detection(string $description, float $confidence): void
    {
        $estimate = $this->fixture();
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate);
        $result = (new ExcelEstimateBuilder)->build($estimate, $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'pto-template-');
        try {
            file_put_contents($path, $result['content']);
            $book = IOFactory::load($path);
            $book->getProperties()->setDescription($description);
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $handler = $this->app->make(MostTemplateHandler::class);
            $session = new ImportSession(['options' => []]);
            self::assertSame($confidence, $handler->detect($session, $path)->confidence);
            $structure = $handler->detectStructure($session, $path);
            self::assertFalse($structure->metadata['most_export'] ?? false);
            $preview = $handler->preview($session, $path, $structure);
            self::assertContains('ПОДПИСИ ОТВЕТСТВЕННЫХ ЛИЦ', array_column($preview->sections, 'item_name'));
            self::assertCount(3, $preview->items);
        } finally {
            unlink($path);
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function test_excel_keeps_long_codes_and_text_readable_when_printing(bool $showPrices): void
    {
        $estimate = $this->fixture();
        $item = $estimate->sections->first()->items[0];
        $item->normative_rate_code = 'ГЭСН08-02-002-05';
        $item->is_not_accounted = true;
        $item->name = str_repeat('Подготовка и обработка стен учебного помещения. ', 5);
        $item->description = str_repeat('Условное примечание для проверки печати. ', 5);
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate, ['show_prices' => $showPrices]);
        $result = (new ExcelEstimateBuilder)->build($estimate, $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'pto-long-text-');
        try {
            file_put_contents($path, $result['content']);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Смета');
            $notesColumn = $showPrices ? 'H' : 'F';
            foreach (['B', 'C', $notesColumn] as $column) {
                self::assertLessThanOrEqual(70, $sheet->getColumnDimension($column)->getWidth());
            }
            self::assertEquals(35, $sheet->getColumnDimension($notesColumn)->getWidth());
            self::assertSame($showPrices, $sheet->getColumnDimension('G')->getVisible());
            self::assertSame($showPrices, $sheet->getColumnDimension('H')->getVisible());
            self::assertSame('ГЭСН08-02-002-05 (Н)', $sheet->getCell('B17')->getValue());
            self::assertSame($item->name, $sheet->getCell('C17')->getValue());
            self::assertSame($item->description, $sheet->getCell($notesColumn.'17')->getValue());
            foreach (['B17', 'C17', $notesColumn.'17'] as $coordinate) {
                self::assertTrue($sheet->getStyle($coordinate)->getAlignment()->getWrapText());
            }
            self::assertSame(1, $sheet->getPageSetup()->getFitToWidth());
            self::assertSame(0, $sheet->getPageSetup()->getFitToHeight());
            if ($directory = getenv('PTO_EXPORT_FIXTURE_DIR')) {
                file_put_contents($directory.'/estimate-long-text'.($showPrices ? '' : '-without-prices').'.xlsx', $result['content']);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_excel_keeps_large_numeric_values_visible_without_changing_them(): void
    {
        $estimate = $this->fixture();
        $item = $estimate->sections->first()->items[0];
        $item->quantity = 999999999;
        $item->total_amount = 179999999820;
        $item->direct_costs = 179999999820;
        $section = $estimate->sections->first();
        $section->setRelation('items', new Collection([$item]));
        $section->section_total_amount = 179999999820;
        $estimate->total_direct_costs = 179999999820;
        $estimate->total_amount = 179999999820;
        $estimate->total_amount_with_vat = 179999999820;
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate);
        $result = (new ExcelEstimateBuilder)->build($estimate, $data, $data['options']);
        $path = tempnam(sys_get_temp_dir(), 'pto-large-numbers-');
        try {
            file_put_contents($path, $result['content']);
            $sheet = IOFactory::load($path)->getSheetByName('Смета');
            self::assertEquals(999999999, $sheet->getCell('E17')->getValue());
            self::assertEquals(179999999820, $sheet->getCell('G17')->getValue());
            foreach (['E17', 'F17', 'G17'] as $coordinate) {
                self::assertTrue($sheet->getStyle($coordinate)->getAlignment()->getShrinkToFit());
                self::assertFalse($sheet->getStyle($coordinate)->getAlignment()->getWrapText());
            }
            self::assertTrue($sheet->getStyle('G18')->getAlignment()->getShrinkToFit());
            if ($directory = getenv('PTO_EXPORT_FIXTURE_DIR')) {
                file_put_contents($directory.'/estimate-large-numbers.xlsx', $result['content']);
            }
        } finally {
            unlink($path);
        }
    }

    #[WithoutErrorHandler]
    public function test_short_pdf_keeps_totals_quantities_and_all_signatures_on_one_page(): void
    {
        [$document, $text] = $this->render($this->fixture(), 'short');
        self::assertCount(1, $document->getPages());
        foreach (['20.0000', '900.0000', '25.0000', '30 300.00', 'Ед.изм.', 'Кол-во', 'ВСЕГО С НДС', 'Утверждаю (Заказчик)'] as $value) {
            self::assertStringContainsString($value, $text);
        }
    }

    #[WithoutErrorHandler]
    public function test_large_numbers_use_wide_paper_without_splitting_amounts(): void
    {
        $estimate = $this->fixture();
        $estimate->sections->first()->items[0]->quantity = 1000000;
        $estimate->sections->first()->items[0]->total_amount = 100000000;
        [$document, $text] = $this->render($estimate, 'large-numbers');
        self::assertStringContainsString('1000000.0000', $text);
        self::assertStringContainsString('100 000 000.00', $text);
        $box = $document->getPages()[0]->getDetails()['MediaBox'];
        self::assertGreaterThan((float) $box[3], (float) $box[2]);
    }

    #[WithoutErrorHandler]
    public function test_long_pdf_keeps_first_and_last_items_and_repeats_column_headers(): void
    {
        $estimate = $this->fixture();
        $section = $estimate->sections->first();
        $source = $section->items[0];
        $items = new Collection;
        for ($position = 1; $position <= 55; $position++) {
            $item = clone $source;
            $item->position_number = (string) $position;
            $item->name = "Материал {$position} — учебная ведомость для отделки стен";
            $items->push($item);
        }
        $section->setRelation('items', $items);
        [$document, $text] = $this->render($estimate, 'long');
        self::assertGreaterThan(1, count($document->getPages()));
        self::assertStringContainsString('Материал 1', $text);
        self::assertStringContainsString('Материал 55', $text);
        self::assertGreaterThan(1, substr_count($text, 'Кол-во'));
        self::assertStringContainsString('Утверждаю (Заказчик)', $text);
    }

    private function render(Estimate $estimate, string $name): array
    {
        $data = $this->app->make(EstimateExportService::class)->prepareExportData($estimate);
        $result = (new PDFEstimateBuilder)->build($estimate, $data, $data['options']);
        if ($directory = getenv('PTO_EXPORT_FIXTURE_DIR')) {
            file_put_contents($directory.'/estimate-'.$name.'.pdf', $result['content']);
        }
        $document = (new Parser)->parseContent($result['content']);

        return [$document, $document->getText()];
    }

    private function fixture(): Estimate
    {
        $estimate = new class extends Estimate
        {
            public function load($relations)
            {
                return $this;
            }
        };
        $estimate->setRawAttributes([
            'id' => 490, 'number' => 'СМ-2026-0018', 'name' => 'Смета материалов — ремонт стен офиса 100 м²',
            'status' => 'draft', 'type' => 'local', 'estimate_date' => '2026-10-13',
            'total_direct_costs' => 30300, 'total_amount' => 30300, 'total_amount_with_vat' => 30300,
            'total_overhead_costs' => 0, 'total_estimated_profit' => 0, 'vat_rate' => 0, 'overhead_rate' => 0, 'profit_rate' => 0,
        ]);
        $organization = new Organization(['name' => 'Учебная организация']);
        $project = new Project(['name' => 'Учебный ПТО — ремонт офиса 100 м² — 13.10.2026']);
        $estimate->setRelation('organization', $organization)->setRelation('project', $project)
            ->setRelation('contract', null)->setRelation('approvedBy', null);
        $section = new EstimateSection(['section_number' => '1', 'full_section_number' => '1',
            'name' => 'Материалы для отделки стен 100 м²', 'section_total_amount' => 30300]);
        $items = new Collection;
        foreach ([['Грунтовка', 'л', 20, 180], ['Гипсовая штукатурная смесь', 'кг', 900, 18], ['Краска водно-дисперсионная', 'л', 25, 420]] as $position => [$name, $unit, $quantity, $price]) {
            $item = new EstimateItem(['id' => 283068 + $position, 'position_number' => (string) ($position + 1),
                'name' => $name.' — учебная ведомость', 'item_type' => 'material', 'quantity' => $quantity, 'quantity_total' => 0,
                'unit_price' => $price, 'direct_costs' => $quantity * $price, 'total_amount' => $quantity * $price]);
            $item->setRelation('measurementUnit', new MeasurementUnit(['name' => $unit]))
                ->setRelation('workType', null)->setRelation('childItems', new Collection);
            $items->push($item);
        }
        $section->setRelation('items', $items);
        $estimate->setRelation('sections', new Collection([$section]));

        return $estimate;
    }
}
