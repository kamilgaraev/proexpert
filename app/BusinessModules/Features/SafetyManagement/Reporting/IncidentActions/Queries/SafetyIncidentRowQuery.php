<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Support\ScopedReportSourceGuard;
use App\BusinessModules\Core\Reporting\Support\SnapshotRowKeyset;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentSnapshot;
use Illuminate\Database\Eloquent\Builder;

final readonly class SafetyIncidentRowQuery implements ReportRowQuery
{
    private const SORTS = ['event_date', 'project_id', 'safety_site_id', 'contractor_id', 'subject_type', 'subject_id', 'event_version', 'severity', 'status', 'due_date'];

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
            if ($cursor->queryHash->value !== $record->query_hash) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
            }
            $payload = SnapshotRowKeyset::payload($cursor, $snapshot, $sort);
            SnapshotRowKeyset::after($query, $sort, $payload['last_sort_value'], $payload['last_stable_row_key']);
        }
        $records = SnapshotRowKeyset::order($query, $sort)->limit($limit + 1)->get();
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
        $record = $this->snapshot($context, $snapshot);
        $lastSortValue = null;
        $lastRowKey = null;
        do {
            $query = $this->rows($context, $snapshot);
            if ($lastRowKey !== null) {
                SnapshotRowKeyset::after($query, $sort, $lastSortValue, $lastRowKey);
            }
            $records = SnapshotRowKeyset::order($query, $sort)->limit($chunkSize)->get();
            foreach ($records as $row) {
                $values = $this->serialize($row, $context);
                yield [
                    'query_hash' => (string) $record->query_hash,
                    'row_key' => (string) $row->row_key,
                    'snapshot_id' => (string) $record->id,
                    'source_hash' => (string) $record->source_hash,
                    'values' => $values,
                ];
                $lastSortValue = $values[$sort->field] ?? null;
                $lastRowKey = (string) $row->row_key;
            }
        } while ($records->count() === $chunkSize);
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
            ->where('definition_hash', $snapshot->definitionHash->value)
            ->where('formula_version', $snapshot->formulaVersion)
            ->first();
        if (! $record instanceof SafetyIncidentSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function serialize(SafetyIncidentRow $row, ReportExecutionContext $context): array
    {
        $resourceKind = match ($row->subject_type) {
            'incident' => 'safety_incident',
            'violation' => 'safety_violation',
            default => 'safety_corrective_action',
        };
        ScopedReportSourceGuard::assertAccessible($context, (int) $row->project_id, [
            new ReportScopedResource($resourceKind, (int) $row->subject_id, (int) $row->project_id),
            ...($row->safety_site_id === null ? [] : [
                new ReportScopedResource('safety_site', (int) $row->safety_site_id, (int) $row->project_id),
            ]),
            ...($row->contractor_id === null ? [] : [
                new ReportScopedResource('contractor', (int) $row->contractor_id, (int) $row->project_id),
            ]),
        ]);
        if ($context->visibility->canViewAudit && $row->evidence_id !== null) {
            ScopedReportSourceGuard::assertExactAccessible(
                $context,
                new ReportScopedResource((string) $row->evidence_type, (int) $row->subject_id, (int) $row->project_id),
            );
        }

        return [
            'row_key' => (string) $row->row_key,
            'event_date' => $row->event_date->toDateString(),
            'project_id' => (int) $row->project_id,
            'safety_site_id' => $row->safety_site_id === null ? null : (int) $row->safety_site_id,
            'contractor_id' => $row->contractor_id === null ? null : (int) $row->contractor_id,
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
            'on_time_closure_pct' => $this->percent(
                (int) $snapshot->action_closed_on_time_count,
                (int) $snapshot->action_due_count,
            ),
            'incident_frequency' => $snapshot->exposure_complete ? $snapshot->incident_frequency : null,
            'opening_backlog' => (int) $snapshot->opening_backlog_count,
            'closing_backlog' => (int) $snapshot->closing_backlog_count,
        ];
    }

    private function quality(SafetyIncidentSnapshot $snapshot): ReportQuality
    {
        $sourceComplete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;
        $complete = $sourceComplete && (bool) $snapshot->exposure_complete;
        $warnings = [];
        if (! (bool) $snapshot->exposure_complete) {
            $warnings[] = new ReportWarning(
                'SAFETY_EXPOSURE_INCOMPLETE',
                ReportWarningSeverity::CRITICAL,
                'incident_frequency',
                1,
            );
        }
        if ((int) $snapshot->unknown_count > 0) {
            $warnings[] = new ReportWarning(
                'SAFETY_SITE_OR_EVIDENCE_UNKNOWN',
                ReportWarningSeverity::CRITICAL,
                null,
                (int) $snapshot->unknown_count,
            );
        }
        $denominator = (int) $snapshot->eligible_count;
        $numerator = (int) $snapshot->projected_count;

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage(
                (string) $numerator,
                (string) $denominator,
                $this->ratio($numerator, $denominator),
            ),
            $warnings,
            (int) $snapshot->gap_count,
            $sourceComplete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
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

    private function percent(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 1_000_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

    private function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 10_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%04d', intdiv($scaled, 10_000), $scaled % 10_000);
    }

}
