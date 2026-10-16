<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportRenderer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\TestCase;

final class AssistantCustomReportRendererTest extends TestCase
{
    use \Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

    public function test_xlsx_preserves_formulas_as_text_and_long_numbers_without_precision_loss(): void
    {
        $stream = (new AssistantCustomReportRenderer)->render($this->report('xlsx'));
        try {
            self::assertSame('PK', fread($stream, 2));
            $path = stream_get_meta_data($stream)['uri'];
            $workbook = (new Xlsx)->load($path);
            $sheet = $workbook->getActiveSheet();
            self::assertSame('=HYPERLINK("https://example.test")', $sheet->getCell('A6')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A6')->getDataType());
            self::assertSame('9007199254740993.99', $sheet->getCell('B6')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('B6')->getDataType());
            self::assertSame(100.3, $sheet->getCell('C6')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C6')->getDataType());
            $workbook->disconnectWorksheets();
        } finally {
            fclose($stream);
        }
    }

    public function test_csv_escapes_formula_prefixes_and_preserves_unicode_and_decimal_strings(): void
    {
        $stream = (new AssistantCustomReportRenderer)->render($this->report('csv'));
        try {
            self::assertSame("\xEF\xBB\xBF", fread($stream, 3));
            self::assertSame(['Название', 'Точная сумма', 'Сумма'], fgetcsv($stream, null, ';', '"', ''));
            self::assertSame(['\'=HYPERLINK("https://example.test")', '9007199254740993.99', '100.30'], fgetcsv($stream, null, ';', '"', ''));
        } finally {
            fclose($stream);
        }
    }

    private function report(string $format): array
    {
        return ['title' => 'Руководителю', 'format' => $format, 'generated_at' => '2026-10-16T15:00:00Z', 'sections' => [
            ['title' => 'Договоры', 'headers' => ['Название', 'Точная сумма', 'Сумма'], 'column_types' => ['string', 'number', 'number'],
                'source_row_count' => 1, 'rows' => [['=HYPERLINK("https://example.test")', '9007199254740993.99', '100.30']]],
        ]];
    }
}
