<?php

declare(strict_types=1);

namespace Tests\Performance\Reporting;

require_once dirname(__DIR__, 2).'/Unit/Reporting/Exports/CsvReportExportRendererTest.php';

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use Tests\Unit\Reporting\Exports\FakeReportPdfDocumentRenderer;
use Tests\Unit\Reporting\Exports\HashingReportArtifactStream;
use Tests\Unit\Reporting\Exports\ReportExportRendererTestCase;

final class ReportExportStreamingBudgetTest extends ReportExportRendererTestCase
{
    public function test_csv_and_xlsx_stream_50000_rows_in_lazy_500_row_chunks_under_128_mib(): void
    {
        [$source, $definition] = $this->source(50_000);

        foreach ([
            'csv' => (new CsvReportExportRenderer())->forDefinition($definition),
            'xlsx' => (new XlsxReportExportRenderer())->forDefinition($definition),
        ] as $format => $renderer) {
            $chunkReads = [];
            $chunks = $this->generatedChunks(
                $source,
                50_000,
                500,
                static function (int $size) use (&$chunkReads): void {
                    $chunkReads[] = ['rows' => $size, 'source_reads' => 1];
                },
            );
            self::assertSame([], $chunkReads);
            $stream = new HashingReportArtifactStream();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $memoryAtStart = memory_get_usage(true);

            $count = $renderer->render($source, $this->data($format), $chunks, $stream);
            $memoryDelta = max(0, memory_get_peak_usage(true) - $memoryAtStart);

            self::assertSame(50_000, $count);
            self::assertCount(100, $chunkReads);
            self::assertLessThanOrEqual(500, max(array_column($chunkReads, 'rows')));
            self::assertLessThanOrEqual(4, max(array_column($chunkReads, 'source_reads')));
            self::assertLessThanOrEqual(128 * 1024 * 1024, $memoryDelta);
            self::assertGreaterThan(0, $stream->size());
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $stream->checksum());
        }
    }

    public function test_pdf_keeps_only_the_locked_5000_row_document_inside_hard_caps(): void
    {
        [$source, $definition] = $this->source(5000);
        $budget = new ReportPdfRenderBudget(
            5000,
            100,
            16 * 1024 * 1024,
            8 * 1024 * 1024,
            128 * 1024 * 1024,
        );
        $documentRenderer = new FakeReportPdfDocumentRenderer('%PDF bounded');
        $renderer = (new PdfReportExportRenderer(
            new ReportPdfDocumentBuilder(ReportExportLimits::pdf()),
            $documentRenderer,
            [PdfReportExportRenderer::budgetKey(
                $definition->definitionHash->value,
                $definition->definition->rendererVersion,
            ) => $budget],
        ))->forDefinition($definition);
        $chunkSizes = [];
        $chunks = $this->generatedChunks(
            $source,
            5000,
            500,
            static function (int $size) use (&$chunkSizes): void {
                $chunkSizes[] = $size;
            },
        );
        $stream = new HashingReportArtifactStream();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $memoryAtStart = memory_get_usage(true);

        $count = $renderer->render($source, $this->data('pdf'), $chunks, $stream);
        $memoryDelta = max(0, memory_get_peak_usage(true) - $memoryAtStart);

        self::assertSame(5000, $count);
        self::assertSame(array_fill(0, 10, 500), $chunkSizes);
        self::assertSame(5000, $documentRenderer->document?->detailRowCount());
        self::assertSame(5000, $budget->maxDetailRows);
        self::assertSame(100, $budget->maxPages);
        self::assertSame(16 * 1024 * 1024, $budget->maxHtmlBytes);
        self::assertSame(8 * 1024 * 1024, $budget->maxPdfBytes);
        self::assertLessThanOrEqual($budget->maxMemoryDeltaBytes, $memoryDelta);
        self::assertSame(strlen('%PDF bounded'), $stream->size());
    }
}
