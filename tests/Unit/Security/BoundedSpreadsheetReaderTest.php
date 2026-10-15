<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Import\BoundedSpreadsheetReader;
use App\Services\Import\SpreadsheetImportException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BoundedSpreadsheetReaderTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    #[DataProvider('excelFormats')]
    public function test_excel_preserves_active_sheet_calculation_formatting_and_twelve_columns(string $format): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Other sheet');
        $sheet = $book->createSheet()->setTitle('Import');
        $sheet->setCellValue('A1', 'Название');
        $sheet->setCellValue('B1', '=2+3');
        $sheet->setCellValue('C1', Date::stringToExcel('2026-10-15'));
        $sheet->getStyle('C1')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->setCellValueExplicit('L1', '00123', 's');
        $book->setActiveSheetIndex(1);
        $path = $this->path();
        IOFactory::createWriter($book, $format)->save($path);
        $book->disconnectWorksheets();
        $memoryLimit = ini_get('memory_limit');
        $reader = new BoundedSpreadsheetReader;
        $rows = $reader->read($path, strtolower($format), 'catalog');
        self::assertCount(1, $rows);
        self::assertCount(12, $rows[0]);
        self::assertSame('Название', $rows[0][0]);
        self::assertEquals(5, $rows[0][1]);
        self::assertSame('2026-10-15', $rows[0][2]);
        self::assertSame('00123', $rows[0][11]);
        $rows = $reader->read($path, strtolower($format), 'materials');
        self::assertSame('Название', $rows[1]['A']);
        self::assertEquals(5, $rows[1]['B']);
        self::assertSame('00123', $rows[1]['L']);
        self::assertSame($memoryLimit, ini_get('memory_limit'));
    }

    public static function excelFormats(): array
    {
        return [['Xlsx'], ['Xls']];
    }

    public function test_csv_profiles_keep_catalog_delimiter_detection_and_material_physical_lines(): void
    {
        $path = $this->path();
        file_put_contents($path, "\xEF\xBB\xBFname;price\r\nМатериал;12.5\r\n");
        $reader = new BoundedSpreadsheetReader;
        self::assertEquals([['name', 'price'], ['Материал', '12.5']], $reader->read($path, 'csv', 'catalog'));
        $content = "name,measurement_unit,description\r\n\"Сталь, лист\",шт,\"одна\nдве\"\r\n";
        file_put_contents($path, $content);
        self::assertSame(array_map('str_getcsv', file($path)), $reader->read($path, 'csv', 'materials'));
    }

    public function test_catalog_csv_header_starting_with_pk_is_not_mistaken_for_zip(): void
    {
        $path = $this->path();
        $content = "PK,name,description,type,unit,price,direct,overhead,profit\n00123,Материал,Описание,material,шт,12,5,0,0\n";
        file_put_contents($path, $content);

        self::assertEquals(array_map('str_getcsv', file($path)), (new BoundedSpreadsheetReader)->read($path, 'csv', 'catalog'));
    }

    #[DataProvider('oversizedCoordinates')]
    public function test_sparse_and_out_of_order_coordinates_are_rejected(string $coordinate): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Header');
        $path = $this->save($book);
        $rowNumber = preg_replace('/^[A-Z]+/', '', $coordinate);
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="'.$rowNumber.'"><c r="'.$coordinate.'" t="inlineStr"><is><t>x</t></is></c></row>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>Header</t></is></c></row></sheetData></worksheet>';
        $this->replaceEntry($path, 'xl/worksheets/sheet1.xml', $xml);
        $this->assertRejected($path);
    }

    public static function oversizedCoordinates(): array
    {
        return [['A10001'], ['DY1'], ['CX2001'], ['XFD1048576']];
    }

    public function test_container_budget_is_checked_before_reader_detection_even_with_csv_extension(): void
    {
        $path = $this->path();
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        $zip->addFromString('_rels/.rels', str_repeat(' ', 5_000_001));
        $zip->close();
        self::assertLessThan(20_000, filesize($path));
        $this->assertRejected($path, 'csv');
    }

    public function test_repeated_shared_strings_are_limited_by_expanded_result_size(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', str_repeat('x', 2000));
        $path = $this->save($book);
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        for ($row = 1; $row <= 5000; $row++) {
            $xml .= '<row r="'.$row.'"><c r="A'.$row.'" t="s"><v>0</v></c></row>';
        }
        $xml .= '</sheetData></worksheet>';
        $this->replaceEntry($path, 'xl/worksheets/sheet1.xml', $xml);
        self::assertLessThan(100_000, filesize($path));
        $this->assertRejected($path);
    }

    public function test_material_csv_row_limit_and_service_level_input_size_are_enforced(): void
    {
        $path = $this->path();
        file_put_contents($path, str_repeat("x,y\n", 10_001));
        $this->assertRejected($path, 'csv', 'materials');
        $handle = fopen($path, 'wb');
        ftruncate($handle, 2 * 1024 * 1024 + 1);
        fclose($handle);
        clearstatcache(true, $path);
        $this->assertRejected($path, 'xlsx', 'materials');
    }

    private function assertRejected(string $path, string $extension = 'xlsx', string $profile = 'catalog'): void
    {
        $memoryLimit = ini_get('memory_limit');
        try {
            (new BoundedSpreadsheetReader)->read($path, $extension, $profile);
            self::fail('Expected a bounded import rejection.');
        } catch (SpreadsheetImportException $exception) {
            self::assertSame('spreadsheet_import_rejected', $exception->getMessage());
            self::assertSame($memoryLimit, ini_get('memory_limit'));
        }
    }

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'most-import-');
        $this->paths[] = $path;

        return $path;
    }

    private function save(Spreadsheet $book): string
    {
        $path = $this->path();
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function replaceEntry(string $path, string $name, string $content): void
    {
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true);
        $zip->addFromString($name, $content);
        $zip->close();
    }
}
