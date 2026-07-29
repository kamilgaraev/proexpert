<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocument;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Dompdf\Dompdf;
use RuntimeException;
use Throwable;

final class DompdfReportPdfDocumentRenderer implements ReportPdfDocumentRenderer
{
    private readonly Closure $htmlRenderer;

    private readonly Closure $documentLoader;

    private readonly Closure $pageCounter;

    private readonly Closure $outputRenderer;

    private readonly Closure $memoryUsage;

    private readonly Closure $memoryPeak;

    public function __construct(
        ?Closure $htmlRenderer = null,
        ?Closure $documentLoader = null,
        ?Closure $pageCounter = null,
        ?Closure $outputRenderer = null,
        ?Closure $memoryUsage = null,
        ?Closure $memoryPeak = null,
    ) {
        $this->htmlRenderer = $htmlRenderer
            ?? static fn (ReportPdfDocument $document): string => view(
                'reports.exports.canonical-report-pdf',
                ['document' => $document],
            )->render();
        $this->documentLoader = $documentLoader ?? static function (string $html): Dompdf {
            $wrapper = Pdf::loadHTML($html);
            $dompdf = $wrapper->getDomPDF();
            $dompdf->render();

            return $dompdf;
        };
        $this->pageCounter = $pageCounter ?? static function (object $document): int {
            if (!$document instanceof Dompdf) {
                throw new RuntimeException('invalid_pdf_document');
            }

            return $document->getCanvas()->get_page_count();
        };
        $this->outputRenderer = $outputRenderer ?? static function (object $document): string {
            if (!$document instanceof Dompdf) {
                throw new RuntimeException('invalid_pdf_document');
            }

            return $document->output();
        };
        $this->memoryUsage = $memoryUsage ?? static fn (): int => memory_get_usage(true);
        $this->memoryPeak = $memoryPeak ?? static fn (): int => memory_get_peak_usage(true);
    }

    public function render(ReportPdfDocument $document, ReportPdfRenderBudget $budget): string
    {
        $memoryAtStart = $document->memoryBaselineBytes > 0
            ? $document->memoryBaselineBytes
            : ($this->memoryUsage)();
        if ($document->memoryBaselineBytes === 0 && function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        try {
            $projectedHtmlBytes = $document->projectedHtmlBytes > 0
                ? $document->projectedHtmlBytes
                : $budget->maxHtmlBytes;
            $this->assertCumulativeMemory(
                $document,
                $memoryAtStart,
                $budget,
                $projectedHtmlBytes,
                $budget->maxPdfBytes,
            );

            $html = ($this->htmlRenderer)($document);
            if (!is_string($html)) {
                throw new \RuntimeException('invalid_html_renderer_result');
            }
            if (strlen($html) > $budget->maxHtmlBytes) {
                throw $this->limit();
            }
            $this->assertCumulativeMemory(
                $document,
                $memoryAtStart,
                $budget,
                0,
                $budget->maxPdfBytes,
                strlen($html),
            );

            $renderedDocument = ($this->documentLoader)($html);
            if (!is_object($renderedDocument)) {
                throw new \RuntimeException('invalid_pdf_renderer_result');
            }
            $this->assertCumulativeMemory(
                $document,
                $memoryAtStart,
                $budget,
                0,
                $budget->maxPdfBytes,
                strlen($html),
            );
            $pages = ($this->pageCounter)($renderedDocument);
            if (!is_int($pages) || $pages < 1) {
                throw new RuntimeException('invalid_pdf_page_count');
            }
            if ($pages > $budget->maxPages) {
                throw $this->limit();
            }

            $bytes = ($this->outputRenderer)($renderedDocument);
            if (!is_string($bytes)) {
                throw new RuntimeException('invalid_pdf_output');
            }
            if (strlen($bytes) > $budget->maxPdfBytes) {
                throw $this->limit();
            }
            $this->assertCumulativeMemory(
                $document,
                $memoryAtStart,
                $budget,
                0,
                0,
                strlen($html) + strlen($bytes),
            );

            return $bytes;
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }
    }

    private function limit(): ReportContractException
    {
        return ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED);
    }

    private function assertCumulativeMemory(
        ReportPdfDocument $document,
        int $memoryAtStart,
        ReportPdfRenderBudget $budget,
        int $futureHtmlBytes,
        int $futurePdfBytes,
        int $materializedBytes = 0,
    ): void {
        $actualUsageDelta = max(0, ($this->memoryUsage)() - $memoryAtStart);
        $actualPeakDelta = max(
            $document->buildPeakMemoryDeltaBytes,
            max(0, ($this->memoryPeak)() - $memoryAtStart),
        );
        $retainedAndMaterialized = $document->projectedRetainedBytes + $materializedBytes;
        $knownPeak = max($actualUsageDelta, $actualPeakDelta, $retainedAndMaterialized);
        $remaining = $budget->maxMemoryDeltaBytes;

        foreach ([$knownPeak, $futureHtmlBytes, $futurePdfBytes] as $reservedBytes) {
            if ($reservedBytes < 0 || $reservedBytes > $remaining) {
                throw $this->limit();
            }
            $remaining -= $reservedBytes;
        }

        if ($knownPeak > $budget->maxMemoryDeltaBytes) {
            throw $this->limit();
        }
    }
}
