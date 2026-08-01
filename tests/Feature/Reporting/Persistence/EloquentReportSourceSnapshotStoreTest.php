<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIdentity;
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
        $store = new EloquentReportSourceSnapshotStore;
        $write = $this->write();
        $request = new ReportSourceSnapshotReadRequest(
            (new ReportExecutionContextBuilder)->build(),
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
        $store = new EloquentReportSourceSnapshotStore;
        $write = $this->write();
        $store->persistReady($write);

        $this->expectException(QueryException::class);
        DB::table('report_source_snapshot_rows')->where('snapshot_id', $write->header->id)->where('ordinal', 1)
            ->update(['payload' => json_encode(['amount' => 999], JSON_THROW_ON_ERROR)]);
    }

    public function test_close_bound_resolve_reuses_one_ready_snapshot_and_rejects_source_drift(): void
    {
        $store = new EloquentReportSourceSnapshotStore;
        $first = $this->write('01J00000000000000000000001', 100);
        $identity = new ReportSourceSnapshotIdentity(
            $first->header->sourceKind,
            $first->header->reportCode,
            $first->header->schemaVersion,
            $first->header->scope,
            $first->header->queryHash,
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        );

        $ready = $store->resolveReady($identity, $first);
        $sameContent = $store->resolveReady(
            $identity,
            $this->write('01J00000000000000000000002', 100),
        );

        self::assertSame($ready->id, $sameContent->id);
        self::assertSame($ready->id, $store->findReady($identity)?->id);
        self::assertSame(1, DB::table('report_source_snapshots')
            ->where('source_version', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
            ->where('status', ReportSourceSnapshotStatus::READY->value)
            ->count());

        try {
            $store->resolveReady($identity, $this->write('01J00000000000000000000003', 999));
            self::fail('Source drift for one close-bound identity must be rejected.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT, $exception->errorCode);
        }
    }

    public function test_database_rejects_a_second_ready_snapshot_for_close_bound_identity(): void
    {
        $store = new EloquentReportSourceSnapshotStore;
        $write = $this->write();
        $identity = new ReportSourceSnapshotIdentity(
            $write->header->sourceKind,
            $write->header->reportCode,
            $write->header->schemaVersion,
            $write->header->scope,
            $write->header->queryHash,
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        );
        $store->resolveReady($identity, $write);
        $duplicate = (array) DB::table('report_source_snapshots')->where('id', $write->header->id)->first();
        $duplicate['id'] = '01J00000000000000000000004';

        $this->expectException(QueryException::class);
        DB::table('report_source_snapshots')->insert($duplicate);
    }

    private function write(
        string $id = '01J00000000000000000000001',
        int $firstAmount = 100,
    ): ReportSourceSnapshotWrite {
        $rows = [
            new ReportSourceSnapshotRow(
                $id,
                1,
                'project:1',
                ['amount' => $firstAmount],
                $this->hash(['amount' => $firstAmount]),
            ),
            new ReportSourceSnapshotRow($id, 2, 'project:2', ['amount' => 200], $this->hash(['amount' => 200])),
        ];
        $drillRows = [new ReportSourceSnapshotDrillRow($id, 'project:1', 'amount', 1, ['document_id' => 11], $this->hash(['document_id' => 11]))];
        $scope = (new ReportExecutionContextBuilder)->build()->scope;
        $header = new ReportSourceSnapshotHeader(
            $id, 'portfolio.source', 'project_margin', '1', $scope, $this->hash(['query' => 1]),
            new DateTimeImmutable('2026-07-31T00:00:00+00:00'),
            $this->hash(['source' => $firstAmount]),
            ['portfolio_version' => 3],
            new DateTimeImmutable('2026-07-31T00:00:00+00:00'), new DateTimeImmutable('2026-07-31T01:00:00+00:00'),
            ReportSourceSnapshotStatus::WRITING, 2, 1, $this->hash(['placeholder' => 1]), null, null,
        );
        $header = new ReportSourceSnapshotHeader(
            $id, 'portfolio.source', 'project_margin', '1', $scope, $this->hash(['query' => 1]),
            new DateTimeImmutable('2026-07-31T00:00:00+00:00'),
            $this->hash(['source' => $firstAmount]),
            ['portfolio_version' => 3],
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
