<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportSourceSnapshotStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_persists_and_reads_only_ready_snapshot_bound_rows_and_drill_rows(): void
    {
        $store = new EloquentReportSourceSnapshotStore();
        $write = $this->write();
        $request = new ReportSourceSnapshotReadRequest(
            (new ReportExecutionContextBuilder())->build(),
            $write->header->id,
            $write->header->sourceKind,
            $write->header->reportCode,
            $write->header->schemaVersion,
            $write->header->queryHash,
            new DateTimeImmutable('2026-07-31T00:30:00+00:00'),
        );

        $ready = $store->persistReady($write);
        self::assertSame(ReportSourceSnapshotStatus::READY, $ready->status);
        self::assertSame($write->header->scopeIdentity(), $store->header($request)->scopeIdentity());

        $first = $store->page($request, null, 1);
        self::assertSame(['project:1'], array_map(static fn (ReportSourceSnapshotRow $row): string => $row->rowKey, $first->rows));
        self::assertNotNull($first->nextCursor);
        $second = $store->page($request, $first->nextCursor, 1);
        self::assertSame(['project:2'], array_map(static fn (ReportSourceSnapshotRow $row): string => $row->rowKey, $second->rows));
        self::assertNull($second->nextCursor);

        $drill = $store->drillPage($request, 'project:1', 'amount', null, 10);
        self::assertSame([['document_id' => 11]], array_map(static fn (ReportSourceSnapshotDrillRow $row): array => $row->payload, $drill->rows));
    }

    public function test_database_rejects_mutating_a_ready_snapshot_row(): void
    {
        $store = new EloquentReportSourceSnapshotStore();
        $write = $this->write();
        $store->persistReady($write);

        $this->expectException(QueryException::class);
        DB::table('report_source_snapshot_rows')->where('snapshot_id', $write->header->id)->where('ordinal', 1)
            ->update(['payload' => json_encode(['amount' => 999], JSON_THROW_ON_ERROR)]);
    }

    private function write(): ReportSourceSnapshotWrite
    {
        $id = '01J00000000000000000000001';
        $rows = [
            new ReportSourceSnapshotRow($id, 1, 'project:1', ['amount' => 100], $this->hash(['amount' => 100])),
            new ReportSourceSnapshotRow($id, 2, 'project:2', ['amount' => 200], $this->hash(['amount' => 200])),
        ];
        $drillRows = [new ReportSourceSnapshotDrillRow($id, 'project:1', 'amount', 1, ['document_id' => 11], $this->hash(['document_id' => 11]))];
        $scope = (new ReportExecutionContextBuilder())->build()->scope;
        $header = new ReportSourceSnapshotHeader(
            $id, 'portfolio.source', 'project_margin', '1', $scope, $this->hash(['query' => 1]),
            new DateTimeImmutable('2026-07-31T00:00:00+00:00'), $this->hash(['source' => 1]), ['portfolio_version' => 3],
            new DateTimeImmutable('2026-07-31T00:00:00+00:00'), new DateTimeImmutable('2026-07-31T01:00:00+00:00'),
            ReportSourceSnapshotStatus::WRITING, 2, 1, $this->hash(['placeholder' => 1]), null, null,
        );
        $header = new ReportSourceSnapshotHeader(
            $id, 'portfolio.source', 'project_margin', '1', $scope, $this->hash(['query' => 1]),
            new DateTimeImmutable('2026-07-31T00:00:00+00:00'), $this->hash(['source' => 1]), ['portfolio_version' => 3],
            new DateTimeImmutable('2026-07-31T00:00:00+00:00'), new DateTimeImmutable('2026-07-31T01:00:00+00:00'),
            ReportSourceSnapshotStatus::WRITING, 2, 1, ReportSourceSnapshotIntegrity::hash($header, $rows, $drillRows), null, null,
        );

        return new ReportSourceSnapshotWrite($header, $rows, $drillRows);
    }

    private function hash(array $value): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode($value)));
    }
}
