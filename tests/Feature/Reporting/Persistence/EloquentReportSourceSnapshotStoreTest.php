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
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class EloquentReportSourceSnapshotStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('REPORTING_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped('Set REPORTING_POSTGRES_TESTS=1 to run isolated PostgreSQL reporting tests.');
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
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

    #[DataProvider('partialCloseBoundIdentities')]
    public function test_database_rejects_a_partial_close_bound_identity(
        string $snapshotId,
        ?string $scopeIdentityHash,
        ?string $sourceVersion,
    ): void {
        $attributes = $this->headerAttributes($this->write($snapshotId));
        $attributes['scope_identity_hash'] = $scopeIdentityHash;
        $attributes['source_version'] = $sourceVersion;

        $this->expectException(QueryException::class);
        DB::table('report_source_snapshots')->insert($attributes);
    }

    public static function partialCloseBoundIdentities(): array
    {
        return [
            'source version without scope hash' => [
                '01J00000000000000000000100',
                null,
                '01JZZZZZZZZZZZZZZZZZZZZZZX',
            ],
            'scope hash without source version' => [
                '01J00000000000000000000103',
                str_repeat('a', 64),
                null,
            ],
        ];
    }

    public function test_concurrent_unique_winner_is_recovered_through_an_independent_process(): void
    {
        if (! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')
            || ! function_exists('posix_getpid')) {
            $this->markTestSkipped('Requires pcntl and posix extensions for a real PostgreSQL process race.');
        }

        $baseConnection = config('database.connections.pgsql');
        if (! is_array($baseConnection)) {
            throw new RuntimeException('PostgreSQL test connection is not configured.');
        }

        config([
            'database.connections.report_snapshot_winner' => $baseConnection,
            'database.connections.report_snapshot_contender' => $baseConnection,
            'database.connections.report_snapshot_observer' => $baseConnection,
        ]);
        foreach (['report_snapshot_winner', 'report_snapshot_contender', 'report_snapshot_observer'] as $connection) {
            DB::purge($connection);
        }

        $originalDefault = (string) config('database.default');
        $winnerConnection = DB::connection('report_snapshot_winner');
        $observerConnection = DB::connection('report_snapshot_observer');
        $resultFile = tempnam(sys_get_temp_dir(), 'report-snapshot-race-');
        if ($resultFile === false) {
            throw new RuntimeException('Cannot create race result file.');
        }

        $winnerWrite = $this->write('01J00000000000000000000101');
        $contenderWrite = $this->write('01J00000000000000000000102');
        $identity = new ReportSourceSnapshotIdentity(
            $winnerWrite->header->sourceKind,
            $winnerWrite->header->reportCode,
            $winnerWrite->header->schemaVersion,
            $winnerWrite->header->scope,
            $winnerWrite->header->queryHash,
            '01JZZZZZZZZZZZZZZZZZZZZZZY',
        );
        $pid = -1;

        try {
            $winnerConnection->beginTransaction();
            DB::setDefaultConnection('report_snapshot_winner');
            $winner = (new EloquentReportSourceSnapshotStore)->resolveReady($identity, $winnerWrite);
            DB::setDefaultConnection($originalDefault);

            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork PostgreSQL race contender.');
            }

            if ($pid === 0) {
                $this->runRaceContender($identity, $contenderWrite, $resultFile);
            }

            $this->waitForChildState($resultFile, 'started');
            $blocked = $this->waitForUniqueConstraintLock(
                $observerConnection,
                'report_snapshot_race_contender',
            );
            $winnerConnection->commit();

            $status = $this->waitForChildExit($pid);
            $result = $this->waitForChildState($resultFile, 'finished');

            self::assertTrue($blocked, 'Contender must wait on the winner transaction unique constraint.');
            self::assertTrue(pcntl_wifsignaled($status));
            self::assertSame(SIGKILL, pcntl_wtermsig($status));
            self::assertNull($result['error'] ?? null);
            self::assertSame($winner->id, $result['snapshot_id'] ?? null);
            self::assertSame(1, $observerConnection
                ->table('report_source_snapshots')
                ->where('source_version', $identity->sourceVersion)
                ->where('status', ReportSourceSnapshotStatus::READY->value)
                ->count());
        } finally {
            DB::setDefaultConnection($originalDefault);
            if ($winnerConnection->transactionLevel() > 0) {
                $winnerConnection->rollBack();
            }
            if ($pid > 0 && pcntl_waitpid($pid, $unusedStatus, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $unusedStatus);
            }
            try {
                $this->cleanupCommittedFixture($observerConnection);
            } finally {
                foreach (['report_snapshot_winner', 'report_snapshot_contender', 'report_snapshot_observer'] as $connection) {
                    DB::purge($connection);
                }
                @unlink($resultFile);
            }
        }
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

    private function headerAttributes(ReportSourceSnapshotWrite $write): array
    {
        $header = $write->header;

        return [
            'id' => $header->id,
            'source_kind' => $header->sourceKind,
            'report_code' => $header->reportCode,
            'schema_version' => $header->schemaVersion,
            'organization_id' => $header->scope->organizationId,
            'scope_identity' => json_encode($header->scopeIdentity(), JSON_THROW_ON_ERROR),
            'scope_identity_hash' => null,
            'query_hash' => $header->queryHash->value,
            'source_version' => null,
            'as_of' => $header->asOf,
            'source_hash' => $header->sourceHash->value,
            'watermarks' => json_encode($header->watermarks, JSON_THROW_ON_ERROR),
            'generated_at' => $header->generatedAt,
            'stale_at' => $header->staleAt,
            'status' => ReportSourceSnapshotStatus::WRITING->value,
            'row_count' => $header->rowCount,
            'drill_row_count' => $header->drillRowCount,
            'snapshot_hash' => $header->snapshotHash->value,
            'ready_at' => null,
            'expired_at' => null,
            'created_at' => $header->generatedAt,
            'updated_at' => $header->generatedAt,
        ];
    }

    private function runRaceContender(
        ReportSourceSnapshotIdentity $identity,
        ReportSourceSnapshotWrite $write,
        string $resultFile,
    ): never {
        DB::setDefaultConnection('report_snapshot_contender');
        DB::connection()->statement("SET application_name = 'report_snapshot_race_contender'");
        file_put_contents($resultFile, json_encode(['state' => 'started'], JSON_THROW_ON_ERROR), LOCK_EX);

        try {
            $resolved = (new EloquentReportSourceSnapshotStore)->resolveReady($identity, $write);
            $result = ['state' => 'finished', 'snapshot_id' => $resolved->id, 'error' => null];
        } catch (Throwable $exception) {
            $result = ['state' => 'finished', 'snapshot_id' => null, 'error' => $exception::class];
        }

        file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
        $this->terminateChildWithoutClosingInheritedDatabaseSockets();
    }

    private function waitForChildState(string $resultFile, string $state): array
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $contents = file_get_contents($resultFile);
            $result = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
            if (is_array($result) && ($result['state'] ?? null) === $state) {
                return $result;
            }
            usleep(25_000);
        }

        throw new RuntimeException("Child process did not reach {$state} state.");
    }

    private function waitForUniqueConstraintLock(Connection $observer, string $applicationName): bool
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $row = $observer->selectOne(
                <<<'SQL'
SELECT COUNT(*) AS aggregate
FROM pg_stat_activity
WHERE application_name = ?
  AND wait_event_type = 'Lock'
  AND wait_event = 'transactionid'
SQL,
                [$applicationName],
            );
            if (is_object($row) && (int) ($row->aggregate ?? 0) > 0) {
                return true;
            }
            usleep(25_000);
        }

        return false;
    }

    private function waitForChildExit(int $pid): int
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                return $status;
            }
            usleep(25_000);
        }

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        throw new RuntimeException('PostgreSQL race contender timed out.');
    }

    private function terminateChildWithoutClosingInheritedDatabaseSockets(): never
    {
        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    private function cleanupCommittedFixture(Connection $connection): void
    {
        $connection->statement(
            'TRUNCATE TABLE report_source_snapshot_drill_rows, '
            .'report_source_snapshot_rows, report_source_snapshots',
        );
    }
}
