<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;

interface ReportExportRenderer
{
    /** @param iterable<\App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk> $chunks */
    public function render(
        ReportRunExportSource $source,
        CreateReportExportData $data,
        iterable $chunks,
        ReportArtifactStream $stream,
    ): int;
}
