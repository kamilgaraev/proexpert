<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Queries;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowRow;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowSnapshot;
use Illuminate\Database\Eloquent\Builder;

final readonly class QualityDefectFlowRowQuery implements ReportRowQuery
{
    private const SORTS = [
        'cohort_date',
        'project_id',
        'contractor_id',
        'quality_defect_id',
        'event_version',
        'severity',
        'status',
    ];

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        if (! in_array($sort->field, self::SORTS, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED);
        }
        $record = $this->snapshot($context, $snapshot);
        $query = $this->rows($context, $snapshot);
        if ($cursor !== null) {
            $payload = $this->cursorPayload($cursor->token, $snapshot, $sort);
            $this->applyAfter($query, $sort, $payload['last_sort_value'], $payload['last_stable_row_key']);
        }
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $records = $query->orderBy($sort->field, $direction)->orderBy('row_key', $direction)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $records = $records->take($limit);

        return new ReportPage(
            rows: $records->map(fn (QualityDefectFlowRow $row): array => $this->serialize($row, $context))->all(),
            totals: $this->totals($record),
            freshness: $this->freshness($snapshot),
            quality: $this->quality($record),
            nextCursor: null,
            limit: $limit,
            hasMore: $hasMore,
            sort: $sort,
        );
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        if (! in_array($sort->field, self::SORTS, true) || $chunkSize < 1 || $chunkSize > 1000) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED);
        }
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        foreach ($this->rows($context, $snapshot)->orderBy($sort->field, $direction)->orderBy('row_key', $direction)->lazy($chunkSize) as $row) {
            yield $this->serialize($row, $context);
        }
    }

    private function rows(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        $this->snapshot($context, $snapshot);

        return QualityDefectFlowRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): QualityDefectFlowSnapshot
    {
        $record = QualityDefectFlowSnapshot::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_hash', $snapshot->sourceHash->value)
            ->first();
        if (! $record instanceof QualityDefectFlowSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function serialize(QualityDefectFlowRow $row, ReportExecutionContext $context): array
    {
        return [
            'row_key' => (string) $row->row_key,
            'cohort_date' => $row->cohort_date->toDateString(),
            'project_id' => (int) $row->project_id,
            'contractor_id' => $row->contractor_id === null ? null : (int) $row->contractor_id,
            'quality_defect_id' => (int) $row->quality_defect_id,
            'event_version' => (int) $row->event_version,
            'severity' => (string) $row->severity,
            'status' => (string) $row->status,
            'created' => (bool) $row->created_flag,
            'reopened' => (bool) $row->reopened_flag,
            'closed' => (bool) $row->closed_flag,
            'closing' => (bool) $row->closing_flag,
            'cycle_days' => $row->cycle_days,
            'evidence_refs' => $context->visibility->canViewAudit ? ($row->evidence_refs ?? []) : [],
        ];
    }

    private function totals(QualityDefectFlowSnapshot $snapshot): array
    {
        return [
            'opening' => (int) $snapshot->opening_count,
            'created' => (int) $snapshot->created_count,
            'reopened' => (int) $snapshot->reopened_count,
            'closed' => (int) $snapshot->closed_count,
            'closing' => (int) $snapshot->closing_count,
        ];
    }

    private function quality(QualityDefectFlowSnapshot $snapshot): ReportQuality
    {
        $complete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;
        $denominator = (int) $snapshot->eligible_count;
        $numerator = (int) $snapshot->projected_count;

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage((string) $numerator, (string) $denominator, $this->ratio($numerator, $denominator)),
            [],
            (int) $snapshot->gap_count,
            $complete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            (int) $snapshot->unknown_count > 0 ? ['first_pass_yield'] : [],
            [],
        );
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new \DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 10_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    private function cursorPayload(string $token, ReportSnapshotRef $snapshot, ReportWindowSort $sort): array
    {
        $encoded = explode('.', $token, 2)[0] ?? '';
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload)
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || ($payload['sort_field'] ?? null) !== $sort->field
            || ($payload['sort_direction'] ?? null) !== $sort->direction->value
            || ! is_string($payload['last_stable_row_key'] ?? null)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        return $payload;
    }

    private function applyAfter(Builder $query, ReportWindowSort $sort, mixed $value, string $rowKey): void
    {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        $query->where(static function (Builder $query) use ($sort, $value, $rowKey, $operator): void {
            if ($value === null) {
                $query->whereNull($sort->field)->where('row_key', $operator, $rowKey);

                return;
            }
            $query->where($sort->field, $operator, $value)
                ->orWhere(static function (Builder $query) use ($sort, $value, $rowKey, $operator): void {
                    $query->where($sort->field, $value)->where('row_key', $operator, $rowKey);
                });
        });
    }
}
