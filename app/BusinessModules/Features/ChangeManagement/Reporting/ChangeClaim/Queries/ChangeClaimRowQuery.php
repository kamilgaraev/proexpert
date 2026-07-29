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
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimRow;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimSnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ChangeClaimRowQuery implements ReportRowQuery
{
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
                [new ReportSourceRef(
                    'change_management_versions_ledger',
                    'change_claim_contingency',
                    's'.$snapshot->id,
                    'change_claim_contingency_v1',
                    'w'.max((int) $record->version_watermark_id, (int) $record->ledger_watermark_id),
                    (int) $record->row_count,
                    new Sha256Hash((string) $record->source_hash),
                )],
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
            $payload = $this->tokenPayload($cursor->token);
            $this->assertCursor($payload, $snapshot, $sort);
            $operator = $direction === 'asc' ? '>' : '<';
            $query->where(static fn (Builder $after): Builder => $after
                ->where($column, $operator, $payload['last_sort_value'] ?? null)
                ->orWhere(static fn (Builder $tie): Builder => $tie
                    ->where($column, $payload['last_sort_value'] ?? null)
                    ->where('row_key', $operator, $payload['last_stable_row_key'])));
        }
        $models = $query->orderBy($column, $direction)->orderBy('row_key', $direction)->limit($limit + 1)->get();
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
        $this->snapshot($context, $snapshot);
        $column = self::SORTS[$sort->field] ?? throw new DomainException('report_sort_invalid');
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        foreach ($this->rows($context, $snapshot)->orderBy($column, $direction)->orderBy('row_key', $direction)->lazy($chunkSize) as $row) {
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
        if ($snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw new DomainException('report_snapshot_scope_mismatch');
        }
        $record = ChangeClaimSnapshot::query()->where('organization_id', $context->scope->organizationId)->whereKey($snapshot->id)->firstOrFail();
        if (!hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)) {
            throw new DomainException('report_snapshot_source_hash_mismatch');
        }

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
        $complete = $snapshot->quality_status === 'complete';

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage((string) $n, (string) $d, $d === 0 ? null : number_format($n / $d, 8, '.', '')),
            (array) $snapshot->warnings,
            max(0, $d - $n),
            $complete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            $complete ? [] : ['monetary_evidence'],
            [],
        );
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable()
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function tokenPayload(string $token): array
    {
        $encoded = explode('.', $token, 2)[0];
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($payload) || array_is_list($payload)) {
            throw new DomainException('report_token_invalid');
        }

        return $payload;
    }

    private function assertCursor(array $payload, ReportSnapshotRef $snapshot, ReportWindowSort $sort): void
    {
        if (($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || ($payload['sort_field'] ?? null) !== $sort->field
            || ($payload['sort_direction'] ?? null) !== $sort->direction->value
            || !is_string($payload['last_stable_row_key'] ?? null)) {
            throw new DomainException('report_cursor_identity_mismatch');
        }
    }
}
