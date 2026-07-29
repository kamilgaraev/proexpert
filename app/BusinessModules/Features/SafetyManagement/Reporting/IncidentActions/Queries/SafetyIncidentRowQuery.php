<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
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
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentSnapshot;
use Illuminate\Database\Eloquent\Builder;

final readonly class SafetyIncidentRowQuery implements ReportRowQuery
{
    private const SORTS = ['event_date', 'project_id', 'safety_site_id', 'subject_type', 'subject_id', 'event_version', 'severity', 'status', 'due_date'];

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $this->assertSort($sort);
        $record = $this->snapshot($context, $snapshot);
        $query = $this->rows($context, $snapshot);
        if ($cursor !== null) {
            $payload = $this->payload($cursor->token, $snapshot, $sort);
            $this->applyAfter($query, $sort, $payload['last_sort_value'], $payload['last_stable_row_key']);
        }
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $records = $query->orderBy($sort->field, $direction)->orderBy('row_key', $direction)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;

        return new ReportPage(
            $records->take($limit)->map(fn (SafetyIncidentRow $row): array => $this->serialize($row, $context))->all(),
            $this->totals($record),
            $this->freshness($snapshot),
            $this->quality($record),
            null,
            $limit,
            $hasMore,
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
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        foreach ($this->rows($context, $snapshot)->orderBy($sort->field, $direction)->orderBy('row_key', $direction)->lazy($chunkSize) as $row) {
            yield $this->serialize($row, $context);
        }
    }

    private function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, self::SORTS, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED);
        }
    }

    private function rows(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        $this->snapshot($context, $snapshot);

        return SafetyIncidentRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): SafetyIncidentSnapshot
    {
        $record = SafetyIncidentSnapshot::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_hash', $snapshot->sourceHash->value)
            ->first();
        if (! $record instanceof SafetyIncidentSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function serialize(SafetyIncidentRow $row, ReportExecutionContext $context): array
    {
        return [
            'row_key' => (string) $row->row_key,
            'event_date' => $row->event_date->toDateString(),
            'project_id' => (int) $row->project_id,
            'safety_site_id' => $row->safety_site_id === null ? null : (int) $row->safety_site_id,
            'subject_type' => (string) $row->subject_type,
            'subject_id' => (int) $row->subject_id,
            'event_version' => (int) $row->event_version,
            'category' => $row->category,
            'severity' => (string) $row->severity,
            'status' => (string) $row->status,
            'owner_user_id' => $row->owner_user_id === null ? null : (int) $row->owner_user_id,
            'due_date' => $row->due_date?->toDateString(),
            'created' => (bool) $row->created_flag,
            'reopened' => (bool) $row->reopened_flag,
            'closed' => (bool) $row->closed_flag,
            'closure_verified' => (bool) $row->closure_verified,
            'closure_days' => $row->closure_days,
            'evidence_id' => $context->visibility->canViewAudit ? $row->evidence_id : null,
        ];
    }

    private function totals(SafetyIncidentSnapshot $snapshot): array
    {
        return [
            'incident_count' => (int) $snapshot->incident_count,
            'violation_count' => (int) $snapshot->violation_count,
            'corrective_action_due' => (int) $snapshot->action_due_count,
            'corrective_action_overdue' => (int) $snapshot->action_overdue_count,
            'on_time_closure_count' => (int) $snapshot->action_closed_on_time_count,
            'incident_frequency' => $snapshot->exposure_complete ? $snapshot->incident_frequency : null,
        ];
    }

    private function quality(SafetyIncidentSnapshot $snapshot): ReportQuality
    {
        $complete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0 && (bool) $snapshot->exposure_complete;

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            null,
            [],
            (int) $snapshot->gap_count,
            $complete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            ! (bool) $snapshot->exposure_complete ? ['incident_frequency'] : [],
            [],
        );
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new \DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function payload(string $token, ReportSnapshotRef $snapshot, ReportWindowSort $sort): array
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
