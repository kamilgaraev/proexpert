<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
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
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceSnapshotWriter;

final readonly class ProcurementCycleReportAdapter implements ReportDataProvider, ReportDrillDownProvider, ReportRowQuery
{
    public const REPORT_CODE = 'procurement_cycle';

    public const SOURCE_KIND = 'procurement.cycle';

    public const SCHEMA_VERSION = '1.0.0';

    public const FORMULA_VERSION = 'procurement-cycle.v1';

    public const SORT_FIELD = 'cohort_date';

    public const STAGE_DRILL_COLUMN = 'stage_breakdown';

    public const AUDIT_DRILL_COLUMN = 'audit_timeline';

    private const REPORT_QUERY_HASH = 'report_query_hash';

    private const SOURCE_QUERY_HASH = 'source_snapshot_query_hash';

    public function __construct(
        private ProcurementCycleSourceSnapshotWriter $writer,
        private ReportSourceSnapshotStore $store,
        private ?CanonicalReportSourceHashBuilder $hashes = null,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query);
        $header = $this->writer->persist($query);
        $this->assertHeader($header, $query);
        $progress->advance(100);

        $provisional = new ReportSnapshotRef(
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
                self::SOURCE_QUERY_HASH => $header->queryHash->value,
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
            $header->materializedSourceHash,
        );
        $canonical = ($this->hashes ?? new CanonicalReportSourceHashBuilder)->build($query, $provisional, $this->result($context, $provisional));

        return new ReportSnapshotRef(
            $header->sourceKind,
            $header->id,
            $header->scope,
            $query->definition->definitionHash,
            $query->definition->formulaVersion,
            $canonical,
            $header->generatedAt,
            $header->staleAt,
            [
                ...$header->watermarks,
                self::REPORT_QUERY_HASH => $query->queryHash->value,
                self::SOURCE_QUERY_HASH => $header->queryHash->value,
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
            $header->materializedSourceHash,
        );
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $header = $this->header($context, $snapshot);

        return new ReportResult(
            new ReportResultMetadata($snapshot, $header->rowCount, $header->generatedAt, $header->staleAt),
            $this->totals($header),
            ReportFreshnessStatus::FRESH,
            $this->quality($header),
            new ReportProvenance(
                'procurement',
                [new ReportSourceRef(
                    'procurement',
                    'cycle_snapshot',
                    'sealed_snapshot',
                    'v1_0_0',
                    'event_'.(string) ($header->watermarks['max_event_id'] ?? 0),
                    $header->rowCount,
                    $header->sourceHash,
                )],
                $snapshot->sourceHash,
                null,
            ),
            array_map(static fn (string $id): array => ['id' => $id], $this->rowColumns()),
            ['drill_down' => true, 'source_snapshot' => true],
        );
    }

    public function page(
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
            $this->sourceCursor($context, $snapshot, $sort, $cursor),
            $limit,
        );

        return new ReportPage(
            array_map(static fn ($row): array => ['row_key' => $row->rowKey, ...$row->payload], $sourcePage->rows),
            $this->totals($header),
            ReportFreshnessStatus::FRESH,
            $this->quality($header),
            $sourcePage->nextCursor === null ? null : $this->cursorToken($sourcePage->nextCursor),
            $limit,
            $sourcePage->nextCursor !== null,
            $sort,
        );
    }

    public function cursor(
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
                    'query_hash' => $this->snapshotHash($snapshot, self::REPORT_QUERY_HASH)->value,
                    'row_key' => $row->rowKey,
                    'snapshot_id' => $snapshot->id,
                    'source_hash' => $snapshot->sourceHash->value,
                    'values' => ['row_key' => $row->rowKey, ...$row->payload],
                ];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $this->header($context, $snapshot);
        if (! in_array($input->cell->columnId, [self::STAGE_DRILL_COLUMN, self::AUDIT_DRILL_COLUMN], true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => 'columns']);
        }
        $page = $this->store->drillPage(
            $this->readRequest($context, $snapshot),
            $input->cell->rowKey,
            $input->cell->columnId,
            $this->drillCursor($snapshot, $input->cursor),
            $input->limit,
        );

        return new ReportDrillDownResult(
            array_map(
                static fn ($row): array => ['row_key' => $row->rowKey.':'.$row->columnId.':'.$row->ordinal, ...$row->payload],
                $page->rows,
            ),
            $page->nextCursor === null ? null : $this->cursorToken($page->nextCursor),
            [],
        );
    }

    private function assertQuery(ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($query->definition->code !== self::REPORT_CODE
            || $context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        if ($query->definition->formulaVersion !== self::FORMULA_VERSION
            || $query->definition->sourceSchemaVersion !== self::SCHEMA_VERSION) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

    private function assertHeader(ReportSourceSnapshotHeader $header, ReportQuery $query): void
    {
        if ($header->reportCode !== self::REPORT_CODE
            || $header->sourceKind !== self::SOURCE_KIND
            || $header->schemaVersion !== self::SCHEMA_VERSION
            || $header->scopeIdentity() !== $query->scope->canonicalIdentity()
            || $header->reportQueryHash === null
            || ! hash_equals($header->reportQueryHash->value, $query->queryHash->value)
            || ($header->watermarks['formula_version'] ?? null) !== self::FORMULA_VERSION) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }
    }

    private function header(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportSourceSnapshotHeader
    {
        if ($snapshot->kind !== self::SOURCE_KIND
            || $snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        $header = $this->store->header($this->readRequest($context, $snapshot));
        if ($header->id !== $snapshot->id
            || ! hash_equals($header->materializedSourceHash->value, $snapshot->materializedSourceHash->value)
            || $header->generatedAt != $snapshot->generatedAt
            || $header->staleAt != $snapshot->staleAt
            || ($header->watermarks['formula_version'] ?? null) !== $snapshot->formulaVersion) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return $header;
    }

    private function readRequest(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportSourceSnapshotReadRequest
    {
        return new ReportSourceSnapshotReadRequest(
            $context,
            $snapshot->id,
            self::SOURCE_KIND,
            self::REPORT_CODE,
            self::SCHEMA_VERSION,
            $this->snapshotHash($snapshot, self::SOURCE_QUERY_HASH),
            $snapshot->generatedAt,
        );
    }

    private function sourceCursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
    ): ?ReportSourceSnapshotCursor {
        if ($cursor === null) {
            return null;
        }
        if (! hash_equals($cursor->queryHash->value, $this->snapshotHash($snapshot, self::REPORT_QUERY_HASH)->value)
            || ! hash_equals($cursor->sourceHash->value, $snapshot->sourceHash->value)
            || $cursor->sort != $sort) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        $request = $this->readRequest($context, $snapshot);
        $sourceCursor = null;
        do {
            $page = $this->store->page($request, $sourceCursor, 100);
            foreach ($page->rows as $row) {
                if ($row->rowKey !== $cursor->keyset->lastStableRowKey) {
                    continue;
                }
                if (! array_key_exists(self::SORT_FIELD, $row->payload)
                    || $row->payload[self::SORT_FIELD] !== $cursor->keyset->lastSortValue) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
                }

                return new ReportSourceSnapshotCursor($snapshot->id, $row->ordinal);
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
        if ($sort->field !== self::SORT_FIELD || $sort->direction !== ReportSortDirection::ASC) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED, ['fields' => 'sort_by']);
        }
    }

    private function snapshotHash(ReportSnapshotRef $snapshot, string $key): Sha256Hash
    {
        $value = $snapshot->watermarks[$key] ?? null;
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return new Sha256Hash($value);
    }

    private function totals(ReportSourceSnapshotHeader $header): array
    {
        return [
            'cancelled_count' => (int) ($header->watermarks['cancelled_count'] ?? 0),
            'complete_count' => (int) ($header->watermarks['complete_count'] ?? 0),
            'invalid_count' => (int) ($header->watermarks['invalid_count'] ?? 0),
            'incomplete_count' => (int) ($header->watermarks['incomplete_count'] ?? 0),
            'open_count' => (int) ($header->watermarks['open_count'] ?? 0),
            'row_count' => $header->rowCount,
            'sla_eligible_count' => (int) ($header->watermarks['sla_eligible_count'] ?? 0),
            'sla_met_count' => (int) ($header->watermarks['sla_met_count'] ?? 0),
        ];
    }

    private function quality(ReportSourceSnapshotHeader $header): ReportQuality
    {
        $invalid = (int) ($header->watermarks['invalid_count'] ?? 0);
        $gaps = (int) ($header->watermarks['gap_count'] ?? 0);
        $unscopedQuarantine = (int) ($header->watermarks['unscoped_quarantine_line_count'] ?? 0);
        $complete = max(0, $header->rowCount - $invalid - $gaps);
        $partial = $invalid > 0 || $gaps > 0 || $unscopedQuarantine > 0;
        $coverageTotal = $header->rowCount + $unscopedQuarantine;

        return new ReportQuality(
            $partial ? ReportQualityStatus::PARTIAL : ReportQualityStatus::COMPLETE,
            new ReportCoverage((string) $complete, (string) $coverageTotal, $coverageTotal === 0 ? null : (string) round($complete / $coverageTotal, 8)),
            $partial ? [new ReportWarning('PROCUREMENT_CYCLE_SOURCE_GAPS', ReportWarningSeverity::WARNING, null, $invalid + $gaps + $unscopedQuarantine)] : [],
            $invalid,
            $partial ? ReportReconciliationStatus::MISMATCH : ReportReconciliationStatus::MATCHED,
            $partial ? ['stage_duration'] : [],
            [],
        );
    }

    private function rowColumns(): array
    {
        return [
            'row_key', 'cohort_date', 'purchase_request_line_id', 'request_number', 'material_name',
            'requester_id', 'buyer_id', 'priority', 'current_stage', 'outcome', 'total_cycle_seconds',
            'open_age_seconds', 'awarded_supplier_party_id', 'awarded_amount', 'currency', 'quality_status',
            'gap_codes', self::STAGE_DRILL_COLUMN, self::AUDIT_DRILL_COLUMN,
        ];
    }
}
