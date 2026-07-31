<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;

abstract class AbstractBudgetingReportSourceSnapshotAdapter implements ReportDataProvider, ReportRowQuery, ReportDrillDownProvider
{
    private const REPORT_QUERY_HASH = 'report_query_hash';

    private const SOURCE_SNAPSHOT_QUERY_HASH = 'source_snapshot_query_hash';

    public function __construct(private readonly ReportSourceSnapshotStore $store)
    {
    }

    final public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertQueryContext($context, $query);
        $this->assertFormulaCompatibility($query, $this->approvedCloseFormulaVersion($query));
        $header = $this->persistSourceSnapshot($query);
        $this->assertHeader($header, $context, $query);
        $progress->advance(100);

        return new ReportSnapshotRef(
            $header->sourceKind,
            $header->id,
            $header->scope,
            $query->definition->definitionHash,
            $query->definition->formulaVersion,
            $header->sourceHash,
            $header->generatedAt,
            $header->staleAt,
            [
                ...$header->watermarks,
                self::REPORT_QUERY_HASH => $query->queryHash->value,
                self::SOURCE_SNAPSHOT_QUERY_HASH => $header->queryHash->value,
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    final public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $header = $this->header($context, $snapshot);
        $quality = $this->quality($header);

        return new ReportResult(
            new ReportResultMetadata($snapshot, $header->rowCount, $header->generatedAt, $header->staleAt),
            ['row_count' => $header->rowCount],
            ReportFreshnessStatus::FRESH,
            $quality,
            new ReportProvenance(
                'budgeting',
                [new ReportSourceRef(
                    'budgeting',
                    'source_snapshot',
                    'materialized',
                    $this->sourceRefSchemaVersion(),
                    'approved_close',
                    $header->rowCount,
                    $header->snapshotHash,
                )],
                $snapshot->sourceHash,
                null,
            ),
            $this->rowSchema(),
            ['drill_down' => true, 'source_snapshot' => true],
        );
    }

    final public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $this->assertSort($sort);
        $header = $this->header($context, $snapshot);
        $sourcePage = $this->store->page(
            $this->readRequest($context, $snapshot),
            $this->pageCursor($context, $snapshot, $sort, $cursor),
            $limit,
        );

        return new ReportPage(
            array_map(fn ($row): array => $this->row($row->rowKey, $row->payload), $sourcePage->rows),
            ['row_count' => $header->rowCount],
            ReportFreshnessStatus::FRESH,
            $this->quality($header),
            $sourcePage->nextCursor === null ? null : $this->cursorToken($sourcePage->nextCursor),
            $limit,
            $sourcePage->nextCursor !== null,
            $sort,
        );
    }

    final public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        $this->assertSort($sort);
        $this->header($context, $snapshot);
        $request = $this->readRequest($context, $snapshot);
        $cursor = null;

        do {
            $page = $this->store->page($request, $cursor, $chunkSize);
            foreach ($page->rows as $row) {
                yield [
                    'query_hash' => $this->reportQueryHash($snapshot)->value,
                    'row_key' => $row->rowKey,
                    'snapshot_id' => $snapshot->id,
                    'source_hash' => $snapshot->sourceHash->value,
                    'values' => $this->row($row->rowKey, $row->payload),
                ];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    final public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $this->header($context, $snapshot);
        if ($input->cell->columnId !== $this->drillColumnId()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => 'columns']);
        }

        $sourcePage = $this->store->drillPage(
            $this->readRequest($context, $snapshot),
            $input->cell->rowKey,
            $input->cell->columnId,
            $this->drillCursor($snapshot, $input->cursor),
            $input->limit,
        );

        return new ReportDrillDownResult(
            array_map(
                static fn ($row): array => ['row_key' => $row->rowKey.':'.$row->ordinal, ...$row->payload],
                $sourcePage->rows,
            ),
            $sourcePage->nextCursor === null ? null : $this->cursorToken($sourcePage->nextCursor),
            [],
        );
    }

    abstract protected function persistSourceSnapshot(ReportQuery $query): ReportSourceSnapshotHeader;

    abstract protected function approvedCloseFormulaVersion(ReportQuery $query): string;

    abstract protected function reportCode(): string;

    abstract protected function sourceKind(): string;

    abstract protected function schemaVersion(): string;

    abstract protected function drillColumnId(): string;

    /** @return list<array{id: string}> */
    abstract protected function rowSchema(): array;

    private function assertQueryContext(ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($query->definition->code !== $this->reportCode()
            || $context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        if (! hash_equals($query->definition->sourceSchemaVersion, $this->schemaVersion())) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

    private function assertHeader(ReportSourceSnapshotHeader $header, ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($header->reportCode !== $this->reportCode()
            || $header->sourceKind !== $this->sourceKind()
            || $header->schemaVersion !== $this->schemaVersion()
            || $header->scopeIdentity() !== $context->scope->canonicalIdentity()
            || $header->scopeIdentity() !== $query->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $this->assertFormulaCompatibility($query, $this->closeFormulaVersion($header));
    }

    private function header(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportSourceSnapshotHeader
    {
        if ($snapshot->kind !== $this->sourceKind()
            || $snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $header = $this->store->header($this->readRequest($context, $snapshot));
        if ($header->id !== $snapshot->id
            || ! hash_equals($header->sourceHash->value, $snapshot->sourceHash->value)
            || $header->generatedAt != $snapshot->generatedAt
            || $header->staleAt != $snapshot->staleAt
            || ! hash_equals($this->closeFormulaVersion($header), $snapshot->formulaVersion)
            || $header->scopeIdentity() !== $snapshot->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return $header;
    }

    private function readRequest(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportSourceSnapshotReadRequest
    {
        return new ReportSourceSnapshotReadRequest(
            $context,
            $snapshot->id,
            $this->sourceKind(),
            $this->reportCode(),
            $this->schemaVersion(),
            $this->sourceSnapshotQueryHash($snapshot),
            $snapshot->generatedAt,
        );
    }

    private function pageCursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
    ): ?ReportSourceSnapshotCursor {
        if ($cursor === null) {
            return null;
        }
        if (! hash_equals($cursor->queryHash->value, $this->reportQueryHash($snapshot)->value)
            || ! hash_equals($cursor->sourceHash->value, $snapshot->sourceHash->value)
            || $cursor->sort != $sort) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        $request = $this->readRequest($context, $snapshot);
        $sourceCursor = null;
        do {
            $page = $this->store->page($request, $sourceCursor, 100);
            foreach ($page->rows as $row) {
                if ($row->rowKey === $cursor->keyset->lastStableRowKey) {
                    return new ReportSourceSnapshotCursor($snapshot->id, $row->ordinal);
                }
            }
            $sourceCursor = $page->nextCursor;
        } while ($sourceCursor !== null);

        throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
    }

    private function drillCursor(ReportSnapshotRef $snapshot, ?string $token): ?ReportSourceSnapshotCursor
    {
        if ($token === null) {
            return null;
        }

        $parts = explode(':', $token);
        if (count($parts) !== 2 || $parts[0] !== $snapshot->id || ! ctype_digit($parts[1])) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        return new ReportSourceSnapshotCursor($snapshot->id, (int) $parts[1]);
    }

    private function cursorToken(ReportSourceSnapshotCursor $cursor): string
    {
        return $cursor->snapshotId.':'.$cursor->afterOrdinal;
    }

    private function assertSort(ReportWindowSort $sort): void
    {
        if ($sort->field !== 'row_key' || $sort->direction !== ReportSortDirection::ASC) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED, ['fields' => 'sort_by']);
        }
    }

    private function row(string $rowKey, array $payload): array
    {
        unset($payload['drill']);

        return ['row_key' => $rowKey, ...$payload];
    }

    private function quality(ReportSourceSnapshotHeader $header): ReportQuality
    {
        $count = (string) $header->rowCount;

        return new ReportQuality(
            ReportQualityStatus::COMPLETE,
            new ReportCoverage($count, $count, $header->rowCount === 0 ? null : '1'),
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
    }

    private function sourceRefSchemaVersion(): string
    {
        return 'v'.str_replace('.', '_', $this->schemaVersion());
    }

    private function closeFormulaVersion(ReportSourceSnapshotHeader $header): string
    {
        $formulaVersion = $header->watermarks['formula_version'] ?? null;
        if (! is_string($formulaVersion) || trim($formulaVersion) === '') {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return $formulaVersion;
    }

    private function assertFormulaCompatibility(ReportQuery $query, string $formulaVersion): void
    {
        if (! hash_equals($query->definition->formulaVersion, $formulaVersion)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

    private function reportQueryHash(ReportSnapshotRef $snapshot): Sha256Hash
    {
        return $this->snapshotHash($snapshot, self::REPORT_QUERY_HASH);
    }

    private function sourceSnapshotQueryHash(ReportSnapshotRef $snapshot): Sha256Hash
    {
        return $this->snapshotHash($snapshot, self::SOURCE_SNAPSHOT_QUERY_HASH);
    }

    private function snapshotHash(ReportSnapshotRef $snapshot, string $key): Sha256Hash
    {
        $hash = $snapshot->watermarks[$key] ?? null;
        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return new Sha256Hash($hash);
    }
}
