<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
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
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityProjection;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\ProjectPortfolioHealthProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class BudgetingPortfolioQueryService implements ReportDrillDownProvider, ReportDrillDownTokenColumns, ReportRowQuery
{
    public function drillDownTokenColumns(): array
    {
        return ['drill' => 'source_refs'];
    }

    private const HEALTH_COLUMNS = [
        'project',
        'currency',
        'revenue',
        'cost',
        'margin',
        'margin_percent',
        'wip',
        'ftc',
        'eac',
        'ctc',
        'risk',
    ];

    private const LIQUIDITY_COLUMNS = [
        'date',
        'project',
        'scenario',
        'currency',
        'opening',
        'inflow',
        'outflow',
        'closing',
        'gap',
        'quality',
    ];

    private const HEALTH_SOURCE_TYPES = [
        'project',
        'budget_line',
        'approved_act',
        'payment_transaction',
        'payment_document',
        'earned_value',
        'actual_cost',
        'budget_reservation',
    ];

    private const LIQUIDITY_SOURCE_TYPES = [
        'project',
        'payment_transaction',
        'payment_schedule',
        'payment_document',
        'budget_reservation',
        'budget_plan',
        'opening_balance',
    ];

    private const HEALTH_SORTS = [
        'risk_rank',
        'project_name',
        'revenue',
        'cost',
        'margin',
        'margin_percent',
        'wip',
        'ftc',
        'eac',
        'ctc',
    ];

    private const LIQUIDITY_SORTS = [
        'forecast_date',
        'project_name',
        'currency',
        'scenario',
        'opening',
        'inflow',
        'outflow',
        'closing',
        'gap',
    ];

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $this->assertIdentity($context, $snapshot);
        $this->assertSort($snapshot, $sort);

        if ($cursor !== null) {
            $this->assertCursor($cursor, $snapshot, $sort);
        }

        $snapshotRecord = $this->snapshot($context, $snapshot);
        $query = $this->rowQuery($context, $snapshot);
        if ($cursor !== null) {
            $this->applyAfter(
                $query,
                $sort->field,
                $sort->direction,
                $cursor->keyset->lastSortValue,
                $cursor->keyset->lastStableRowKey,
            );
        }

        $query = $this->orderedQuery($query, $sort)->limit($limit + 1);
        $records = $query->get();
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)->map(
            fn (Model $record): array => $this->row($snapshot, $record),
        )->values()->all();

        return new ReportPage(
            rows: $rows,
            totals: $snapshotRecord->totals,
            freshness: ReportFreshnessStatus::from((string) $snapshotRecord->freshness_status),
            quality: BudgetingPortfolioProjectionService::qualityFromRecord($snapshotRecord),
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
        $this->assertIdentity($context, $snapshot);
        $this->assertSort($snapshot, $sort);
        $this->snapshot($context, $snapshot);
        foreach ($this->orderedQuery($this->rowQuery($context, $snapshot), $sort)->cursor() as $record) {
            yield $this->row($snapshot, $record);
        }
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $this->assertIdentity($context, $snapshot);
        $allowedColumns = $snapshot->kind === BudgetingPortfolioProjectionService::HEALTH_CODE
            ? self::HEALTH_COLUMNS
            : self::LIQUIDITY_COLUMNS;
        if (! in_array($input->cell->columnId, $allowedColumns, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
        }
        $this->snapshot($context, $snapshot);
        $record = $this->rowQuery($context, $snapshot)
            ->where('row_key', $input->cell->rowKey)
            ->first();
        if (! $record instanceof Model) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }

        $allowedTypes = array_fill_keys(
            $snapshot->kind === BudgetingPortfolioProjectionService::HEALTH_CODE
                ? self::HEALTH_SOURCE_TYPES
                : self::LIQUIDITY_SOURCE_TYPES,
            true,
        );
        $scoped = [];
        foreach ($context->scope->resources as $resource) {
            $scoped[$resource->kind.':'.$resource->id] = true;
        }

        $details = [];
        $links = [];
        $sourceRefs = $this->sourceRefs($record->getAttribute('source_refs'));
        if ($sourceRefs === []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        foreach ($sourceRefs as $sourceRef) {
            $identity = $sourceRef['type'].':'.$sourceRef['id'];
            if (! isset($allowedTypes[$sourceRef['type']]) || ! isset($scoped[$identity])) {
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
                $sourceRef['type'],
                'r'.$sourceRef['id'],
                $this->routeName($sourceRef['type']),
                ['id' => $sourceRef['id']],
                'available',
            );
        }

        return new ReportDrillDownResult($details, null, $links);
    }

    private function orderedQuery(
        Builder $query,
        ReportWindowSort $sort,
    ): Builder {
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';

        return $query
            ->orderByRaw($sort->field.' IS NULL ASC')
            ->orderBy($sort->field, $direction)
            ->orderBy('row_key', $direction);
    }

    private function rowQuery(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        $model = $snapshot->kind === BudgetingPortfolioProjectionService::HEALTH_CODE
            ? ProjectPortfolioHealthProjection::query()
            : PortfolioLiquidityProjection::query();

        return $model
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function snapshot(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): BudgetingPortfolioSnapshot {
        $record = BudgetingPortfolioSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('report_code', $snapshot->kind)
            ->whereKey($snapshot->id)
            ->first();
        if (! $record instanceof BudgetingPortfolioSnapshot
            || ! hash_equals((string) $record->source_hash, $snapshot->materializedSourceHash->value)
            || ! hash_equals((string) $record->definition_hash, $snapshot->definitionHash->value)
            || ! hash_equals((string) $record->formula_version, $snapshot->formulaVersion)
            || ! hash_equals((string) $record->query_hash, (string) ($snapshot->watermarks['query_hash'] ?? ''))) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function assertIdentity(ReportExecutionContext $context, ReportSnapshotRef $snapshot): void
    {
        if ($context->scope->canonicalIdentity() !== $snapshot->scope->canonicalIdentity()
            || ! in_array($snapshot->kind, [
                BudgetingPortfolioProjectionService::HEALTH_CODE,
                BudgetingPortfolioProjectionService::LIQUIDITY_CODE,
            ], true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    private function assertSort(ReportSnapshotRef $snapshot, ReportWindowSort $sort): void
    {
        $allowed = $snapshot->kind === BudgetingPortfolioProjectionService::HEALTH_CODE
            ? self::HEALTH_SORTS
            : self::LIQUIDITY_SORTS;
        if (! in_array($sort->field, $allowed, true)) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SORT_UNSUPPORTED,
                ['fields' => ['sort']],
            );
        }
    }

    private function row(ReportSnapshotRef $snapshot, Model $record): array
    {
        if ($snapshot->kind === BudgetingPortfolioProjectionService::HEALTH_CODE) {
            return [
                'row_key' => (string) $record->getAttribute('row_key'),
                'risk_rank' => (int) $record->getAttribute('risk_rank'),
                'project_name' => (string) $record->getAttribute('project_name'),
                'project' => (string) $record->getAttribute('project_name'),
                'currency' => (string) $record->getAttribute('currency'),
                'revenue' => (string) $record->getAttribute('revenue'),
                'cost' => (string) $record->getAttribute('cost'),
                'margin' => (string) $record->getAttribute('margin'),
                'margin_percent' => $record->getAttribute('margin_percent'),
                'wip' => (string) $record->getAttribute('wip'),
                'ftc' => (string) $record->getAttribute('ftc'),
                'eac' => (string) $record->getAttribute('eac'),
                'ctc' => (string) $record->getAttribute('ctc'),
                'risk' => (string) $record->getAttribute('risk_level'),
                'source_refs' => $record->getAttribute('source_refs') ?? [],
            ];
        }

        return [
            'row_key' => (string) $record->getAttribute('row_key'),
            'forecast_date' => $record->getAttribute('forecast_date')?->format('Y-m-d')
                ?? (string) $record->getAttribute('forecast_date'),
            'date' => $record->getAttribute('forecast_date')?->format('Y-m-d')
                ?? (string) $record->getAttribute('forecast_date'),
            'project_name' => (string) $record->getAttribute('project_name'),
            'project' => (string) $record->getAttribute('project_name'),
            'scenario' => (string) $record->getAttribute('scenario'),
            'currency' => (string) $record->getAttribute('currency'),
            'opening' => (string) $record->getAttribute('opening'),
            'inflow' => (string) $record->getAttribute('inflow'),
            'outflow' => (string) $record->getAttribute('outflow'),
            'closing' => (string) $record->getAttribute('closing'),
            'gap' => (string) $record->getAttribute('gap'),
            'quality' => (string) $record->getAttribute('quality_status'),
            'quality_gaps' => $record->getAttribute('quality_gaps') ?? [],
            'warnings' => $record->getAttribute('warnings') ?? [],
            'reconciliation_status' => (string) $record->getAttribute('reconciliation_status'),
            'source_refs' => $record->getAttribute('source_refs') ?? [],
        ];
    }

    private function assertCursor(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): void {
        if (! hash_equals($snapshot->sourceHash->value, $cursor->sourceHash->value)
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_CURSOR_INVALID,
                ['fields' => ['cursor']],
            );
        }
    }

    private function applyAfter(
        Builder $query,
        string $column,
        ReportSortDirection $direction,
        string|int|float|bool|null $value,
        string $rowKey,
    ): void {
        $operator = $direction === ReportSortDirection::ASC ? '>' : '<';
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

    private function sourceRefs(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $refs = [];
        foreach ($value as $ref) {
            if (! is_array($ref)
                || ! is_string($ref['type'] ?? null)
                || (! is_int($ref['id'] ?? null) && ! is_string($ref['id'] ?? null))
                || trim((string) $ref['id']) === '') {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
            }
            $refs[] = ['type' => $ref['type'], 'id' => (string) $ref['id']];
        }

        return $refs;
    }

    private function routeName(string $type): string
    {
        return match ($type) {
            'project' => 'admin.projects.show',
            'budget_line', 'budget_plan' => 'admin.budgeting.lines.show',
            'approved_act' => 'admin.contracts.acts.show',
            'payment_transaction', 'payment_document', 'payment_schedule' => 'admin.payments.show',
            'budget_reservation' => 'admin.budgeting.reservations.show',
            'earned_value', 'actual_cost' => 'admin.budgeting.wip.show',
            'opening_balance' => 'admin.budgeting.cash-gap.show',
            default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN),
        };
    }
}
