<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries;

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
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetyAdmissionSnapshot;
use Illuminate\Database\Eloquent\Builder;

final readonly class WorkforceAdmissionRowQuery implements ReportRowQuery
{
    private const SORTS = ['snapshot_date', 'project_id', 'safety_site_id', 'employee_id', 'requirement_code', 'status', 'valid_until'];

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
            ->first();
        if (! $record instanceof SafetyAdmissionSnapshot) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function serialize(SafetyAdmissionRow $row, ReportExecutionContext $context): array
    {
        $medical = $row->requirement_type === 'medical_exam';
        $canViewMedical = $context->visibility->canViewSensitive;
        $status = (string) $row->status;
        if ($medical && ! $canViewMedical) {
            $status = (bool) $row->blocked
                ? 'blocked'
                : (in_array($status, ['expired', 'missing'], true) ? $status : 'valid');
        }

        return [
            'row_key' => (string) $row->row_key,
            'snapshot_date' => $row->snapshot_date->toDateString(),
            'project_id' => (int) $row->project_id,
            'safety_site_id' => (int) $row->safety_site_id,
            'employee_id' => (int) $row->employee_id,
            'requirement_code' => (string) $row->requirement_code,
            'requirement_type' => (string) $row->requirement_type,
            'status' => $status,
            'blocked' => (bool) $row->blocked,
            'verified' => (bool) $row->verified,
            'valid_until' => $row->valid_until?->toDateString(),
            'evidence_id' => $medical && ! $canViewMedical ? null : $row->evidence_id,
            'medical_details' => $canViewMedical ? $row->medical_details : null,
        ];
    }

    private function totals(SafetyAdmissionSnapshot $snapshot): array
    {
        return [
            'evaluated_people' => (int) $snapshot->evaluated_people,
            'admitted_people' => (int) $snapshot->admitted_people,
            'partial_people' => (int) $snapshot->partial_people,
            'not_admitted_people' => (int) $snapshot->not_admitted_people,
            'blocker_count' => (int) $snapshot->blocker_count,
            'expiring_count' => (int) $snapshot->expiring_count,
            'unverified_coverage' => (int) $snapshot->unverified_count,
        ];
    }

    private function quality(SafetyAdmissionSnapshot $snapshot): ReportQuality
    {
        $complete = (int) $snapshot->gap_count === 0 && (int) $snapshot->unknown_count === 0;

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            null,
            [],
            (int) $snapshot->gap_count,
            $complete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
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
