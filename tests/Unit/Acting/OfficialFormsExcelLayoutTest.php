<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use App\Services\Acting\ActingPriceService;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionClass;
use PHPUnit\Framework\TestCase;

final class OfficialFormsExcelLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_ks2_preserves_standard_columns_snapshot_rate_and_optional_notes(): void
    {
        $sheet = $this->render('KS2');
        $header = $this->findRow($sheet, '№ п/п');
        self::assertSame([
            '№ п/п', 'Номер позиции по смете', 'Наименование работ', 'Номер единичной расценки',
            'Единица измерения', 'Количество', 'Цена за единицу, руб.', 'Стоимость, руб.', 'Примечание',
        ], $sheet->rangeToArray("A{$header}:I{$header}")[0]);
        $row = $header + 1;
        self::assertSame('02.01', $sheet->getCell("B{$row}")->getValue());
        self::assertSame('ГЭСН-15-02-001-01', $sheet->getCell("D{$row}")->getValue());
        self::assertSame('Учебное примечание', $sheet->getCell("I{$row}")->getValue());
        self::assertEquals(2.5, $sheet->getCell("F{$row}")->getValue());
        self::assertEquals(120, $sheet->getCell("G{$row}")->getValue());
        self::assertEquals(300, $sheet->getCell("H{$row}")->getValue());
        self::assertSame('n', $sheet->getCell("H{$row}")->getDataType());
        $vat = $this->findRow($sheet, 'В том числе НДС', 'C');
        self::assertEquals(50, $sheet->getCell("H{$vat}")->getValue());
        $total = $this->findRow($sheet, 'Всего по Акту', 'C');
        self::assertEquals(300, $sheet->getCell("H{$total}")->getValue());
    }

    public function test_both_forms_identify_parties_once_and_have_two_neutral_signatures(): void
    {
        foreach (['KS2', 'KS3'] as $form) {
            $sheet = $this->render($form);
            $text = $this->text($sheet);
            self::assertSame(1, substr_count($text, 'ООО Заказчик договора'));
            self::assertSame(1, substr_count($text, 'ИП Подрядчик договора'));
            self::assertStringContainsString('7700000001', $text);
            self::assertStringContainsString('Адрес заказчика', $text);
            self::assertStringContainsString('Д-УЧ/2026', $text);
            self::assertStringContainsString('01.01.2026', $text);
            self::assertStringContainsString('01.10.2026', $text);
            self::assertStringContainsString('31.10.2026', $text);
            self::assertStringContainsString('АКТ-УЧ-01', $text);
            self::assertStringNotContainsString('Генеральный', $text);
            self::assertSame(2, substr_count($text, '(должность)'));
            self::assertSame(2, substr_count($text, '(подпись)'));
            self::assertSame(2, substr_count($text, '(расшифровка подписи)'));
            $signatureRows = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $value = (string) $sheet->getCell('A'.$row->getRowIndex())->getValue();
                if (str_contains($value, '(должность)')) {
                    self::assertSame(2, substr_count($value, "\n"));
                    self::assertGreaterThanOrEqual(42, $sheet->getRowDimension($row->getRowIndex())->getRowHeight());
                    $signatureRows++;
                }
            }
            self::assertSame(2, $signatureRows);
            self::assertStringContainsString($form === 'KS2' ? 'Сдал' : 'Заказчик (Генподрядчик)', $text);
            self::assertStringContainsString($form === 'KS2' ? 'Принял' : 'Подрядчик (Субподрядчик)', $text);
            self::assertStringContainsString($form === 'KS2' ? 'АКТ О ПРИЕМКЕ ВЫПОЛНЕННЫХ РАБОТ' : 'СПРАВКА О СТОИМОСТИ ВЫПОЛНЕННЫХ РАБОТ И ЗАТРАТ', $text);
            self::assertSame('landscape', $sheet->getPageSetup()->getOrientation());
            self::assertEquals(1, $sheet->getPageSetup()->getFitToWidth());
            self::assertEquals(0, $sheet->getPageSetup()->getFitToHeight());
            self::assertNotEmpty($sheet->getPageSetup()->getRowsToRepeatAtTop());
        }
    }

    public function test_ks3_keeps_distinct_cumulative_totals_and_certificate_signatures(): void
    {
        foreach ([false, true] as $certificate) {
            $sheet = $this->render('KS3', 1, $certificate);
            $row = $this->findRow($sheet, 'Всего работ и затрат, включаемых в стоимость работ', 'B');
            self::assertEquals(900, $sheet->getCell("D{$row}")->getValue());
            self::assertEquals(600, $sheet->getCell("E{$row}")->getValue());
            self::assertEquals(300, $sheet->getCell("F{$row}")->getValue());
            self::assertSame(2, substr_count($this->text($sheet), '(должность)'));
        }
    }

    public function test_long_documents_repeat_table_headings_without_horizontal_spill(): void
    {
        foreach (['KS2', 'KS3'] as $form) {
            $sheet = $this->render($form, 45);
            self::assertStringContainsString('Работа 45', $this->text($sheet));
            self::assertSame('A1:'.($form === 'KS2' ? 'I' : 'G').$sheet->getHighestRow(), $sheet->getPageSetup()->getPrintArea());
            self::assertEquals(0, $sheet->getPageSetup()->getFitToHeight());
            self::assertLessThanOrEqual(0.4, $sheet->getPageMargins()->getLeft());
            self::assertLessThanOrEqual(0.4, $sheet->getPageMargins()->getRight());
        }
    }

    public function test_large_amounts_and_long_party_names_remain_complete(): void
    {
        foreach (['KS2' => 'H', 'KS3' => 'F'] as $form => $column) {
            $sheet = $this->render($form, 1, false, true);
            self::assertStringContainsString(str_repeat('Полное наименование организации ', 7), $this->text($sheet));
            self::assertGreaterThan(18, $sheet->getRowDimension(6)->getRowHeight());
            $row = $this->findRow($sheet, $form === 'KS2' ? 'Всего по Акту' : 'Всего с учетом НДС', $form === 'KS2' ? 'C' : 'B');
            self::assertEquals(123456789012.34, $sheet->getCell($column.$row)->getValue());
            self::assertTrue($sheet->getCell($column.$row)->getStyle()->getAlignment()->getShrinkToFit());
            self::assertFalse($sheet->getCell($column.$row)->getStyle()->getAlignment()->getWrapText());
        }
    }

    public function test_short_three_work_act_keeps_both_signatures_within_landscape_height(): void
    {
        $sheet = $this->render('KS2', 3, false, false, true);
        $height = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $height += $sheet->getRowDimension($row->getRowIndex())->getRowHeight();
        }

        self::assertLessThanOrEqual(535, $height);
        self::assertSame('Arial', $sheet->getParent()->getDefaultStyle()->getFont()->getName());
        self::assertSame(10.0, $sheet->getParent()->getDefaultStyle()->getFont()->getSize());
        self::assertSame(2, substr_count($this->text($sheet), '(подпись)'));
        self::assertStringContainsString('Окраска стен водно-дисперсионной краской в два слоя', $this->text($sheet));
        self::assertEquals(900, $sheet->getCell('H'.$this->findRow($sheet, 'Всего по Акту', 'C'))->getValue());
        self::assertEquals(0, $sheet->getPageSetup()->getFitToHeight());
    }

    private function render(string $form, int $count = 1, bool $certificate = false, bool $large = false, bool $shortAct = false): Worksheet
    {
        $contract = new Contract;
        $contract->setRawAttributes(['id' => 1, 'number' => 'Д-УЧ/2026', 'date' => '2026-01-01', 'is_fixed_amount' => true, 'total_amount' => 100000]);
        $first = new ContractParty;
        $first->setRawAttributes(['name' => 'Заказчик', 'legal_name' => 'ООО Заказчик договора', 'inn' => '7700000001', 'legal_address' => 'Адрес заказчика']);
        if ($large) {
            $first->legal_name = str_repeat('Полное наименование организации ', 7);
        }
        if ($shortAct) {
            $first->legal_address = str_repeat('Учебный адрес, ', 8);
        }
        $second = new ContractParty;
        $second->setRawAttributes(['name' => 'Подрядчик', 'legal_name' => 'ИП Подрядчик договора', 'inn' => '7700000002', 'legal_address' => 'Адрес подрядчика']);
        $contract->setRelation('firstParty', $first);
        $contract->setRelation('secondParty', $second);
        $contract->setRelation('project', (object) ['name' => 'Учебный объект 102']);
        $contract->setRelation('estimate', null);
        $act = new ContractPerformanceAct;
        $act->setRawAttributes(['id' => 1, 'act_document_number' => 'АКТ-УЧ-01', 'act_date' => '2026-11-03', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount' => 300 * $count, 'vat_amount' => 50 * $count, 'vat_rate' => 20]);
        if ($large) {
            $act->amount = 123456789012.34;
        }
        $act->setRelation('contract', $contract);
        $lines = new Collection;
        for ($index = 1; $index <= $count; $index++) {
            $line = new PerformanceActLine;
            $line->setRawAttributes(['id' => $index, 'title' => 'Работа '.$index.($count > 1 ? ' — '.str_repeat('подготовка поверхности и устройство покрытия ', 4) : ''), 'unit' => 'м²', 'quantity' => 2.5, 'unit_price' => 120, 'amount' => 300, 'manual_reason' => 'Учебное примечание', 'basis_snapshot' => json_encode(['estimate_item' => ['position_number' => '02.01', 'normative_rate_code' => 'ГЭСН-15-02-001-01']])]);
            if ($shortAct) {
                $line->title = ['Подготовка поверхности стен под штукатурку', 'Штукатурка стен гипсовой смесью', 'Окраска стен водно-дисперсионной краской в два слоя'][$index - 1];
            }
            if ($large) {
                $line->amount = $act->amount;
                $line->unit_price = (float) $act->amount / 2.5;
            }
            $line->setRelation('estimateItem', null);
            $line->setRelation('completedWork', null);
            $lines->push($line);
        }
        $act->setRelation('lines', $lines);
        $act->setRelation('completedWorks', new Collection);
        $service = new class extends OfficialFormsExportService
        {
            public function __construct()
            {
                $this->priceService = new ActingPriceService;
            }

            protected function ks3LineAggregates(ContractPerformanceAct $act, bool $preview = false): array
            {
                $lines = [];
                foreach ($act->lines as $line) {
                    $lines['line:'.$line->id] = ['from_start' => (float) $line->amount * 3, 'year_total' => (float) $line->amount * 2];
                }

                return ['lines' => $lines, 'year_total' => (float) $act->amount * 2, 'total_from_start' => (float) $act->amount * 3, 'period_total' => (float) $act->amount, 'vat' => (float) $act->vat_amount];
            }
        };
        $reflection = new ReflectionClass($service);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        if ($certificate) {
            $data = ['contract' => $contract, 'document_number' => 'Справка-01', 'document_date' => $act->act_date, 'period_start' => $act->period_start, 'period_end' => $act->period_end, 'total_amount' => 300, 'total_from_start' => 900, 'year_total' => 600, 'vat_amount' => 50, 'works' => [['title' => 'Работа 1', 'code' => 'ГЭСН-01', 'from_start' => 900, 'year_total' => 600, 'amount' => 300]]];
            $reflection->getMethod('setKS3DatasetHeader')->invoke($service, $sheet, $data);
            $reflection->getMethod('setKS3DatasetItems')->invoke($service, $sheet, $data);
        } else {
            $reflection->getMethod('set'.$form.'Header')->invoke($service, $sheet, $act, $contract);
            $reflection->getMethod('set'.$form.'Items')->invoke($service, $sheet, $act);
            $reflection->getMethod('set'.$form.'Footer')->invoke($service, $sheet, $act, $contract);
        }
        $reflection->getMethod('apply'.$form.'Styles')->invoke($service, $sheet);
        $directory = getenv('MOST_KS23_QA_DIR');
        $path = $directory ? $directory.'/'.$form.'-'.$count.($certificate ? '-certificate' : '').($large ? '-large' : '').'.xlsx' : tempnam(sys_get_temp_dir(), 'ks23-');
        try {
            (new Xlsx($spreadsheet))->save($path);
            return (new XlsxReader)->load($path)->getActiveSheet();
        } finally {
            if (! $directory) {
                unlink($path);
            }
        }
    }

    private function findRow(Worksheet $sheet, string $text, string $column = 'A'): int
    {
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if ($sheet->getCell($column.$row)->getValue() === $text) {
                return $row;
            }
        }
        self::fail('Missing row: '.$text);
    }

    private function text(Worksheet $sheet): string
    {
        return implode("\n", array_map(static fn (array $row): string => implode(' ', $row), $sheet->toArray()));
    }
}
