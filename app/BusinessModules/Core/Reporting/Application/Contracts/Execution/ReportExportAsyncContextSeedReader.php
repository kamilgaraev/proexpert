<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportAsyncContextSeed;

interface ReportExportAsyncContextSeedReader
{
    public function forExport(string $exportId): ReportAsyncContextSeed;
}
