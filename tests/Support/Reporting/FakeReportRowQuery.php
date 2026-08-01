<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;

final class FakeReportRowQuery implements ReportRowQuery
{
    private array $rows;

    private array $pageCalls = [];

    private array $cursorCalls = [];

    public function __construct(private readonly ReportPage $page, iterable $rows)
    {
        $this->rows = [];
        foreach ($rows as $row) {
            $this->rows[] = $row;
        }
    }

    public function page(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, ?ReportCursor $cursor, int $limit): ReportPage
    {
        $this->pageCalls[] = [$context, $snapshot, $sort, $cursor, $limit];

        return $this->page;
    }

    public function cursor(ReportExecutionContext $context, ReportSnapshotRef $snapshot, ReportWindowSort $sort, int $chunkSize): iterable
    {
        $this->cursorCalls[] = [$context, $snapshot, $sort, $chunkSize];

        yield from $this->rows;
    }

    public function pageCalls(): array
    {
        return $this->pageCalls;
    }

    public function cursorCalls(): array
    {
        return $this->cursorCalls;
    }
}
