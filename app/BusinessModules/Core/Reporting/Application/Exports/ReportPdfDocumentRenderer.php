<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

interface ReportPdfDocumentRenderer
{
    public function render(ReportPdfDocument $document, ReportPdfRenderBudget $budget): string;
}
