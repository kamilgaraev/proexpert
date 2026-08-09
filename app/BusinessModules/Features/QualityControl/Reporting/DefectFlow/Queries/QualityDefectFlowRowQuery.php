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
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowRow;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowSnapshot;
use Illuminate\Database\Eloquent\Builder;

final readonly class QualityDefectFlowRowQuery implements ReportRowQuery
{
    private const SORTS = [
        'cohort_date',
        'project_id',
        'contractor_id',
        'schedule_task_id',
        'quality_defect_id',
        'event_version',
        'severity',
        'status',
        'due_date',
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
            if ($cursor->queryHash->value !== $record->query_hash) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
            }
            $payload = SnapshotRowKeyset::payload($cursor, $snapshot, $sort);
            SnapshotRowKeyset::after($query, $sort, $payload['last_sort_value'], $payload['last_stable_row_key']);
        }
        $records = SnapshotRowKeyset::order($query, $sort)->limit($limit + 1)->get();
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
            ->where('source_hash', $snapshot->materializedSourceHash->value)
            ->where('definition_hash', $snapshot->definitionHash->value)
            ->where('formula_version', $snapshot->formulaVersion)
            ->first();
        if (! $record instanceof QualityDefectFlowSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function serialize(QualityDefectFlowRow $row, ReportExecutionContext $context): array
    {
        ScopedReportSourceGuard::assertAccessible($context, (int) $row->project_id, [
            new ReportScopedResource('quality_defect', (int) $row->quality_defect_id, (int) $row->project_id),
            ...($row->schedule_task_id === null ? [] : [
                new ReportScopedResource('schedule_task', (int) $row->schedule_task_id, (int) $row->project_id),
                new ReportScopedResource('task', (int) $row->schedule_task_id, (int) $row->project_id),
            ]),
            ...($row->contractor_id === null ? [] : [
                new ReportScopedResource('contractor', (int) $row->contractor_id, (int) $row->project_id),
            ]),
        ]);
        if ($context->visibility->canViewAudit) {
            foreach (($row->evidence_refs ?? []) as $evidence) {
                if (is_array($evidence) && isset($evidence['id'])) {
                    ScopedReportSourceGuard::assertExactAccessible(
                        $context,
                        new ReportScopedResource(
                            ($evidence['type'] ?? null) === 'status_comment' ? 'status_comment' : 'quality_defect_photo',
                            (int) $evidence['id'],
                            (int) $row->project_id,
                        ),
                    );
                }
            }
        }

        return [
            'row_key' => (string) $row->row_key,
            'cohort_date' => $row->cohort_date->toDateString(),
            'project_id' => (int) $row->project_id,
            'contractor_id' => $row->contractor_id === null ? null : (int) $row->contractor_id,
            'schedule_task_id' => $row->schedule_task_id === null ? null : (int) $row->schedule_task_id,
            'quality_defect_id' => (int) $row->quality_defect_id,
            'event_version' => (int) $row->event_version,
            'severity' => (string) $row->severity,
            'status' => (string) $row->status,
            'created' => (bool) $row->created_flag,
            'reopened' => (bool) $row->reopened_flag,
            'closed' => (bool) $row->closed_flag,
            'closing' => (bool) $row->closing_flag,
            'cycle_days' => $row->cycle_days,
            'due_date' => $row->due_date?->toDateString(),
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
            'due' => (int) $snapshot->due_count,
            'overdue' => (int) $snapshot->overdue_count,
            'overdue_pct' => $snapshot->overdue_pct,
            'mature_cohort' => (int) $snapshot->mature_cohort_count,
            'first_pass' => (int) $snapshot->first_pass_count,
            'mature_reopened' => (int) $snapshot->mature_reopened_count,
            'reopen_rate' => $snapshot->reopen_rate,
            'first_pass_yield' => $snapshot->first_pass_yield,
        ];
    }

    private function quality(QualityDefectFlowSnapshot $snapshot): ReportQuality
    {
        $emptyMatureCohort = (int) $snapshot->mature_cohort_count === 0;
        $emptyDueCohort = (int) $snapshot->due_count === 0;
        $sourceComplete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;
        $complete = $sourceComplete && ! $emptyMatureCohort && ! $emptyDueCohort;
        $denominator = (int) $snapshot->eligible_count;
        $numerator = (int) $snapshot->projected_count;
        $warnings = [];
        if ((int) $snapshot->gap_count > 0) {
            $warnings[] = new ReportWarning(
                'DEFECT_HISTORY_GAP',
                ReportWarningSeverity::CRITICAL,
                null,
                (int) $snapshot->gap_count,
            );
        }
        if ((int) $snapshot->unknown_count > 0) {
            $warnings[] = new ReportWarning(
                'DEFECT_CLOSURE_EVIDENCE_MISSING',
                ReportWarningSeverity::CRITICAL,
                'closed',
                (int) $snapshot->unknown_count,
            );
        }
        if ($emptyMatureCohort) {
            $warnings[] = new ReportWarning(
                'DEFECT_MATURE_COHORT_EMPTY',
                ReportWarningSeverity::WARNING,
                'first_pass_yield',
                0,
            );
        }
        if ($emptyDueCohort) {
            $warnings[] = new ReportWarning(
                'DEFECT_DUE_COHORT_EMPTY',
                ReportWarningSeverity::WARNING,
                'overdue_pct',
                0,
            );
        }

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage((string) $numerator, (string) $denominator, $this->ratio($numerator, $denominator)),
            $warnings,
            (int) $snapshot->gap_count,
            $sourceComplete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            [
                ...((int) $snapshot->unknown_count > 0 || $emptyMatureCohort
                    ? ['first_pass_yield', 'reopen_rate']
                    : []),
                ...($emptyDueCohort ? ['overdue_pct'] : []),
            ],
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
}
