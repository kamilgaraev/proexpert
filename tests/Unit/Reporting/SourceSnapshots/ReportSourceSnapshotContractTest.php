<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SourceSnapshots;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotIntegrity;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotRow;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceSnapshotStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportSourceSnapshotContractTest extends TestCase
{
    public function test_rejects_not_ready_foreign_or_stale_snapshot_reads(): void
    {
        $write = $this->write();
        $request = $this->request($write->header->id);

        $this->assertError(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, static fn () => ReportSourceSnapshotIntegrity::assertReadable($write->header, $request));

        $ready = $this->readyHeader($write);
        $foreign = new ReportSourceSnapshotReadRequest($request->context, $request->snapshotId, 'other.source', 'project_margin', '1', $request->queryHash, $request->readAt);
        $this->assertError(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, static fn () => ReportSourceSnapshotIntegrity::assertReadable($ready, $foreign));

        $stale = new ReportSourceSnapshotReadRequest($request->context, $request->snapshotId, 'portfolio.source', 'project_margin', '1', $request->queryHash, new DateTimeImmutable('2026-07-31T02:00:00+00:00'));
        $this->assertError(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED, static fn () => ReportSourceSnapshotIntegrity::assertReadable($ready, $stale));
    }

    public function test_write_hash_detects_mutation_after_it_was_sealed_for_ready_persistence(): void
    {
        $write = $this->write();
        ReportSourceSnapshotIntegrity::assertWrite($write);

        $mutated = new ReportSourceSnapshotRow($write->header->id, 1, 'project:1', ['amount' => 999], $this->hash(['amount' => 999]));
        $changed = new ReportSourceSnapshotWrite($write->header, [$mutated, $write->rows[1]], $write->drillRows);

        $this->assertError(ReportErrorCode::REPORT_INTERNAL_ERROR, static fn () => ReportSourceSnapshotIntegrity::assertWrite($changed));
    }

    public function test_cursor_is_snapshot_bound_and_pagination_is_stable_by_ordinal(): void
    {
        $write = $this->write();
        $first = array_values(array_filter($write->rows, static fn (ReportSourceSnapshotRow $row): bool => $row->ordinal > 0));
        self::assertSame(['project:1', 'project:2'], array_map(static fn (ReportSourceSnapshotRow $row): string => $row->rowKey, $first));

        $cursor = new ReportSourceSnapshotCursor($write->header->id, 1);
        $second = array_values(array_filter($write->rows, static fn (ReportSourceSnapshotRow $row): bool => $row->ordinal > $cursor->afterOrdinal));
        self::assertSame(['project:2'], array_map(static fn (ReportSourceSnapshotRow $row): string => $row->rowKey, $second));
        self::assertNotSame('01J00000000000000000000000', $cursor->snapshotId);
    }

    public function test_drill_rows_are_bound_to_snapshot_row_and_column(): void
    {
        $write = $this->write();
        $rows = array_values(array_filter($write->drillRows, static fn (ReportSourceSnapshotDrillRow $row): bool => $row->snapshotId === $write->header->id && $row->rowKey === 'project:1' && $row->columnId === 'amount'));

        self::assertCount(1, $rows);
        self::assertSame(['document_id' => 11], $rows[0]->payload);
    }

    private function write(): ReportSourceSnapshotWrite
    {
        $id = '01J00000000000000000000001';
        $rows = [
            new ReportSourceSnapshotRow($id, 1, 'project:1', ['amount' => 100], $this->hash(['amount' => 100])),
            new ReportSourceSnapshotRow($id, 2, 'project:2', ['amount' => 200], $this->hash(['amount' => 200])),
        ];
        $drillRows = [new ReportSourceSnapshotDrillRow($id, 'project:1', 'amount', 1, ['document_id' => 11], $this->hash(['document_id' => 11]))];
        $scope = (new ReportExecutionContextBuilder)->build()->scope;
        $identity = [];
        $identityHash = $this->hash($identity);
        $header = new ReportSourceSnapshotHeader($id, 'portfolio.source', 'project_margin', '1', $scope, $this->hash(['query' => 1]), new DateTimeImmutable('2026-07-31T00:00:00+00:00'), $this->hash(['source' => 1]), ['portfolio_version' => 3], new DateTimeImmutable('2026-07-31T00:00:00+00:00'), new DateTimeImmutable('2026-07-31T01:00:00+00:00'), ReportSourceSnapshotStatus::WRITING, 2, 1, $this->hash(['placeholder' => 1]), null, null, $identity, $identityHash);
        $hash = ReportSourceSnapshotIntegrity::hash($header, $rows, $drillRows);
        $header = new ReportSourceSnapshotHeader($id, 'portfolio.source', 'project_margin', '1', $scope, $this->hash(['query' => 1]), new DateTimeImmutable('2026-07-31T00:00:00+00:00'), $this->hash(['source' => 1]), ['portfolio_version' => 3], new DateTimeImmutable('2026-07-31T00:00:00+00:00'), new DateTimeImmutable('2026-07-31T01:00:00+00:00'), ReportSourceSnapshotStatus::WRITING, 2, 1, $hash, null, null, $header->reportQueryIdentity, $header->reportQueryHash);

        return new ReportSourceSnapshotWrite($header, $rows, $drillRows);
    }

    private function readyHeader(ReportSourceSnapshotWrite $write): ReportSourceSnapshotHeader
    {
        $header = $write->header;

        return new ReportSourceSnapshotHeader($header->id, $header->sourceKind, $header->reportCode, $header->schemaVersion, $header->scope, $header->queryHash, $header->asOf, $header->sourceHash, $header->watermarks, $header->generatedAt, $header->staleAt, ReportSourceSnapshotStatus::READY, $header->rowCount, $header->drillRowCount, $header->snapshotHash, new DateTimeImmutable('2026-07-31T00:00:01+00:00'), null, $header->reportQueryIdentity, $header->reportQueryHash);
    }

    private function request(string $snapshotId): ReportSourceSnapshotReadRequest
    {
        return new ReportSourceSnapshotReadRequest((new ReportExecutionContextBuilder)->build(), $snapshotId, 'portfolio.source', 'project_margin', '1', $this->hash(['query' => 1]), new DateTimeImmutable('2026-07-31T00:30:00+00:00'));
    }

    private function hash(array $value): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)));
    }

    private function assertError(ReportErrorCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected report contract exception.');
        } catch (ReportContractException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
