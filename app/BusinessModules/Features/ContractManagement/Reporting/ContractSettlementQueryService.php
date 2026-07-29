<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
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
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementExposureRecord;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementExposureSnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ContractSettlementQueryService implements ReportRowQuery, ReportDrillDownProvider
{
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
                sourceRefs: [new ReportSourceRef(
                    source: 'contract_settlement_source_facts',
                    snapshotKind: 'contract_settlement_exposure',
                    snapshotId: 's'.$snapshot->id,
                    schemaVersion: 'contract_settlement_source_facts_v1',
                    watermark: 'w'.(string) $record->source_watermark_id,
                    rowCount: (int) $record->row_count,
                    hash: new Sha256Hash((string) $record->source_hash),
                )],
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
            $position = $this->tokenPayload($cursor->token);
            $this->assertCursor($position, $snapshot, $sort);
            $operator = $direction === 'asc' ? '>' : '<';
            $query->where(static fn (Builder $after): Builder => $after
                ->where($column, $operator, $position['last_sort_value'] ?? null)
                ->orWhere(static fn (Builder $tie): Builder => $tie
                    ->where($column, $position['last_sort_value'] ?? null)
                    ->where('row_key', $operator, $position['last_stable_row_key'])));
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
        $column = self::SORTS[$sort->field] ?? throw new DomainException('report_sort_invalid');
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        foreach ($this->rows($context, $snapshot)->orderBy($column, $direction)->orderBy('row_key', $direction)->lazy($chunkSize) as $row) {
            yield $this->serialize($row);
        }
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $this->snapshot($context, $snapshot);
        $position = $this->tokenPayload($request->token);
        if (($position['organization_id'] ?? null) !== $context->scope->organizationId
            || ($position['snapshot_id'] ?? null) !== $snapshot->id
            || ($position['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || !is_string($position['row_key'] ?? null)) {
            throw new DomainException('report_drill_down_token_invalid');
        }
        $record = $this->rows($context, $snapshot)->where('row_key', $position['row_key'])->firstOrFail();
        $resources = [];
        foreach ($context->scope->resources as $resource) {
            $resources[$resource->kind.':'.$resource->id] = true;
        }
        $rows = [];
        $links = [];
        foreach ((array) $record->source_refs as $ref) {
            if (!is_array($ref) || !in_array($ref['type'] ?? null, self::SOURCE_TYPES, true)) {
                continue;
            }
            $id = (string) ($ref['id'] ?? '');
            $type = (string) $ref['type'];
            if (!isset($resources[$type.':'.$id]) || !ctype_digit($id)) {
                continue;
            }
            $rows[] = ['row_key' => $type.':'.$id, 'source_type' => $type, 'source_id' => $id];
            $links[] = new ReportResourceLink($type, 'r'.$id, $this->routeName($type), ['id' => (int) $id], 'available');
        }

        return new ReportDrillDownResult($rows, null, $links);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ContractSettlementExposureSnapshot
    {
        if ($snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw new DomainException('report_snapshot_scope_mismatch');
        }
        $record = ContractSettlementExposureSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->firstOrFail();
        if (!hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)) {
            throw new DomainException('report_snapshot_source_hash_mismatch');
        }

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

        return new ReportQuality(
            ReportQualityStatus::COMPLETE,
            new ReportCoverage((string) $numerator, (string) $denominator, number_format($numerator / max(1, $denominator), 8, '.', '')),
            [],
            max(0, $denominator - $numerator),
            $numerator === $denominator ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            [],
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
