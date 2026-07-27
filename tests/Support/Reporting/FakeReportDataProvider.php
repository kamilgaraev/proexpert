<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use LogicException;

final class FakeReportDataProvider implements ReportDataProvider
{
    private array $materializeCalls = [];

    private array $resultCalls = [];

    public function __construct(
        private readonly ReportSnapshotRef $snapshot,
        private readonly ReportResult $result,
    ) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
    {
        $this->materializeCalls[] = [$context, $query, $progress];
        $progress->advance(100);

        return $this->snapshot;
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $this->resultCalls[] = [$context, $snapshot];
        if ($snapshot->id !== $this->snapshot->id) {
            throw new LogicException('snapshot_mismatch');
        }

        return $this->result;
    }

    public function materializeCalls(): array
    {
        return $this->materializeCalls;
    }

    public function resultCalls(): array
    {
        return $this->resultCalls;
    }
}
