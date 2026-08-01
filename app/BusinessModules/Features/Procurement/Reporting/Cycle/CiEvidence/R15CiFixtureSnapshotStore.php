<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use InvalidArgumentException;

final readonly class R15CiFixtureSnapshotStore implements ReportSourceSnapshotStore
{
    public function __construct(private ReportSourceSnapshotWrite $snapshot) {}

    public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader { throw new \LogicException('r15_ci_fixture_write_forbidden'); }
    public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader { return null; }
    public function resolveReady(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader { throw new \LogicException('r15_ci_fixture_write_forbidden'); }

    public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
    {
        $this->assertRequest($request);
        return $this->snapshot->header;
    }

    public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
    {
        $this->assertRequest($request);
        $offset = $cursor?->afterOrdinal ?? 0;
        $rows = array_slice($this->snapshot->rows, $offset, $limit);
        $next = $offset + count($rows) < count($this->snapshot->rows)
            ? new ReportSourceSnapshotCursor($this->snapshot->header->id, $offset + count($rows)) : null;
        return new ReportSourceSnapshotPage($rows, $next);
    }

    public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
    {
        $this->assertRequest($request);
        $all = array_values(array_filter($this->snapshot->drillRows, static fn ($row): bool => $row->rowKey === $rowKey && $row->columnId === $columnId));
        $offset = $cursor?->afterOrdinal ?? 0;
        $rows = array_slice($all, $offset, $limit);
        $next = $offset + count($rows) < count($all) ? new ReportSourceSnapshotCursor($this->snapshot->header->id, $offset + count($rows)) : null;
        return new ReportSourceSnapshotDrillPage($rows, $next);
    }

    private function assertRequest(ReportSourceSnapshotReadRequest $request): void
    {
        if ($request->snapshotId !== $this->snapshot->header->id || $request->reportCode !== $this->snapshot->header->reportCode || ! hash_equals($request->queryHash->value, $this->snapshot->header->queryHash->value)) {
            throw new InvalidArgumentException('r15_ci_fixture_snapshot_mismatch');
        }
    }
}
