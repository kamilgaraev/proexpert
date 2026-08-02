<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\OwnerSnapshotIdentityGuard;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimRow;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimSnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ChangeClaimRowQuery implements ReportRowQuery
{
    public function __construct(private OwnerSnapshotIdentityGuard $identityGuard) {}

    private const SORTS = [
        'occurred_on' => 'occurred_on',
        'project_id' => 'project_id',
        'contract_id' => 'contract_id',
        'change_request_id' => 'change_request_id',
        'change_version' => 'change_version',
        'currency' => 'currency',
        'proposed_exposure' => 'proposed_exposure_minor',
        'approved_exposure' => 'approved_exposure_minor',
        'linked_claim' => 'linked_claim_minor',
        'closing_contingency' => 'closing_contingency_minor',
    ];

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $quality = $this->quality($record);

        return new ReportResult(
            new ReportResultMetadata($snapshot, (int) $record->row_count, $snapshot->generatedAt, $snapshot->staleAt),
            (array) $record->totals,
            $this->freshness($snapshot),
            $quality,
            new ReportProvenance(
                'most',
                [
                    new ReportSourceRef(
                        'change_request_versions',
                        'change_request_versions',
                        's'.$snapshot->id,
                        'change_request_versions_v1',
                        'w'.(int) $record->version_watermark_id,
                        (int) $record->row_count,
                        new Sha256Hash((string) $record->source_hash),
                    ),
                    new ReportSourceRef(
                        'contingency_ledger',
                        'contingency_ledger',
                        's'.$snapshot->id,
                        'contingency_ledger_v1',
                        'w'.(int) $record->ledger_watermark_id,
                        (int) $record->row_count,
                        new Sha256Hash((string) $record->source_hash),
                    ),
                ],
                $snapshot->sourceHash,
                'confirmation_only',
            ),
            [
                ['id' => 'occurred_on', 'type' => 'date'],
                ['id' => 'project_id', 'type' => 'integer'],
                ['id' => 'contract_id', 'type' => 'integer'],
                ['id' => 'allocation_id', 'type' => 'integer'],
                ['id' => 'change_request_id', 'type' => 'integer'],
                ['id' => 'change_version', 'type' => 'integer'],
                ['id' => 'status', 'type' => 'string'],
                ['id' => 'currency', 'type' => 'currency'],
                ['id' => 'proposed_exposure', 'type' => 'money_minor'],
                ['id' => 'approved_exposure', 'type' => 'money_minor'],
                ['id' => 'linked_claim', 'type' => 'money_minor'],
                ['id' => 'closing_contingency', 'type' => 'money_minor'],
            ],
            [
                'drill_down' => true,
                'export' => $context->visibility->canExport,
                'sensitive_costs' => $context->visibility->canViewSensitive,
                'audit' => $context->visibility->canViewAudit,
            ],
        );
    }

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $record = $this->snapshot($context, $snapshot);
        $column = self::SORTS[$sort->field] ?? throw new DomainException('report_sort_invalid');
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $query = $this->rows($context, $snapshot);
        if ($cursor !== null) {
            $this->assertCursor($cursor, $snapshot, $sort);
            $this->applyAfter($query, $column, $direction, $cursor->keyset->lastSortValue, $cursor->keyset->lastStableRowKey);
        }
        $models = $query->orderByRaw($column.' IS NULL ASC')->orderBy($column, $direction)->orderBy('row_key', $direction)->limit($limit + 1)->get();
        $hasMore = $models->count() > $limit;
        $rows = $models->take($limit)->map($this->serialize(...))->values()->all();

        return new ReportPage($rows, (array) $record->totals, $this->freshness($snapshot), $this->quality($record), null, $limit, $hasMore, $sort);
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new DomainException('report_chunk_size_invalid');
        }
        $this->snapshot($context, $snapshot);
        $column = self::SORTS[$sort->field] ?? throw new DomainException('report_sort_invalid');
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        foreach ($this->rows($context, $snapshot)->orderByRaw($column.' IS NULL ASC')->orderBy($column, $direction)->orderBy('row_key', $direction)->cursor() as $row) {
            yield $this->serialize($row);
        }
    }

    public function row(ReportExecutionContext $context, ReportSnapshotRef $snapshot, string $rowKey): ChangeClaimRow
    {
        $this->snapshot($context, $snapshot);

        return $this->rows($context, $snapshot)->where('row_key', $rowKey)->firstOrFail();
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ChangeClaimSnapshot
    {
        $record = ChangeClaimSnapshot::query()->where('organization_id', $context->scope->organizationId)->whereKey($snapshot->id)->firstOrFail();
        $this->identityGuard->assert($record, $context, $snapshot, [
            'version_watermark_id' => 'change_request_version_id',
            'ledger_watermark_id' => 'contingency_ledger_entry_id',
        ]);

        return $record;
    }

    private function rows(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        return ChangeClaimRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function serialize(ChangeClaimRow $row): array
    {
        return [
            'row_key' => (string) $row->row_key,
            'occurred_on' => $row->occurred_on->format('Y-m-d'),
            'project_id' => (int) $row->project_id,
            'contract_id' => $row->contract_id,
            'allocation_id' => (int) $row->contract_project_allocation_id,
            'change_request_id' => $row->change_request_id,
            'change_version' => $row->change_version,
            'status' => (string) $row->status,
            'currency' => (string) $row->currency,
            'proposed_exposure' => (int) $row->proposed_exposure_minor,
            'approved_exposure' => (int) $row->approved_exposure_minor,
            'linked_claim' => (int) $row->linked_claim_minor,
            'opening_contingency' => (int) $row->opening_contingency_minor,
            'allocated_contingency' => (int) $row->allocated_contingency_minor,
            'consumed_contingency' => (int) $row->consumed_contingency_minor,
            'released_contingency' => (int) $row->released_contingency_minor,
            'closing_contingency' => (int) $row->closing_contingency_minor,
            'quality_status' => (string) $row->quality_status,
        ];
    }

    private function quality(ChangeClaimSnapshot $snapshot): ReportQuality
    {
        $n = (int) $snapshot->coverage_numerator;
        $d = (int) $snapshot->coverage_denominator;
        $complete = $snapshot->quality_status === 'complete' && $n === $d;

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage((string) $n, (string) $d, $d === 0 ? null : number_format($n / $d, 8, '.', '')),
            $complete ? [] : [new ReportWarning('MONETARY_EVIDENCE_INCOMPLETE', ReportWarningSeverity::WARNING, 'monetary_evidence', max(0, $d - $n))],
            max(0, $d - $n),
            $complete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            $complete ? [] : ['monetary_evidence'],
            [],
        );
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function applyAfter(Builder $query, string $column, string $direction, mixed $value, string $rowKey): void
    {
        $operator = $direction === 'asc' ? '>' : '<';
        if ($value === null) {
            $query->whereNull($column)->where('row_key', $operator, $rowKey);

            return;
        }
        $query->where(static fn (Builder $after): Builder => $after
            ->where($column, $operator, $value)
            ->orWhere(static fn (Builder $tie): Builder => $tie
                ->where($column, $value)
                ->where('row_key', $operator, $rowKey))
            ->orWhereNull($column));
    }

    private function assertCursor(ReportCursor $cursor, ReportSnapshotRef $snapshot, ReportWindowSort $sort): void
    {
        if ($cursor->sourceHash->value !== $snapshot->sourceHash->value
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw new DomainException('report_cursor_identity_mismatch');
        }
    }
}
