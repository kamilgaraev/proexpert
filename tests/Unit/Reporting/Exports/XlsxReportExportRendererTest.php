<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

require_once __DIR__.'/CsvReportExportRendererTest.php';

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use Throwable;
use ZipArchive;

final class XlsxReportExportRendererTest extends ReportExportRendererTestCase
{
    public function test_streams_minimal_ooxml_with_inline_strings_and_numeric_fidelity(): void
    {
        [$source] = $this->source(2, ['amount' => '13.75']);
        $stream = new InMemoryReportArtifactStream();

        $count = (new XlsxReportExportRenderer())->render(
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
    }

    public function test_archive_checksum_is_stable_and_cancellation_stops_before_next_chunk(): void
    {
        [$source] = $this->source(2);
        $chunks = [
            $this->chunk($source, [['name' => 'A', 'amount' => '1', 'date' => '2026-07-29']]),
            $this->chunk($source, [['name' => 'B', 'amount' => '2', 'date' => '2026-07-30']], 1),
        ];
        $first = new InMemoryReportArtifactStream();
        $second = new InMemoryReportArtifactStream();
        $renderer = new XlsxReportExportRenderer();
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
        $stream = new InMemoryReportArtifactStream();
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
        $zip = new ZipArchive();
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
