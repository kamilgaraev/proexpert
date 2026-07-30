<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Queries;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\IntercompanyContractFlowRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use Illuminate\Database\Eloquent\Builder;

final readonly class IntercompanyContractFlowRowQuery implements ReportDrillDownProvider, ReportRowQuery
{
    private const SORTS = [
        'period_start',
        'project_id',
        'counterparty_organization_id',
        'currency',
        'internal_minor',
        'external_minor',
        'unclassified_minor',
        'total_minor',
        'internal_share',
        'external_share',
        'unclassified_share',
        'linked_spread_minor',
    ];

    public function __construct(private IntercompanyContractFlowSnapshotMaterializer $materializer) {}

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $this->assertRequest($context, $snapshot, $sort);
        if ($cursor !== null) {
            $this->assertCursor($cursor, $snapshot, $sort);
        }
        $builder = $this->base($context, $snapshot);
        if ($cursor !== null) {
            $this->applyAfter(
                $builder,
                $sort->field,
                $sort->direction,
                $cursor->keyset->lastSortValue,
                $cursor->keyset->lastStableRowKey,
            );
        }

        $snapshotRecord = $this->materializer->snapshot($context, $snapshot);
        $records = $this->ordered($builder, $sort)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)->map($this->row(...))->values()->all();

        return new ReportPage(
            $rows,
            $snapshotRecord->totals,
            ReportFreshnessStatus::from((string) $snapshotRecord->freshness_status),
            $this->materializer->quality($snapshotRecord),
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
        $this->assertRequest($context, $snapshot, $sort);
        $this->materializer->snapshot($context, $snapshot);
        foreach ($this->ordered($this->base($context, $snapshot), $sort)->cursor() as $record) {
            yield $this->row($record);
        }
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $this->assertSnapshot($context, $snapshot);
        $this->materializer->snapshot($context, $snapshot);
        if (! in_array($input->cell->columnId, self::SORTS, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
        }
        $row = $this->base($context, $snapshot)->where('row_key', $input->cell->rowKey)->first();
        if (! $row instanceof IntercompanyContractFlowRow) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }

        $scoped = [];
        foreach ($context->scope->resources as $resource) {
            $scoped[$resource->kind.':'.$resource->id] = true;
        }
        $details = [];
        $links = [];
        $sourceRefs = $this->sourceRefs($row->source_refs);
        if ($sourceRefs === []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        foreach ($sourceRefs as $sourceRef) {
            $identity = $this->authorizationIdentity($sourceRef);
            if (! isset($scoped[$identity])) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
            }
            $details[] = [
                'row_key' => $identity,
                'column_id' => $input->cell->columnId,
                'source_type' => $sourceRef['type'],
                'source_id' => $sourceRef['id'],
                'snapshot_row_key' => $input->cell->rowKey,
            ];
            $links[] = new ReportResourceLink(
                $this->resourceType($sourceRef),
                'r'.$this->resourceId($sourceRef),
                $this->routeName($sourceRef['type']),
                $this->routeParams($sourceRef),
                'available',
            );
        }

        return new ReportDrillDownResult($details, null, $links);
    }

    private function ordered(
        Builder $builder,
        ReportWindowSort $sort,
    ): Builder {
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';

        return $builder
            ->orderByRaw($sort->field.' IS NULL ASC')
            ->orderBy($sort->field, $direction)
            ->orderBy('row_key', $direction);
    }

    private function base(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        return IntercompanyContractFlowRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function assertRequest(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): void {
        $this->assertSnapshot($context, $snapshot);
        if (! in_array($sort->field, self::SORTS, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SORT_UNSUPPORTED, ['fields' => ['sort']]);
        }
    }

    private function assertSnapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): void
    {
        if ($snapshot->kind !== IntercompanyContractFlowSnapshotMaterializer::CODE
            || $snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    private function row(IntercompanyContractFlowRow $record): array
    {
        return [
            'row_key' => (string) $record->row_key,
            'project_id' => (int) $record->project_id,
            'allocation_id' => (int) $record->allocation_id,
            'counterparty_organization_id' => $record->counterparty_organization_id === null ? null : (int) $record->counterparty_organization_id,
            'currency' => $record->currency,
            'period_start' => $record->period_start->format('Y-m-d'),
            'internal_minor' => (int) $record->internal_minor,
            'external_minor' => (int) $record->external_minor,
            'unclassified_minor' => (int) $record->unclassified_minor,
            'total_minor' => (int) $record->total_minor,
            'internal_share' => $record->internal_share === null ? null : (string) $record->internal_share,
            'external_share' => $record->external_share === null ? null : (string) $record->external_share,
            'unclassified_share' => $record->unclassified_share === null ? null : (string) $record->unclassified_share,
            'linked_spread_minor' => $record->linked_spread_minor === null ? null : (int) $record->linked_spread_minor,
        ];
    }

    private function assertCursor(ReportCursor $cursor, ReportSnapshotRef $snapshot, ReportWindowSort $sort): void
    {
        if (! hash_equals($snapshot->sourceHash->value, $cursor->sourceHash->value)
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID, ['fields' => ['cursor']]);
        }
    }

    private function applyAfter(
        Builder $builder,
        string $column,
        ReportSortDirection $direction,
        string|int|float|bool|null $value,
        string $rowKey,
    ): void {
        $operator = $direction === ReportSortDirection::ASC ? '>' : '<';
        if ($value === null) {
            $builder->whereNull($column)->where('row_key', $operator, $rowKey);

            return;
        }
        $builder->where(static fn (Builder $after): Builder => $after
            ->where($column, $operator, $value)
            ->orWhere(static fn (Builder $tie): Builder => $tie
                ->where($column, $value)
                ->where('row_key', $operator, $rowKey))
            ->orWhereNull($column));
    }

    private function sourceRefs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $refs = [];
        foreach ($value as $ref) {
            if (! is_array($ref)
                || ! is_string($ref['type'] ?? null)
                || (! is_int($ref['id'] ?? null) && ! ctype_digit((string) ($ref['id'] ?? '')))) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
            }
            $normalized = ['type' => $ref['type'], 'id' => (string) $ref['id']];
            if ($ref['type'] === 'contract_allocation') {
                if (! isset($ref['contract_id']) || ! ctype_digit((string) $ref['contract_id'])) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
                }
                $normalized['contract_id'] = (string) $ref['contract_id'];
            }
            $refs[] = $normalized;
        }

        return $refs;
    }

    private function routeName(string $type): string
    {
        return match ($type) {
            'contract_allocation' => 'admin.contracts.show',
            'approved_act' => 'admin.contracts.acts.show',
            'payment_document', 'payment_transaction' => 'admin.payments.show',
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN),
        };
    }

    private function routeParams(array $sourceRef): array
    {
        return [
            'id' => (int) ($sourceRef['type'] === 'contract_allocation'
                ? $sourceRef['contract_id']
                : $sourceRef['id']),
        ];
    }

    private function authorizationIdentity(array $sourceRef): string
    {
        return $this->resourceType($sourceRef).':'.$this->resourceId($sourceRef);
    }

    private function resourceType(array $sourceRef): string
    {
        return $sourceRef['type'] === 'contract_allocation' ? 'contract' : $sourceRef['type'];
    }

    private function resourceId(array $sourceRef): string
    {
        return (string) ($sourceRef['type'] === 'contract_allocation'
            ? $sourceRef['contract_id']
            : $sourceRef['id']);
    }
}
