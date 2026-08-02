<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries;

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
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionSnapshot;
use Illuminate\Database\Eloquent\Builder;

final readonly class WorkforceAdmissionRowQuery implements ReportRowQuery
{
    private const SORTS = ['snapshot_date', 'project_id', 'safety_site_id', 'workforce_assignment_id', 'employee_id', 'requirement_code', 'requirement_type', 'status', 'mandatory', 'blocked', 'valid_until'];

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
            $records->take($limit)->map(fn (SafetyAdmissionRow $row): array => $this->serialize($row, $context))->all(),
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

        return SafetyAdmissionRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): SafetyAdmissionSnapshot
    {
        $record = SafetyAdmissionSnapshot::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->where('source_hash', $snapshot->sourceHash->value)
            ->where('definition_hash', $snapshot->definitionHash->value)
            ->where('formula_version', $snapshot->formulaVersion)
            ->first();
        if (! $record instanceof SafetyAdmissionSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function serialize(SafetyAdmissionRow $row, ReportExecutionContext $context): array
    {
        ScopedReportSourceGuard::assertAccessible($context, (int) $row->project_id, [
            new ReportScopedResource('workforce_assignment', (int) $row->workforce_assignment_id, (int) $row->project_id),
            new ReportScopedResource('workforce_employee', (int) $row->employee_id, (int) $row->project_id),
            new ReportScopedResource('safety_site', (int) $row->safety_site_id, (int) $row->project_id),
            new ReportScopedResource('workforce_assignment_site', (int) $row->site_assignment_id, (int) $row->project_id),
            new ReportScopedResource('workforce_snapshot_evidence', (int) $row->id, (int) $row->project_id),
        ]);

        $medical = $row->requirement_type === 'medical_exam';
        $canViewMedical = $context->visibility->canViewSensitive;
        $status = (string) $row->status;
        if ($medical && ! $canViewMedical) {
            $status = (bool) $row->blocked
                ? 'blocked'
                : (in_array($status, ['expired', 'missing'], true) ? $status : 'valid');
        }

        $values = [
            'row_key' => (string) $row->row_key,
            'snapshot_date' => $row->snapshot_date->toDateString(),
            'project_id' => (int) $row->project_id,
            'safety_site_id' => (int) $row->safety_site_id,
            'workforce_assignment_id' => (int) $row->workforce_assignment_id,
            'employee_id' => (int) $row->employee_id,
            'requirement_code' => (string) $row->requirement_code,
            'requirement_type' => (string) $row->requirement_type,
            'status' => $status,
            'mandatory' => (bool) $row->mandatory,
            'blocked' => (bool) $row->blocked,
            'verified' => (bool) $row->verified,
            'valid_until' => $row->valid_until?->toDateString(),
        ];
        if ($context->visibility->canViewSensitive) {
            $values['evidence_id'] = $row->evidence_id;
            if ($medical) {
                $values['medical_details'] = $row->medical_details;
            }
        }

        return $values;
    }

    private function totals(SafetyAdmissionSnapshot $snapshot): array
    {
        return [
            'evaluated_people' => (int) $snapshot->evaluated_people,
            'admitted_people' => (int) $snapshot->admitted_people,
            'partial_people' => (int) $snapshot->partial_people,
            'not_admitted_people' => (int) $snapshot->not_admitted_people,
            'compliance_pct' => $this->percent(
                (int) $snapshot->admitted_people,
                (int) $snapshot->evaluated_people,
            ),
            'blocker_count' => (int) $snapshot->blocker_count,
            'expiring_count' => (int) $snapshot->expiring_count,
            'unverified_coverage' => (int) $snapshot->unverified_count,
        ];
    }

    private function quality(SafetyAdmissionSnapshot $snapshot): ReportQuality
    {
        $sourceComplete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;
        $warnings = [];
        if ((int) $snapshot->unknown_count > 0) {
            $warnings[] = new ReportWarning(
                'ADMISSION_EVIDENCE_UNVERIFIED',
                ReportWarningSeverity::CRITICAL,
                'unverified_coverage',
                (int) $snapshot->unknown_count,
            );
        }

        return new ReportQuality(
            $sourceComplete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage(
                (string) $snapshot->projected_count,
                (string) $snapshot->eligible_count,
                $this->ratio((int) $snapshot->projected_count, (int) $snapshot->eligible_count),
            ),
            $warnings,
            (int) $snapshot->gap_count,
            $sourceComplete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            [],
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

    private function percent(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }
        $scaled = intdiv($numerator * 10_000 + intdiv($denominator, 2), $denominator);

        return sprintf('%d.%02d', intdiv($scaled, 100), $scaled % 100);
    }
}
