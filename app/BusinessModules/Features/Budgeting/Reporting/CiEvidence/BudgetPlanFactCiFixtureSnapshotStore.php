<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\CiEvidence;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStreamingStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotStream;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use LogicException;

final class BudgetPlanFactCiFixtureSnapshotStore implements ReportSourceSnapshotStreamingStore
{
    private ?ReportSourceSnapshotHeader $header = null;
    private ?ReportSourceSnapshotIdentity $identity = null;
    private array $rows = [];
    private array $drills = [];

    public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader { throw new LogicException('budget_plan_fact_ci_fixture_write_forbidden'); }
    public function findReady(ReportSourceSnapshotIdentity $identity): ?ReportSourceSnapshotHeader { return $this->header !== null && $this->identity !== null && $identity->matches($this->header) ? $this->header : null; }
    public function resolveReady(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader { throw new LogicException('budget_plan_fact_ci_fixture_write_forbidden'); }
    public function resolveReadyStreamed(ReportSourceSnapshotIdentity $identity, ReportSourceSnapshotStream $snapshot): ReportSourceSnapshotHeader
    {
        if ($this->header !== null) { throw new LogicException('budget_plan_fact_ci_fixture_write_forbidden'); }
        $drills = iterator_to_array($snapshot->drillRows(), false);
        $rows = $snapshot->rows;
        $source = ReportSourceSnapshotIntegrity::materializedSourceHash($rows, $drills, $snapshot->watermarks);
        $writing = $snapshot->header($source, count($drills), new Sha256Hash(str_repeat('0', 64)));
        $hash = ReportSourceSnapshotIntegrity::hash($writing, $rows, $drills);
        $base = $snapshot->header($source, count($drills), $hash);
        $this->header = new ReportSourceSnapshotHeader($base->id, $base->sourceKind, $base->reportCode, $base->schemaVersion, $base->scope, $base->queryHash, $base->asOf, $base->sourceHash, $base->watermarks, $base->generatedAt, $base->staleAt, ReportSourceSnapshotStatus::READY, $base->rowCount, $base->drillRowCount, $base->snapshotHash, $base->generatedAt, null, $base->reportQueryIdentity, $base->reportQueryHash);
        $this->identity = $identity; $this->rows = $rows; $this->drills = $drills;
        ReportSourceSnapshotIntegrity::assertWrite(new ReportSourceSnapshotWrite($this->header, $rows, $drills));
        return $this->header;
    }
    public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader { $header = $this->header ?? throw new LogicException('budget_plan_fact_ci_fixture_uninitialized'); ReportSourceSnapshotIntegrity::assertReadable($header, $request); return $header; }
    public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage { $header = $this->header($request); $offset = $cursor?->afterOrdinal ?? 0; $rows = array_values(array_filter($this->rows, static fn ($row): bool => $row->ordinal > $offset)); $items = array_slice($rows, 0, $limit); return new ReportSourceSnapshotPage($items, count($rows) > $limit ? new ReportSourceSnapshotCursor($header->id, $items[array_key_last($items)]->ordinal) : null); }
    public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage { $header = $this->header($request); $offset = $cursor?->afterOrdinal ?? 0; $rows = array_values(array_filter($this->drills, static fn (ReportSourceSnapshotDrillRow $row): bool => $row->rowKey === $rowKey && $row->columnId === $columnId && $row->ordinal > $offset)); $items = array_slice($rows, 0, $limit); return new ReportSourceSnapshotDrillPage($items, count($rows) > $limit ? new ReportSourceSnapshotCursor($header->id, $items[array_key_last($items)]->ordinal) : null); }
}
