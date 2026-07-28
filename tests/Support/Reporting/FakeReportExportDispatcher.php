<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportDispatcher;

final class FakeReportExportDispatcher implements ReportExportDispatcher
{
    private array $dispatchedIds = [];

    public function dispatch(string $exportId): void
    {
        $this->dispatchedIds[] = $exportId;
    }

    public function dispatchedIds(): array
    {
        return $this->dispatchedIds;
    }
}
