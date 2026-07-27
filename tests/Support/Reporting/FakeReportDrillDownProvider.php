<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;

final class FakeReportDrillDownProvider implements ReportDrillDownProvider
{
    private array $calls = [];

    public function __construct(private readonly ReportDrillDownResult $result) {}

    public function drillDown(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportDrillDownRequest $request): ReportDrillDownResult
    {
        $this->calls[] = [$context, $snapshot, $request];

        return $this->result;
    }

    public function calls(): array
    {
        return $this->calls;
    }
}
