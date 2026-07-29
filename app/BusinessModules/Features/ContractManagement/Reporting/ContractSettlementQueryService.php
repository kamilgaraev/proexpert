<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Reporting\FinanceSourceAccessPolicy;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
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
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\OwnerSnapshotIdentityGuard;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementExposureRecord;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementExposureSnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ContractSettlementQueryService implements ReportDrillDownProvider, ReportRowQuery
{
    public function __construct(
        private FinanceSourceAccessPolicy $sourceAccess,
        private OwnerSnapshotIdentityGuard $identityGuard,
    ) {}

    private const SORTS = [
        'contract_id' => 'contract_id',
        'project_id' => 'project_id',
        'party_id' => 'party_id',
        'currency' => 'currency',
        'effective' => 'effective_minor',
        'accepted' => 'accepted_minor',
        'cash' => 'cash_minor',
        'settlement' => 'settlement_minor',
        'unperformed_exposure' => 'unperformed_exposure_minor',
        'unpaid_exposure' => 'unpaid_exposure_minor',
        'aging_bucket' => 'aging_bucket',
    ];

    private const SOURCE_TYPES = [
        'contract',
        'contract_allocation',
        'contract_performance_act',
        'payment_document',
        'payment_transaction',
    ];

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);

        return new ReportResult(
            metadata: new ReportResultMetadata($snapshot, (int) $record->row_count, $snapshot->generatedAt, $snapshot->staleAt),
            totals: is_array($record->totals) ? $record->totals : [],
            freshness: $this->freshness($snapshot),
            quality: $this->quality($record),
            provenance: new ReportProvenance(
                sourceOfTruth: 'most',
                sourceRefs: $this->sourceRefs($context, $snapshot),
                sourceHash: $snapshot->sourceHash,
                externalConfirmationRole: 'confirmation_only',
            ),
            rowSchema: [
                ['id' => 'contract_id', 'type' => 'integer'],
                ['id' => 'project_id', 'type' => 'integer'],
                ['id' => 'party_id', 'type' => 'integer'],
                ['id' => 'direction', 'type' => 'string'],
                ['id' => 'currency', 'type' => 'currency'],
                ['id' => 'effective', 'type' => 'money_minor'],
                ['id' => 'accepted', 'type' => 'money_minor'],
                ['id' => 'cash', 'type' => 'money_minor'],
                ['id' => 'settlement', 'type' => 'money_minor'],
                ['id' => 'unperformed_exposure', 'type' => 'money_minor'],
                ['id' => 'unpaid_exposure', 'type' => 'money_minor'],
                ['id' => 'aging_bucket', 'type' => 'string'],
            ],
            capabilities: [
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
        $column = self::SORTS[$sort->field] ?? throw new DomainException('report_sort_invalid');
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        foreach ($this->rows($context, $snapshot)->orderByRaw($column.' IS NULL ASC')->orderBy($column, $direction)->orderBy('row_key', $direction)->cursor() as $row) {
            yield $this->serialize($row);
        }
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $this->snapshot($context, $snapshot);
        $record = $this->rows($context, $snapshot)->where('row_key', $input->cell->rowKey)->firstOrFail();
        $rows = [];
        $links = [];
        foreach ($this->sourceAccess->visibleRefs($context, $record->source_refs, self::SOURCE_TYPES) as $ref) {
            $id = (string) $ref['id'];
            $type = (string) $ref['type'];
            $rows[] = ['row_key' => $type.':'.$id, 'source_type' => $type, 'source_id' => $id];
            $links[] = new ReportResourceLink($type, 'r'.$id, $this->routeName($type), ['id' => (int) $id], 'available');
        }

        return new ReportDrillDownResult($rows, null, $links);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ContractSettlementExposureSnapshot
    {
        $record = ContractSettlementExposureSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->firstOrFail();
        $this->identityGuard->assert($record, $context, $snapshot, [
            'aging_policy_version' => 'aging_policy_version',
            'source_watermark_id' => 'source_fact_id',
        ]);

        return $record;
    }

    private function rows(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        return ContractSettlementExposureRecord::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function serialize(ContractSettlementExposureRecord $row): array
    {
        return [
            'row_key' => (string) $row->row_key,
            'contract_id' => (int) $row->contract_id,
            'allocation_id' => (int) $row->allocation_id,
            'project_id' => $row->project_id,
            'party_id' => $row->party_id,
            'direction' => (string) $row->direction,
            'currency' => (string) $row->currency,
            'currency_source' => (string) $row->currency_source,
            'effective' => (int) $row->effective_minor,
            'accepted' => (int) $row->accepted_minor,
            'cash' => (int) $row->cash_minor,
            'settlement' => (int) $row->settlement_minor,
            'unperformed_exposure' => (int) $row->unperformed_exposure_minor,
            'unpaid_exposure' => (int) $row->unpaid_exposure_minor,
            'aging_bucket' => (string) $row->aging_bucket,
        ];
    }

    private function quality(ContractSettlementExposureSnapshot $snapshot): ReportQuality
    {
        $numerator = (int) $snapshot->coverage_numerator;
        $denominator = (int) $snapshot->coverage_denominator;

        $complete = $snapshot->quality_status === 'complete' && $numerator === $denominator;

        return new ReportQuality(
            $complete ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            new ReportCoverage(
                (string) $numerator,
                (string) $denominator,
                $denominator === 0 ? null : number_format($numerator / $denominator, 8, '.', ''),
            ),
            $complete ? [] : [new ReportWarning('SOURCE_COVERAGE_INCOMPLETE', ReportWarningSeverity::WARNING, null, max(0, $denominator - $numerator))],
            max(0, $denominator - $numerator),
            $complete ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            $complete ? [] : ['source_coverage'],
            [],
        );
    }

    private function sourceRefs(ReportExecutionContext $context, ReportSnapshotRef $snapshot): array
    {
        $grouped = [];
        foreach ($this->rows($context, $snapshot)->orderBy('row_key')->get() as $row) {
            foreach ((array) $row->source_refs as $ref) {
                if (! is_array($ref)
                    || ! in_array($ref['type'] ?? null, self::SOURCE_TYPES, true)
                    || ! ctype_digit((string) ($ref['id'] ?? ''))
                    || preg_match('/^[a-f0-9]{64}$/', (string) ($ref['hash'] ?? '')) !== 1) {
                    throw new DomainException('contract_settlement_source_provenance_invalid');
                }
                $type = (string) $ref['type'];
                $identity = (string) $ref['id'].':'.(string) $ref['hash'];
                $grouped[$type][$identity] = ['id' => (string) $ref['id'], 'hash' => (string) $ref['hash']];
            }
        }
        $sources = [];
        foreach ($grouped as $type => $refs) {
            $refs = array_values($refs);
            usort($refs, static fn (array $left, array $right): int => [$left['id'], $left['hash']] <=> [$right['id'], $right['hash']]);
            $sources[] = new ReportSourceRef(
                $type,
                $type,
                's'.substr(hash('sha256', CanonicalJson::encode($refs)), 0, 32),
                $type.'_v1',
                'w'.max(array_map(static fn (array $ref): int => (int) $ref['id'], $refs)),
                count($refs),
                new Sha256Hash(hash('sha256', CanonicalJson::encode($refs))),
            );
        }
        if ($sources === []) {
            throw new DomainException('contract_settlement_source_provenance_missing');
        }

        return $sources;
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

    private function routeName(string $type): string
    {
        return match ($type) {
            'contract', 'contract_allocation' => 'admin.contracts.show',
            'contract_performance_act' => 'admin.contracts.acts.show',
            'payment_document', 'payment_transaction' => 'admin.payments.show',
            default => throw new DomainException('report_drill_down_source_invalid'),
        };
    }
}
