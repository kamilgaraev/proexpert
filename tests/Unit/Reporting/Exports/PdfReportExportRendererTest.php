<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

require_once __DIR__.'/CsvReportExportRendererTest.php';

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocument;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\DompdfReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use RuntimeException;
use stdClass;
use Throwable;

final class PdfReportExportRendererTest extends ReportExportRendererTestCase
{
    public function test_builds_bounded_semantic_document_and_writes_exact_pdf_artifact(): void
    {
        [$source, $definition] = $this->source(
            2,
            ['amount' => '13.75'],
            official: true,
        );
        $budget = new ReportPdfRenderBudget(5000, 20, 2_000_000, 2_000_000, 128 * 1024 * 1024);
        $documentRenderer = new FakeReportPdfDocumentRenderer('%PDF-1.7 exact-artifact');
        $renderer = $this->pdf($definition, $documentRenderer, $budget);
        $stream = new InMemoryReportArtifactStream();

        $count = $renderer->render(
            $source,
            $this->data('pdf', ['amount', 'name']),
            [$this->chunk($source, [
                ['name' => 'Кран', 'amount' => '12.50', 'date' => '2026-07-29'],
                ['name' => 'Бетон', 'amount' => 1.25, 'date' => '2026-07-30'],
            ])],
            $stream,
        );

        self::assertSame(2, $count);
        self::assertSame(PdfReportExportRenderer::MIME_TYPE, 'application/pdf');
        self::assertSame('%PDF-1.7 exact-artifact', $stream->bytes());
        self::assertSame(strlen($stream->bytes()), 23);
        self::assertSame(hash('sha256', '%PDF-1.7 exact-artifact'), hash('sha256', $stream->bytes()));

        $document = $documentRenderer->document;
        self::assertInstanceOf(ReportPdfDocument::class, $document);
        self::assertSame(
            [['id' => 'amount', 'label' => 'Сумма'], ['id' => 'name', 'label' => 'Название']],
            $document->headers,
        );
        self::assertSame([['12.50', 'Кран'], ['1.25', 'Бетон']], $document->rows);
        self::assertSame(['amount' => '13.75'], $document->totals);
        self::assertSame($source->resultHash->value, $document->metadata['result_hash']);
        self::assertSame($source->snapshot->sourceHash->value, $document->metadata['source_hash']);
        self::assertSame(['amount'], $document->metadata['output_classification']['sensitive_columns']);
        self::assertSame(['name'], $document->metadata['output_classification']['audit_columns']);
        self::assertSame($source->snapshot->seal?->signature, $document->metadata['snapshot']['seal']['signature']);
        self::assertStringNotContainsString(
            (string) $source->snapshot->seal?->signature,
            implode("\n", array_merge(...$document->rows)),
        );
    }

    public function test_accepts_5000_rows_and_rejects_5001_before_document_renderer(): void
    {
        $budget = new ReportPdfRenderBudget(5000, 100, 20_000_000, 2_000_000, 128 * 1024 * 1024);
        [$acceptedSource, $definition] = $this->source(5000);
        $acceptedDocumentRenderer = new FakeReportPdfDocumentRenderer();
        $accepted = $this->pdf($definition, $acceptedDocumentRenderer, $budget);
        $acceptedStream = new InMemoryReportArtifactStream();

        self::assertSame(
            5000,
            $accepted->render(
                $acceptedSource,
                $this->data('pdf'),
                $this->generatedChunks($acceptedSource, 5000, 500),
                $acceptedStream,
            ),
        );
        self::assertSame(1, $acceptedDocumentRenderer->calls);

        [$rejectedSource, $rejectedDefinition] = $this->source(5001);
        $rejectedDocumentRenderer = new FakeReportPdfDocumentRenderer();
        $rejected = $this->pdf($rejectedDefinition, $rejectedDocumentRenderer, $budget);
        try {
            $rejected->render(
                $rejectedSource,
                $this->data('pdf'),
                $this->generatedChunks($rejectedSource, 5001, 500),
                new InMemoryReportArtifactStream(),
            );
            self::fail('Expected row 5001 to fail.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertSame(0, $rejectedDocumentRenderer->calls);
    }

    public function test_oversized_scalar_fails_before_document_renderer_and_artifact_write(): void
    {
        [$source, $definition] = $this->source(1);
        $budget = new ReportPdfRenderBudget(1, 10, 64 * 1024, 1024, 128 * 1024 * 1024);
        $documentRenderer = new FakeReportPdfDocumentRenderer();
        $stream = new InMemoryReportArtifactStream();

        try {
            $this->pdf($definition, $documentRenderer, $budget)->render(
                $source,
                $this->data('pdf'),
                [$this->chunk($source, [[
                    'name' => str_repeat('"&', 100_000),
                    'amount' => '1',
                    'date' => '2026-07-29',
                ]])],
                $stream,
            );
            self::fail('Expected projected HTML limit before document materialization.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }

        self::assertSame(0, $documentRenderer->calls);
        self::assertSame('', $stream->bytes());
    }

    public function test_oversized_total_fails_incrementally_without_full_json_copy(): void
    {
        [$source, $definition] = $this->source(1, ['name' => str_repeat('"', 4 * 1024 * 1024)]);
        $budget = new ReportPdfRenderBudget(1, 10, 64 * 1024, 1024, 128 * 1024 * 1024);
        $documentRenderer = new FakeReportPdfDocumentRenderer();
        $stream = new InMemoryReportArtifactStream();
        $chunk = $this->chunk($source, [[
            'name' => 'A',
            'amount' => '1',
            'date' => '2026-07-29',
        ]]);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $memoryAtStart = memory_get_usage(true);

        try {
            $this->pdf($definition, $documentRenderer, $budget)->render(
                $source,
                $this->data('pdf'),
                [$chunk],
                $stream,
            );
            self::fail('Expected projected total limit.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }

        self::assertLessThanOrEqual(4 * 1024 * 1024, memory_get_peak_usage(true) - $memoryAtStart);
        self::assertSame(0, $documentRenderer->calls);
        self::assertSame('', $stream->bytes());
    }

    public function test_html_page_pdf_and_peak_memory_boundaries_are_exact(): void
    {
        $document = new ReportPdfDocument(
            [['id' => 'name', 'label' => 'Name']],
            [['A']],
            [],
            ['locale' => 'en-US'],
        );
        $budget = new ReportPdfRenderBudget(1, 2, 4, 5, 19);
        $adapter = new DompdfReportPdfDocumentRenderer(
            htmlRenderer: static fn (): string => 'html',
            documentLoader: static fn (): object => new stdClass(),
            pageCounter: static fn (): int => 2,
            outputRenderer: static fn (): string => '12345',
            memoryUsage: static fn (): int => 100,
            memoryPeak: static fn (): int => 110,
        );
        self::assertSame('12345', $adapter->render($document, $budget));

        $cases = [
            'html' => new DompdfReportPdfDocumentRenderer(
                htmlRenderer: static fn (): string => 'html!',
                documentLoader: static fn (): object => new stdClass(),
                pageCounter: static fn (): int => 2,
                outputRenderer: static fn (): string => '12345',
                memoryUsage: static fn (): int => 100,
                memoryPeak: static fn (): int => 110,
            ),
            'pages' => new DompdfReportPdfDocumentRenderer(
                htmlRenderer: static fn (): string => 'html',
                documentLoader: static fn (): object => new stdClass(),
                pageCounter: static fn (): int => 3,
                outputRenderer: static fn (): string => '12345',
                memoryUsage: static fn (): int => 100,
                memoryPeak: static fn (): int => 110,
            ),
            'pdf bytes' => new DompdfReportPdfDocumentRenderer(
                htmlRenderer: static fn (): string => 'html',
                documentLoader: static fn (): object => new stdClass(),
                pageCounter: static fn (): int => 2,
                outputRenderer: static fn (): string => '123456',
                memoryUsage: static fn (): int => 100,
                memoryPeak: static fn (): int => 110,
            ),
            'memory' => new DompdfReportPdfDocumentRenderer(
                htmlRenderer: static fn (): string => 'html',
                documentLoader: static fn (): object => new stdClass(),
                pageCounter: static fn (): int => 2,
                outputRenderer: static fn (): string => '12345',
                memoryUsage: static fn (): int => 100,
                memoryPeak: static fn (): int => 111,
            ),
        ];

        foreach ($cases as $name => $failing) {
            try {
                $failing->render($document, $budget);
                self::fail('Expected '.$name.' boundary failure.');
            } catch (Throwable $exception) {
                $this->assertLimit($exception);
            }
        }
    }

    public function test_page_limit_is_checked_before_output_stage(): void
    {
        $events = [];
        $adapter = new DompdfReportPdfDocumentRenderer(
            htmlRenderer: static fn (): string => 'html',
            documentLoader: static function () use (&$events): object {
                $events[] = 'render';

                return new stdClass();
            },
            pageCounter: static function () use (&$events): int {
                $events[] = 'pages';

                return 3;
            },
            outputRenderer: static function () use (&$events): string {
                $events[] = 'output';

                return 'must-not-be-created';
            },
            memoryUsage: static fn (): int => 100,
            memoryPeak: static fn (): int => 100,
        );
        $document = new ReportPdfDocument(
            [['id' => 'name', 'label' => 'Name']],
            [['A']],
            [],
            ['locale' => 'en-US'],
        );

        try {
            $adapter->render($document, new ReportPdfRenderBudget(1, 2, 1024, 1024, 4096));
            self::fail('Expected page cap.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }

        self::assertSame(['render', 'pages'], $events);
    }

    public function test_real_blade_and_dompdf_path_accepts_exact_html_boundary(): void
    {
        [$source] = $this->source(1, ['amount' => '1']);
        $budget = new ReportPdfRenderBudget(1, 10, 2 * 1024 * 1024, 2 * 1024 * 1024, 128 * 1024 * 1024);
        $document = (new ReportPdfDocumentBuilder(ReportExportLimits::pdf()))->build(
            $source,
            $this->data('pdf'),
            [$this->chunk($source, [[
                'name' => 'Кран',
                'amount' => '1',
                'date' => '2026-07-29',
            ]])],
            $budget,
            new InMemoryReportArtifactStream(),
        );
        $html = $this->renderBlade($document);
        $exactBudget = new ReportPdfRenderBudget(
            1,
            10,
            strlen($html),
            2 * 1024 * 1024,
            128 * 1024 * 1024,
        );
        $bytes = (new DompdfReportPdfDocumentRenderer(
            htmlRenderer: static fn (): string => $html,
            documentLoader: static function (string $html): object {
                $dompdf = new \Dompdf\Dompdf();
                $dompdf->loadHtml($html);
                $dompdf->render();

                return $dompdf;
            },
        ))->render($document, $exactBudget);

        self::assertStringStartsWith('%PDF-', $bytes);

        $documentLoads = 0;
        $capped = new DompdfReportPdfDocumentRenderer(
            htmlRenderer: static fn (): string => $html,
            documentLoader: static function () use (&$documentLoads): object {
                $documentLoads++;

                return new stdClass();
            },
        );
        try {
            $capped->render(
                $document,
                new ReportPdfRenderBudget(1, 10, strlen($html) - 1, 2 * 1024 * 1024, 128 * 1024 * 1024),
            );
            self::fail('Expected exact HTML cap before Dompdf load.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertSame(0, $documentLoads);
    }

    public function test_document_dependency_failure_is_catalogued_without_library_message(): void
    {
        [$source, $definition] = $this->source(1);
        $renderer = $this->pdf(
            $definition,
            new FakeReportPdfDocumentRenderer(failure: new RuntimeException('dompdf secret failure')),
            new ReportPdfRenderBudget(1, 1, 128 * 1024, 1024, 128 * 1024),
        );
        $stream = new InMemoryReportArtifactStream();

        try {
            $renderer->render(
                $source,
                $this->data('pdf'),
                [$this->chunk($source, [['name' => 'A', 'amount' => '1', 'date' => '2026-07-29']])],
                $stream,
            );
            self::fail('Expected dependency failure.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
            self::assertSame('REPORT_DEPENDENCY_FAILED', $exception->getMessage());
            self::assertStringNotContainsString('dompdf', $exception->getMessage());
        }
        self::assertSame('', $stream->bytes());
    }

    private function pdf(
        \App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition $definition,
        FakeReportPdfDocumentRenderer $documentRenderer,
        ReportPdfRenderBudget $budget,
    ): PdfReportExportRenderer {
        $renderer = new PdfReportExportRenderer(
            new ReportPdfDocumentBuilder(ReportExportLimits::pdf()),
            $documentRenderer,
            [PdfReportExportRenderer::budgetKey(
                $definition->definitionHash->value,
                $definition->definition->rendererVersion,
            ) => $budget],
        );

        return $renderer->forDefinition($definition);
    }
}
