<?php

declare(strict_types=1);

namespace Tests\Unit\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeTableReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class WorkVolumeTableReaderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function test_csv_preserves_decimal_strings_incomplete_rows_and_multiline_fields(): void
    {
        $path = $this->file("name;unit;quantity\nWork;м2;123456789012345678.123456\n\"Line\ncontinued\";шт\n");

        $result = (new WorkVolumeTableReader())->read($path, 'csv');

        self::assertNull($result['sheet_name']);
        self::assertSame([], $result['sheet_names']);
        self::assertSame([], $result['rows'][0]['formula_columns']);
        self::assertSame('123456789012345678.123456', $result['rows'][1]['values'][2]);
        self::assertSame(["Line\ncontinued", 'шт'], $result['rows'][2]['values']);
    }

    public function test_csv_uses_rfc4180_escaped_quotes(): void
    {
        $path = $this->file("name;unit\n\"A \"\"quoted\"\" item\";шт\n");

        self::assertSame('A "quoted" item', (new WorkVolumeTableReader())->read($path, 'csv')['rows'][1]['values'][0]);
    }

    public function test_xlsx_reads_formulas_without_calculation_and_supports_sheet_selection(): void
    {
        $path = $this->xlsx([
            'First' => '<row r="1"><c r="A1" t="inlineStr"><is><t>item</t></is></c><c r="B1" t="s"><v>0</v></c><c r="C1"><f>SUM(A1:B1)</f><v>99</v></c></row>',
            'Second' => '<row r="4"><c r="A4" t="inlineStr"><is><t>123456789012345678.123456</t></is></c></row>',
        ], ['shared']);

        $reader = new WorkVolumeTableReader();
        $result = $reader->read($path, 'xlsx', 'First');

        self::assertSame(['First', 'Second'], $result['sheet_names']);
        self::assertSame('First', $result['sheet_name']);
        self::assertSame('=SUM(A1:B1)', $result['rows'][0]['values'][2]);
        self::assertSame([2], $result['rows'][0]['formula_columns']);
        self::assertSame('123456789012345678.123456', $reader->read($path, 'xlsx', 'Second')['rows'][0]['values'][0]);
    }

    public function test_xlsx_rejects_doctype_and_unknown_sheet(): void
    {
        $path = $this->xlsx(['First' => '<!DOCTYPE foo><row r="1"/>']);
        $reader = new WorkVolumeTableReader();

        try {
            $reader->read($path, 'xlsx');
            self::fail('DOCTYPE must be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('wvs_table_xml_unsafe', $exception->getMessage());
        }

        $safe = $this->xlsx(['First' => '<row r="1"/>']);
        $this->expectExceptionObject(new InvalidArgumentException('wvs_table_sheet_not_found'));
        $reader->read($safe, 'xlsx', 'Missing');
    }

    public function test_xlsx_resolves_package_root_targets_and_namespaced_relationship_id(): void
    {
        $path = $this->xlsx(['First' => '<row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row>'], [], true);

        self::assertSame('ok', (new WorkVolumeTableReader())->read($path, 'xlsx')['rows'][0]['values'][0]);
    }

    public function test_xlsx_rejects_malformed_shared_string_reference_and_utf16_xml(): void
    {
        $path = $this->xlsx(['First' => '<row r="1"><c r="A1" t="s"><v>bad</v></c></row>']);
        $this->expectExceptionObject(new InvalidArgumentException('wvs_table_shared_string_invalid'));
        (new WorkVolumeTableReader())->read($path, 'xlsx');
    }

    public function test_xlsx_rejects_non_utf8_xml_and_formula_over_limit(): void
    {
        $encodingPath = $this->xlsx(['First' => "\xFF\xFE<row r=\"1\"/>"]);
        try {
            (new WorkVolumeTableReader())->read($encodingPath, 'xlsx');
            self::fail('Non-UTF8 XML must be rejected');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('wvs_table_xml_encoding', $exception->getMessage());
        }

        $formulaPath = $this->xlsx(['First' => '<row r="1"><c r="A1"><f>'.str_repeat('x', 10000).'</f></c></row>']);
        $this->expectExceptionObject(new InvalidArgumentException('wvs_table_cell_limit'));
        (new WorkVolumeTableReader())->read($formulaPath, 'xlsx');
    }

    public function test_xlsx_rejects_row_and_column_limits(): void
    {
        $row = '<row r="1">'.str_repeat('<c t="inlineStr"><is><t>x</t></is></c>', 129).'</row>';
        $path = $this->xlsx(['First' => $row]);
        $this->expectExceptionObject(new InvalidArgumentException('wvs_table_column_limit'));
        (new WorkVolumeTableReader())->read($path, 'xlsx');
    }

    public function test_xlsx_rejects_row_overflow_before_table_materialization(): void
    {
        $rows = str_repeat('<row><c t="inlineStr"><is><t>x</t></is></c></row>', 10001);
        $path = $this->xlsx(['First' => $rows]);

        $this->expectExceptionObject(new InvalidArgumentException('wvs_table_row_limit'));
        (new WorkVolumeTableReader())->read($path, 'xlsx');
    }

    public function test_xlsx_rejects_repeated_shared_strings_after_decoded_size_limit(): void
    {
        $rows = str_repeat('<row><c t="s"><v>0</v></c></row>', 10000);
        $path = $this->xlsx(['First' => $rows], [], false, str_repeat('x', 4000));

        $this->expectExceptionObject(new InvalidArgumentException('wvs_table_decoded_size_limit'));
        (new WorkVolumeTableReader())->read($path, 'xlsx');
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wvs-reader-');
        file_put_contents($path, "\xEF\xBB\xBF".$contents);
        $this->files[] = $path;
        return $path;
    }

    private function xlsx(array $sheets, array $shared = [], bool $absoluteTarget = false, string $sharedValue = 'shared'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wvs-reader-').'.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="xl/workbook.xml" Type="officeDocument"/></Relationships>');
        $sheetNodes = $rels = '';
        $index = 1;
        foreach ($sheets as $name => $xml) {
            $sheetNodes .= '<sheet name="'.$name.'" sheetId="'.$index.'" x:id="rId'.$index.'"/>';
            $target = $absoluteTarget ? '/xl/worksheets/sheet'.$index.'.xml' : 'worksheets/sheet'.$index.'.xml';
            $rels .= '<Relationship Id="rId'.$index.'" Target="'.$target.'" Type="worksheet"/>';
            $zip->addFromString('xl/worksheets/sheet'.$index.'.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$xml.'</worksheet>');
            $index++;
        }
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:x="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetNodes.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>'.$sharedValue.'</t></si></sst>');
        $zip->close();
        $this->files[] = $path;
        return $path;
    }
}
