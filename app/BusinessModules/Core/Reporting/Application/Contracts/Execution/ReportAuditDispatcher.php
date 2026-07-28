<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

interface ReportAuditDispatcher
{
    public function dispatch(string $intentId): void;
}
