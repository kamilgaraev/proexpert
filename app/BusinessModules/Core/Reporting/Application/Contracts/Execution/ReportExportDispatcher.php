<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

interface ReportExportDispatcher
{
    public function dispatch(string $exportId): void;
}
