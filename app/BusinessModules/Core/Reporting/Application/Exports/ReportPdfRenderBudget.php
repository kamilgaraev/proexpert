<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use InvalidArgumentException;

final readonly class ReportPdfRenderBudget
{
    public function __construct(
        public int $maxDetailRows,
        public int $maxPages,
        public int $maxHtmlBytes,
        public int $maxPdfBytes,
        public int $maxMemoryDeltaBytes,
    ) {
        if ($maxDetailRows < 1
            || $maxDetailRows > 5000
            || $maxPages < 1
            || $maxHtmlBytes < 1
            || $maxPdfBytes < 1
            || $maxMemoryDeltaBytes < 1) {
            throw new InvalidArgumentException('report_pdf_render_budget_invalid');
        }
    }
}
