<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

require_once __DIR__.'/CsvReportExportRendererTest.php';

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use Throwable;

final class ReportExportRendererRegistryTest extends ReportExportRendererTestCase
{
    public function test_resolves_exact_csv_xlsx_and_pdf_entries(): void
    {
        [, $definition] = $this->source(1);
        $registry = $this->registry($definition->definitionHash->value, 'renderer-1');

        self::assertInstanceOf(
            CsvReportExportRenderer::class,
            $registry->resolve($definition, $this->data('csv')),
        );
        self::assertInstanceOf(
            XlsxReportExportRenderer::class,
            $registry->resolve($definition, $this->data('xlsx')),
        );
        self::assertInstanceOf(
            PdfReportExportRenderer::class,
            $registry->resolve($definition, $this->data('pdf')),
        );
    }

    public function test_missing_or_stale_pdf_budget_fails_before_cursor_consumption(): void
    {
        [, $definition] = $this->source(1);
        foreach ([
            'missing' => $this->registry(null, null),
            'stale version' => $this->registry($definition->definitionHash->value, 'renderer-old'),
        ] as $registry) {
            $cursorReads = 0;
            $chunks = (static function () use (&$cursorReads): iterable {
                $cursorReads++;
                yield from [];
            })();

            try {
                $renderer = $registry->resolve($definition, $this->data('pdf'));
                $renderer->render(
                    $this->source(1)[0],
                    $this->data('pdf'),
                    $chunks,
                    new InMemoryReportArtifactStream(),
                );
                self::fail('Expected missing exact PDF budget.');
            } catch (Throwable $exception) {
                $this->assertLimit($exception);
            }
            self::assertSame(0, $cursorReads);
        }
    }

    public function test_unregistered_format_column_and_bound_identity_fail_closed(): void
    {
        [$source, $csvOnly] = $this->source(1, formats: ['csv']);
        $registry = $this->registry($csvOnly->definitionHash->value, 'renderer-1');

        foreach ([
            $this->data('pdf'),
            $this->data('csv', ['unknown']),
        ] as $data) {
            try {
                $registry->resolve($csvOnly, $data);
                self::fail('Expected unregistered request.');
            } catch (Throwable $exception) {
                $this->assertLimit($exception);
            }
        }

        [, $otherDefinition] = $this->source(1, rendererVersion: 'renderer-2');
        $bound = $registry->resolve($csvOnly, $this->data('csv'));
        $cursorReads = 0;
        $chunks = (static function () use (&$cursorReads): iterable {
            $cursorReads++;
            yield from [];
        })();
        try {
            $bound->render(
                $this->source(1, rendererVersion: 'renderer-2')[0],
                $this->data('csv'),
                $chunks,
                new InMemoryReportArtifactStream(),
            );
            self::fail('Expected stale source identity.');
        } catch (Throwable $exception) {
            $this->assertLimit($exception);
        }
        self::assertSame(0, $cursorReads);
        self::assertSame('renderer-2', $otherDefinition->definition->rendererVersion);
        self::assertSame('renderer-1', $source->rendererVersion);
    }

    private function registry(?string $definitionHash, ?string $rendererVersion): ReportExportRendererRegistry
    {
        $budgets = [];
        if ($definitionHash !== null && $rendererVersion !== null) {
            $budgets[PdfReportExportRenderer::budgetKey($definitionHash, $rendererVersion)]
                = new ReportPdfRenderBudget(5000, 100, 10_000_000, 10_000_000, 128 * 1024 * 1024);
        }

        return new ReportExportRendererRegistry(
            new CsvReportExportRenderer(),
            new XlsxReportExportRenderer(),
            new PdfReportExportRenderer(
                new ReportPdfDocumentBuilder(ReportExportLimits::pdf()),
                new FakeReportPdfDocumentRenderer(),
                $budgets,
            ),
        );
    }
}
