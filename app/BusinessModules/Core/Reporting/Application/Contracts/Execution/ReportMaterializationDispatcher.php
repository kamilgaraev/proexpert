<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

interface ReportMaterializationDispatcher
{
    public function dispatch(string $runId): void;
}
