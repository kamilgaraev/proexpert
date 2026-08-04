<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;

final class FakeReportDrillDownProvider implements ReportDrillDownProvider, ReportDrillDownTokenColumns
{
    private array $calls = [];

    public function __construct(
        private readonly ReportDrillDownResult $result,
        private readonly array $tokenColumns = [],
    ) {}

    public function drillDown(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportDrillDownInput $input): ReportDrillDownResult
    {
        $this->calls[] = [$context, $snapshot, $input];

        return $this->result;
    }

    public function calls(): array
    {
        return $this->calls;
    }

    public function drillDownTokenColumns(): array
    {
        return $this->tokenColumns;
    }
}
