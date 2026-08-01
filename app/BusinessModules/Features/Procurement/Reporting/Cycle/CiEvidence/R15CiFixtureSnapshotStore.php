<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use InvalidArgumentException;

final readonly class R15CiFixtureSnapshotStore implements ReportSourceSnapshotStore
{
    private ReportSourceSnapshotHeader $readyHeader;

    public function __construct(private ReportSourceSnapshotWrite $snapshot)
    {
        ReportSourceSnapshotIntegrity::assertWrite($snapshot);
        $header = $snapshot->header;
        $this->readyHeader = new ReportSourceSnapshotHeader(
            $header->id,
            $header->sourceKind,
            $header->reportCode,
            $header->schemaVersion,
            $header->scope,
            $header->queryHash,
            $header->asOf,
            $header->sourceHash,
            $header->watermarks,
            $header->generatedAt,
            $header->staleAt,
            ReportSourceSnapshotStatus::READY,
            $header->rowCount,
            $header->drillRowCount,
            $header->snapshotHash,
            $header->generatedAt,
            null,
            $header->reportQueryIdentity,
            $header->reportQueryHash,
        );
    }

    public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader { throw new \LogicException('r15_ci_fixture_write_forbidden'); }
    public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader
    {
        return $identity->matches($this->readyHeader) ? $this->readyHeader : null;
    }
    public function resolveReady(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader { throw new \LogicException('r15_ci_fixture_write_forbidden'); }

    public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
    {
        $this->assertRequest($request);
        ReportSourceSnapshotIntegrity::assertReadable($this->readyHeader, $request);
        return $this->readyHeader;
    }

    public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
    {
        $this->assertRequest($request);
        $offset = $cursor?->afterOrdinal ?? 0;
        $rows = array_slice($this->snapshot->rows, $offset, $limit);
        $next = $offset + count($rows) < count($this->snapshot->rows)
            ? new ReportSourceSnapshotCursor($this->readyHeader->id, $offset + count($rows)) : null;
        return new ReportSourceSnapshotPage($rows, $next);
    }

    public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
    {
        $this->assertRequest($request);
        $all = array_values(array_filter($this->snapshot->drillRows, static fn ($row): bool => $row->rowKey === $rowKey && $row->columnId === $columnId));
        $offset = $cursor?->afterOrdinal ?? 0;
        $rows = array_slice($all, $offset, $limit);
        $next = $offset + count($rows) < count($all) ? new ReportSourceSnapshotCursor($this->readyHeader->id, $offset + count($rows)) : null;
        return new ReportSourceSnapshotDrillPage($rows, $next);
    }

    private function assertRequest(ReportSourceSnapshotReadRequest $request): void
    {
        ReportSourceSnapshotIntegrity::assertReadable($this->readyHeader, $request);
        if ($request->snapshotId !== $this->readyHeader->id || $request->reportCode !== $this->readyHeader->reportCode || ! hash_equals($request->queryHash->value, $this->readyHeader->queryHash->value)) {
            throw new InvalidArgumentException('r15_ci_fixture_snapshot_mismatch');
        }
    }
}
