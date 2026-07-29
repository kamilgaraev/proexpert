<?php

declare(strict_types=1);

namespace Tests\Performance\Reporting;

require_once dirname(__DIR__, 2).'/Unit/Reporting/Exports/CsvReportExportRendererTest.php';

use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocument;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\DompdfReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use Tests\Unit\Reporting\Exports\HashingReportArtifactStream;
use Tests\Unit\Reporting\Exports\ReportExportRendererTestCase;

/** @implements \Iterator<int, ReportRowChunk> */
final class InstrumentedReportChunkSource implements \Iterator
{
    private int $emittedRows = 0;

    private int $activeRows = 0;

    private int $peakRetainedRows = 0;

    /** @var list<int> */
    private array $chunkSizes = [];

    /** @var list<int> */
    private array $sourceReadCounts = [];

    private int $collectibleChunkChecks = 0;

    private int $offset = 0;

    private int $position = 0;

    private ?ReportRowChunk $currentChunk = null;

    public function __construct(
        private readonly ReportRunExportSource $source,
        private readonly int $rowCount,
        private readonly int $chunkSize,
    ) {
    }

    /** @return iterable<ReportRowChunk> */
    public function chunks(): iterable
    {
        return $this;
    }

    public function current(): ReportRowChunk
    {
        if (!$this->currentChunk instanceof ReportRowChunk) {
            throw new \LogicException('report_chunk_iterator_not_valid');
        }

        return $this->currentChunk;
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        $this->releaseCurrent();
        $this->offset += $this->chunkSize;
        $this->position++;
        $this->loadCurrent();
    }

    public function rewind(): void
    {
        if ($this->currentChunk instanceof ReportRowChunk) {
            $this->releaseCurrent();
        }
        $this->offset = 0;
        $this->position = 0;
        $this->loadCurrent();
    }

    public function valid(): bool
    {
        return $this->currentChunk instanceof ReportRowChunk;
    }

    public function emittedRows(): int
    {
        return $this->emittedRows;
    }

    public function activeRows(): int
    {
        return $this->activeRows;
    }

    public function peakRetainedRows(): int
    {
        return $this->peakRetainedRows;
    }

    /** @return list<int> */
    public function chunkSizes(): array
    {
        return $this->chunkSizes;
    }

    /** @return list<int> */
    public function sourceReadCounts(): array
    {
        return $this->sourceReadCounts;
    }

    public function collectibleChunkChecks(): int
    {
        return $this->collectibleChunkChecks;
    }

    private function loadCurrent(): void
    {
        if ($this->offset >= $this->rowCount) {
            $this->currentChunk = null;
            $this->activeRows = 0;

            return;
        }

        $this->currentChunk = $this->readChunk($this->offset);
        $this->activeRows = count($this->currentChunk->rows);
        $this->peakRetainedRows = max($this->peakRetainedRows, $this->activeRows);
        $this->chunkSizes[] = $this->activeRows;
        $this->emittedRows += $this->activeRows;
    }

    private function releaseCurrent(): void
    {
        if (!$this->currentChunk instanceof ReportRowChunk) {
            return;
        }

        $chunkReference = \WeakReference::create($this->currentChunk);
        $rowReferences = array_map(
            static fn (object $row): \WeakReference => \WeakReference::create($row),
            $this->currentChunk->rows,
        );
        $this->currentChunk = null;
        $this->activeRows = 0;
        gc_collect_cycles();
        foreach ($rowReferences as $rowReference) {
            if ($rowReference->get() !== null) {
                throw new \RuntimeException('previous_chunk_retained');
            }
        }
        if ($chunkReference->get() !== null) {
            throw new \RuntimeException('previous_chunk_retained');
        }
        $this->collectibleChunkChecks++;
    }

    private function readChunk(int $offset): ReportRowChunk
    {
        $chunkIndex = intdiv($offset, $this->chunkSize);
        $this->sourceReadCounts[$chunkIndex] = ($this->sourceReadCounts[$chunkIndex] ?? 0) + 1;
        $size = min($this->chunkSize, $this->rowCount - $offset);
        $rows = [];

        for ($index = 0; $index < $size; $index++) {
            $number = $offset + $index;
            $rows[] = new ReportCursorRow(
                'row-'.$number,
                [
                    'name' => 'Строка '.$number,
                    'amount' => $number.'.25',
                    'date' => '2026-07-29',
                ],
                $this->source->snapshot->id,
                $this->source->run->queryHash,
                $this->source->snapshot->sourceHash,
            );
        }

        return new ReportRowChunk($rows);
    }
}

final class ReportExportStreamingBudgetTest extends ReportExportRendererTestCase
{
    public function test_csv_and_xlsx_stream_50000_rows_in_lazy_500_row_chunks_under_128_mib(): void
    {
        [$source, $definition] = $this->source(50_000);

        foreach ([
            'csv' => (new CsvReportExportRenderer())->forDefinition($definition),
            'xlsx' => (new XlsxReportExportRenderer())->forDefinition($definition),
        ] as $format => $renderer) {
            $provider = new InstrumentedReportChunkSource($source, 50_000, 500);
            $chunks = $provider->chunks();
            self::assertSame(0, $provider->emittedRows());
            self::assertSame([], $provider->sourceReadCounts());
            $stream = new HashingReportArtifactStream();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $memoryAtStart = memory_get_usage(true);

            $count = $renderer->render($source, $this->data($format), $chunks, $stream);
            $memoryDelta = max(0, memory_get_peak_usage(true) - $memoryAtStart);

            self::assertSame(50_000, $count);
            self::assertSame(50_000, $provider->emittedRows());
            self::assertCount(100, $provider->chunkSizes());
            self::assertLessThanOrEqual(500, max($provider->chunkSizes()));
            self::assertLessThanOrEqual(4, max($provider->sourceReadCounts()));
            self::assertSame(500, $provider->peakRetainedRows());
            self::assertSame(0, $provider->activeRows());
            self::assertSame(100, $provider->collectibleChunkChecks());
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
            strlen('%PDF bounded'),
            128 * 1024 * 1024,
        );
        $events = [];
        $renderedHtmlBytes = 0;
        $documentRenderer = new DompdfReportPdfDocumentRenderer(
            htmlRenderer: function (ReportPdfDocument $document) use (&$renderedHtmlBytes): string {
                $html = $this->renderBlade($document);
                $renderedHtmlBytes = strlen($html);

                return $html;
            },
            documentLoader: static function () use (&$events): object {
                $events[] = 'render';

                return new \stdClass();
            },
            pageCounter: static function () use (&$events): int {
                $events[] = 'pages';

                return 100;
            },
            outputRenderer: static function () use (&$events): string {
                $events[] = 'output';

                return '%PDF bounded';
            },
        );
        $renderer = (new PdfReportExportRenderer(
            new ReportPdfDocumentBuilder(ReportExportLimits::pdf()),
            $documentRenderer,
            [PdfReportExportRenderer::budgetKey(
                $definition->definitionHash->value,
                $definition->definition->rendererVersion,
            ) => $budget],
        ))->forDefinition($definition);
        $provider = new InstrumentedReportChunkSource($source, 5000, 500);
        $chunks = $provider->chunks();
        self::assertSame(0, $provider->emittedRows());
        $stream = new HashingReportArtifactStream();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $memoryAtStart = memory_get_usage(true);

        $count = $renderer->render($source, $this->data('pdf'), $chunks, $stream);
        $memoryDelta = max(0, memory_get_peak_usage(true) - $memoryAtStart);

        self::assertSame(5000, $count);
        self::assertSame(5000, $provider->emittedRows());
        self::assertSame(array_fill(0, 10, 500), $provider->chunkSizes());
        self::assertSame(array_fill(0, 10, 1), $provider->sourceReadCounts());
        self::assertSame(500, $provider->peakRetainedRows());
        self::assertSame(0, $provider->activeRows());
        self::assertSame(10, $provider->collectibleChunkChecks());
        self::assertGreaterThan(0, $renderedHtmlBytes);
        self::assertLessThanOrEqual($budget->maxHtmlBytes, $renderedHtmlBytes);
        self::assertSame(['render', 'pages', 'output'], $events);
        self::assertLessThanOrEqual($budget->maxMemoryDeltaBytes, $memoryDelta);
        self::assertSame(strlen('%PDF bounded'), $stream->size());
    }

    public function test_adversarial_probe_rejects_eager_chunk_materialization(): void
    {
        [$source] = $this->source(1000);
        $counterProbe = new InstrumentedReportChunkSource($source, 1000, 500);

        $counterProbe->rewind();
        $counterProbe->rewind();

        self::assertSame(2, $counterProbe->sourceReadCounts()[0]);

        $provider = new InstrumentedReportChunkSource($source, 1000, 500);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous_chunk_retained');

        iterator_to_array($provider->chunks(), false);
    }

    public function test_adversarial_probe_rejects_retained_row_without_chunk(): void
    {
        [$source] = $this->source(1000);
        $provider = new InstrumentedReportChunkSource($source, 1000, 500);
        $retainedRows = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('previous_chunk_retained');

        foreach ($provider->chunks() as $chunk) {
            $retainedRows[] = $chunk->rows[250];
            unset($chunk);
        }
    }
}
