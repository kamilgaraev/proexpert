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
use Throwable;

final class DompdfReportPdfDocumentRenderer implements ReportPdfDocumentRenderer
{
    private readonly Closure $htmlRenderer;

    private readonly Closure $pdfRenderer;

    private readonly Closure $memoryUsage;

    private readonly Closure $memoryPeak;

    public function __construct(
        ?Closure $htmlRenderer = null,
        ?Closure $pdfRenderer = null,
        ?Closure $memoryUsage = null,
        ?Closure $memoryPeak = null,
    ) {
        $this->htmlRenderer = $htmlRenderer
            ?? static fn (ReportPdfDocument $document): string => view(
                'reports.exports.canonical-report-pdf',
                ['document' => $document],
            )->render();
        $this->pdfRenderer = $pdfRenderer ?? static function (string $html): array {
            $wrapper = Pdf::loadHTML($html);
            $dompdf = $wrapper->getDomPDF();
            $dompdf->render();

            return [
                'pages' => $dompdf->getCanvas()->get_page_count(),
                'bytes' => $dompdf->output(),
            ];
        };
        $this->memoryUsage = $memoryUsage ?? static fn (): int => memory_get_usage(true);
        $this->memoryPeak = $memoryPeak ?? static fn (): int => memory_get_peak_usage(true);
    }

    public function render(ReportPdfDocument $document, ReportPdfRenderBudget $budget): string
    {
        $memoryAtStart = ($this->memoryUsage)();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        try {
            $html = ($this->htmlRenderer)($document);
            if (!is_string($html)) {
                throw new \RuntimeException('invalid_html_renderer_result');
            }
            if (strlen($html) > $budget->maxHtmlBytes) {
                throw $this->limit();
            }

            $rendered = ($this->pdfRenderer)($html);
            if (!is_array($rendered)
                || array_keys($rendered) !== ['pages', 'bytes']
                || !is_int($rendered['pages'])
                || $rendered['pages'] < 1
                || !is_string($rendered['bytes'])) {
                throw new \RuntimeException('invalid_pdf_renderer_result');
            }
            if ($rendered['pages'] > $budget->maxPages) {
                throw $this->limit();
            }

            $bytes = $rendered['bytes'];
            if (strlen($bytes) > $budget->maxPdfBytes
                || max(0, ($this->memoryPeak)() - $memoryAtStart) > $budget->maxMemoryDeltaBytes) {
                throw $this->limit();
            }

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
}
