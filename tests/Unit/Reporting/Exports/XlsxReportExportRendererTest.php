<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

require_once __DIR__.'/CsvReportExportRendererTest.php';

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportStore;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Throwable;
use ZipArchive;

final class XlsxReportExportRendererTest extends ReportExportRendererTestCase
{
    public static function printCases(): array
    {
        return [
            'empty-thirteen' => [13, 0],
            'long-thirteen' => [13, 1],
            'many-thirteen' => [13, 45],
            'wide-twenty-seven' => [27, 2],
            'small-three' => [3, 2],
        ];
    }

    #[DataProvider('printCases')]
    public function test_print_layout_preserves_all_selected_columns_and_full_values(int $columnCount, int $rowCount): void
    {
        $labels = ['Договор', 'Сумма договора', 'Дата отчёта', 'Проект', 'Контрагент', 'Валюта', 'Направление расчётов', 'Принято работ', 'Оплачено', 'Сальдо расчётов', 'Неоплаченные обязательства', 'Неисполненные обязательства', 'Срок задолженности'];
        $values = [
            str_repeat('УЧ-102 — учебный договор ремонта офиса ', 3), '123456.78', '2026-10-23',
            str_repeat('Учебный проект ремонта офиса ', 3), str_repeat('Учебный подрядчик по ремонту офиса ', 4), 'RUB',
            'Расход', '100000.00', '0.00', '23456.78', '23456.78', '23456.78', 'До 30 дней',
        ];
        $schema = [];
        $row = [];
        for ($index = 0; $index < $columnCount; $index++) {
            $id = ['name', 'amount', 'date'][$index] ?? 'column_'.$index;
            $schema[] = ['id' => $id, 'labels' => ['ru-RU' => $labels[$index] ?? 'Дополнительная колонка '.($index + 1)]];
            $row[$id] = $values[$index] ?? 'Учебное значение '.($index + 1);
        }
        [$source, $definition] = $this->source($rowCount, rowSchema: $schema, rendererVersion: '1.1.0', reportCode: 'contract_settlement_exposure');
        $stream = new InMemoryReportArtifactStream;
        $rows = array_fill(0, $rowCount, $row);
        (new XlsxReportExportRenderer)->forDefinition($definition)->render($source, $this->data('xlsx', array_column($schema, 'id')), $rows === [] ? [] : [$this->chunk($source, $rows)], $stream);
        if ($directory = getenv('MOST_REPORT_XLSX_PRINT_QA_DIR')) {
            file_put_contents($directory.'/report-'.$columnCount.'-columns-'.$rowCount.'-rows.xlsx', $stream->bytes());
        }
        $path = tempnam(sys_get_temp_dir(), 'report-print-');
        self::assertIsString($path);
        try {
            file_put_contents($path, $stream->bytes());
            $sheet = IOFactory::load($path)->getActiveSheet();
            self::assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
            self::assertSame(0, $sheet->getPageSetup()->getFitToHeight());
            self::assertGreaterThanOrEqual(1, $sheet->getPageSetup()->getFitToWidth());
            self::assertSame([1, 1], array_map('intval', $sheet->getPageSetup()->getRowsToRepeatAtTop()));
            self::assertSame('A2', $sheet->getFreezePane());
            self::assertSame('Arial', $sheet->getStyle('A1')->getFont()->getName());
            self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
            self::assertTrue($sheet->getStyle('A1')->getAlignment()->getWrapText());
            self::assertCount($columnCount, $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0]);
            foreach ($schema as $index => $column) {
                self::assertSame($column['labels']['ru-RU'], (string) $sheet->getCell([$index + 1, 1])->getValue());
            }
            if ($rowCount > 0) {
                self::assertSame($row['name'], (string) $sheet->getCell('A'.($rowCount + 1))->getValue());
                self::assertSame(123456.78, $sheet->getCell('B'.($rowCount + 1))->getValue());
                self::assertSame('2026-10-23', (string) $sheet->getCell('C'.($rowCount + 1))->getValue());
                self::assertTrue($sheet->getStyle('A2')->getAlignment()->getWrapText());
                self::assertNotSame(0, $sheet->getCell('A2')->getXfIndex());
            }
            if ($columnCount === 27) {
                self::assertSame('Учебное значение 27', (string) $sheet->getCell('AA3')->getValue());
                self::assertGreaterThan(1, $sheet->getPageSetup()->getFitToWidth());
            }
        } finally {
            unlink($path);
        }
    }

    public static function legacyDefinitions(): array
    {
        return [
            ['contract_settlement_exposure', '1.0.0'],
            ['other_report', '1.1.0'],
            ['contract_settlement_exposure', 'unregistered-version'],
        ];
    }

    public function test_renderer_transition_changes_export_identity_without_a_hidden_hash_suffix(): void
    {
        $store = (new ReflectionClass(EloquentReportExportStore::class))->newInstanceWithoutConstructor();
        $identity = (new ReflectionClass(EloquentReportExportStore::class))->getMethod('identity');
        [$old] = $this->source(0, rendererVersion: '1.0.0', reportCode: 'contract_settlement_exposure');
        [$current] = $this->source(0, rendererVersion: '1.1.0', reportCode: 'contract_settlement_exposure');
        $data = $this->data('xlsx');
        $oldIdentity = $identity->invoke($store, $old, $data, $data->columns);
        $currentIdentity = $identity->invoke($store, $current, $data, $data->columns);
        self::assertNotSame($oldIdentity['export_hash'], $currentIdentity['export_hash']);
        self::assertNotSame($oldIdentity['input_fingerprint'], $currentIdentity['input_fingerprint']);
        self::assertSame($currentIdentity, $identity->invoke($store, $current, $data, $data->columns));
    }

    #[DataProvider('legacyDefinitions')]
    public function test_existing_renderer_versions_preserve_the_original_archive_entries(string $code, string $version): void
    {
        [$source, $definition] = $this->source(1, rendererVersion: $version, reportCode: $code);
        $stream = new InMemoryReportArtifactStream;
        (new XlsxReportExportRenderer)->forDefinition($definition)->render(
            $source,
            $this->data('xlsx', ['name'], 'en-US'),
            [$this->chunk($source, [['name' => 'Original value']])],
            $stream,
        );
        $entries = $this->archiveEntries($stream->bytes());
        self::assertSame(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" s="1" t="inlineStr"><is><t>Name</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Original value</t></is></c></row>'
            .'</sheetData></worksheet>',
            $entries['xl/worksheets/sheet1.xml'],
        );
        self::assertSame(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
            $entries['xl/workbook.xml'],
        );
        self::assertSame(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font/><font><b/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/></cellXfs></styleSheet>',
            $entries['xl/styles.xml'],
        );
    }

    public function test_accepted_excel_headers_are_localized_without_mutating_persisted_schema(): void
    {
        $schema = [['id' => 'accepted_quantity'], ['id' => 'event_status'], ['id' => 'recognized_on']];
        [$source] = $this->source(0, rowSchema: $schema, reportCode: 'accepted_production_progress');
        $stream = new InMemoryReportArtifactStream;
        (new XlsxReportExportRenderer)->render($source, $this->data('xlsx', array_column($schema, 'id')), [], $stream);
        $xml = $this->archiveEntries($stream->bytes())['xl/worksheets/sheet1.xml'];
        foreach (['Принятый объём', 'Состояние приёмки', 'Дата признания'] as $label) {
            self::assertStringContainsString('<t>'.$label.'</t>', $xml);
        }
        self::assertSame($schema, $source->result->rowSchema);
    }

    public function test_streams_minimal_ooxml_with_inline_strings_and_numeric_fidelity(): void
    {
        [$source] = $this->source(2, ['amount' => '13.75']);
        $stream = new InMemoryReportArtifactStream;

        $count = (new XlsxReportExportRenderer)->render(
            $source,
            $this->data('xlsx', ['name', 'amount', 'date'], 'en-US'),
            [$this->chunk($source, [
                ['name' => '=1+1', 'amount' => '12.50', 'date' => '2026-07-29'],
                ['name' => 'Кран', 'amount' => -1.25, 'date' => '2026-07-30'],
            ])],
            $stream,
        );

        self::assertSame(2, $count);
        $entries = $this->archiveEntries($stream->bytes());
        self::assertArrayNotHasKey('xl/sharedStrings.xml', $entries);
        self::assertStringContainsString('t="inlineStr"', $entries['xl/worksheets/sheet1.xml']);
        self::assertStringContainsString('<t>=1+1</t>', $entries['xl/worksheets/sheet1.xml']);
        self::assertStringContainsString('t="n"><v>12.50</v>', $entries['xl/worksheets/sheet1.xml']);
        self::assertStringContainsString('t="n"><v>-1.25</v>', $entries['xl/worksheets/sheet1.xml']);
        self::assertStringContainsString('<t>2026-07-29</t>', $entries['xl/worksheets/sheet1.xml']);
        self::assertStringContainsString('<t>Кран</t>', $entries['xl/worksheets/sheet1.xml']);
        self::assertStringContainsString('<t>Total</t>', $entries['xl/worksheets/sheet1.xml']);
    }

    public function test_streams_structured_cells_as_canonical_json(): void
    {
        [$source] = $this->source(1);
        $stream = new InMemoryReportArtifactStream;

        $count = (new XlsxReportExportRenderer)->render(
            $source,
            $this->data('xlsx', ['name', 'amount'], 'en-US'),
            [$this->chunk($source, [[
                'name' => ['z' => 1, 'a' => ['formula' => '=1+1']],
                'amount' => ['items' => [null, 2.0]],
                'date' => '2026-07-29',
            ]])],
            $stream,
        );

        self::assertSame(1, $count);
        $entries = $this->archiveEntries($stream->bytes());
        self::assertStringContainsString(
            '<t>{&quot;a&quot;:{&quot;formula&quot;:&quot;=1+1&quot;},&quot;z&quot;:1}</t>',
            $entries['xl/worksheets/sheet1.xml'],
        );
        self::assertStringContainsString(
            '<t>{&quot;items&quot;:[null,2.0]}</t>',
            $entries['xl/worksheets/sheet1.xml'],
        );
    }

    public function test_archive_checksum_is_stable_and_cancellation_stops_before_next_chunk(): void
    {
        [$source] = $this->source(2);
        $chunks = [
            $this->chunk($source, [['name' => 'A', 'amount' => '1', 'date' => '2026-07-29']]),
            $this->chunk($source, [['name' => 'B', 'amount' => '2', 'date' => '2026-07-30']], 1),
        ];
        $first = new InMemoryReportArtifactStream;
        $second = new InMemoryReportArtifactStream;
        $renderer = new XlsxReportExportRenderer;
        $renderer->render($source, $this->data('xlsx'), $chunks, $first);
        $renderer->render($source, $this->data('xlsx'), $chunks, $second);

        self::assertSame(hash('sha256', $first->bytes()), hash('sha256', $second->bytes()));

        $cancelled = new InMemoryReportArtifactStream(2);
        try {
            $renderer->render($source, $this->data('xlsx'), $chunks, $cancelled);
            self::fail('Expected cancellation to fail closed.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertSame('', $cancelled->bytes());
    }

    public function test_sheet_row_column_and_final_byte_limits_fail_before_artifact_write(): void
    {
        [$source] = $this->source(2);
        $rows = [$this->chunk($source, [
            ['name' => 'A', 'amount' => '1', 'date' => '2026-07-29'],
            ['name' => 'B', 'amount' => '2', 'date' => '2026-07-30'],
        ])];
        $stream = new InMemoryReportArtifactStream;
        $sheetLimited = new XlsxReportExportRenderer(new ReportExportLimits(2, 3, 1_000_000, 2, 60));

        try {
            $sheetLimited->render($source, $this->data('xlsx'), $rows, $stream);
            self::fail('Expected sheet limit.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertSame('', $stream->bytes());

        $byteLimited = new XlsxReportExportRenderer(new ReportExportLimits(2, 3, 50, 4, 60));
        try {
            $byteLimited->render($source, $this->data('xlsx'), $rows, $stream);
            self::fail('Expected byte limit.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertSame('', $stream->bytes());
    }

    /** @return array<string, string> */
    private function archiveEntries(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'most-xlsx-test-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true);
        $entries = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                self::assertIsString($name);
                $content = $zip->getFromIndex($index);
                self::assertIsString($content);
                $entries[$name] = $content;
            }
        } finally {
            $zip->close();
            @unlink($path);
        }

        return $entries;
    }
}
