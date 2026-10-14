<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\BusinessModules\Features\DesignManagement\Services\BoundedSpreadsheetMetadata;
use App\Services\Documents\BoundedPdfParser;
use App\Services\Export\ExportValueBinder;
use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class DocumentMetadataSecurityTest extends TestCase
{
    public function test_spreadsheet_metadata_reads_regular_xlsx_and_ods_without_loading_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-spreadsheet-');
        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->setTitle('Normal sheet')->setCellValue('A1', 'Value');
            (new Xlsx($spreadsheet))->save($path);
            self::assertSame(['Normal sheet'], (new BoundedSpreadsheetMetadata)->inspect($path, 'xlsx'));
            $spreadsheet->disconnectWorksheets();
            unlink($path);
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
            $zip->addFromString('content.xml', '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"><table:table table:name="ODS sheet"/></office:document-content>');
            $zip->close();
            self::assertSame(['ODS sheet'], (new BoundedSpreadsheetMetadata)->inspect($path, 'ods'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_compressed_spreadsheet_bomb_is_rejected_before_xml_inflation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'most-zip-');
        try {
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
            $zip->addFromString('xl/workbook.xml', str_repeat(' ', 5_000_001));
            $zip->close();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('spreadsheet_container_limit_exceeded');
            (new BoundedSpreadsheetMetadata)->inspect($path, 'xlsx');
        } finally {
            unlink($path);
        }
    }

    public function test_pdf_parsing_preserves_parent_memory_limit_and_valid_documents(): void
    {
        $previousLimit = ini_get('memory_limit');
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<p>Safe document text</p>');
        $dompdf->render();
        $result = (new BoundedPdfParser)->inspectContent($dompdf->output());
        self::assertSame(1, $result['page_count']);
        self::assertStringContainsString('Safe document text', $result['pages'][0]);
        self::assertSame($previousLimit, ini_get('memory_limit'));
    }

    public function test_large_compressed_pdf_stream_cannot_exhaust_the_parent_process(): void
    {
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE);
        $compressed = '';
        for ($index = 0; $index < 300; $index++) {
            $compressed .= deflate_add($deflate, str_repeat('A', 1_000_000), ZLIB_NO_FLUSH);
        }
        $compressed .= deflate_add($deflate, '', ZLIB_FINISH);
        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /Parent 2 0 R /Contents 4 0 R /MediaBox [0 0 100 100] >>\nendobj\n"
            ."4 0 obj\n<< /Length ".strlen($compressed)." /Filter /FlateDecode >>\nstream\n".$compressed."\nendstream\nendobj\n"
            ."trailer\n<< /Root 1 0 R >>\n%%EOF";
        $previousLimit = ini_get('memory_limit');
        try {
            (new BoundedPdfParser)->inspectContent($content);
            self::fail('The compressed stream must exceed the child parser budget.');
        } catch (RuntimeException $exception) {
            self::assertSame('pdf_parser_failed', $exception->getMessage());
            self::assertSame($previousLimit, ini_get('memory_limit'));
        }
    }

    public function test_exported_user_strings_never_become_formulas_and_numbers_remain_numeric(): void
    {
        $spreadsheet = (new Spreadsheet)->setValueBinder(new ExportValueBinder);
        $sheet = $spreadsheet->getActiveSheet();
        foreach (['=HYPERLINK("https://attacker.test","x")', '=1+1', '+123', '@name', '00123', '12,50', '1e999'] as $index => $text) {
            $sheet->setCellValue('A'.($index + 1), $text);
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A'.($index + 1))->getDataType());
            self::assertSame($text, $sheet->getCell('A'.($index + 1))->getValue());
        }
        $sheet->setCellValue('B1', 123);
        $sheet->setCellValue('B2', 45.67);
        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B1')->getDataType());
        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        foreach (['12.50', '-45.67', '0.00', '123'] as $index => $number) {
            $sheet->setCellValue('C'.($index + 1), $number);
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C'.($index + 1))->getDataType());
            self::assertEquals((float) $number, $sheet->getCell('C'.($index + 1))->getValue());
        }
        $path = tempnam(sys_get_temp_dir(), 'most-export-');
        try {
            (new Xlsx($spreadsheet))->save($path);
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path) === true);
            self::assertStringNotContainsString('<f', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
            $reopened = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path);
            foreach (['12.50', '-45.67', '0.00', '123'] as $index => $number) {
                $cell = $reopened->getActiveSheet()->getCell('C'.($index + 1));
                self::assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
                self::assertEquals((float) $number, $cell->getValue());
            }
            $reopened->disconnectWorksheets();
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }
}
