<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher;

final class FakeReportMaterializationDispatcher implements ReportMaterializationDispatcher
{
    private array $dispatchedIds = [];

    public function dispatch(string $runId): void
    {
        $this->dispatchedIds[] = $runId;
    }

    public function dispatchedIds(): array
    {
        return $this->dispatchedIds;
    }
}
