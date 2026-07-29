<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ValidatedPortfolioDrillDownCell;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityProjection;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\ProjectPortfolioHealthProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class BudgetingPortfolioQueryService implements ReportRowQuery, ReportDrillDownProvider
{
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
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_CURSOR_INVALID,
                ['fields' => ['cursor']],
            );
        }

        $snapshotRecord = $this->snapshot($context, $snapshot);
        $query = $this->orderedQuery($context, $snapshot, $sort)->limit($limit + 1);
        $records = $query->get();
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)->map(
            fn (Model $record): array => $this->row($snapshot, $record),
        )->values()->all();

        return new ReportPage(
            rows: $rows,
            totals: $snapshotRecord->totals,
            freshness: ReportFreshnessStatus::from((string) $snapshotRecord->freshness_status),
            quality: $this->quality($snapshotRecord),
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
        $queryHash = $snapshot->watermarks['query_hash'] ?? null;
        if (!is_string($queryHash) || preg_match('/^[a-f0-9]{64}$/D', $queryHash) !== 1) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        foreach ($this->orderedQuery($context, $snapshot, $sort)->cursor() as $record) {
            yield [
                'row_key' => (string) $record->getAttribute('row_key'),
                'values' => $this->row($snapshot, $record),
                'snapshot_id' => $snapshot->id,
                'query_hash' => $queryHash,
                'source_hash' => $snapshot->sourceHash->value,
            ];
        }
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $this->assertIdentity($context, $snapshot);

        throw ReportContractException::fromCode(
            ReportErrorCode::REPORT_CURSOR_INVALID,
            ['fields' => ['token']],
        );
    }

    public function drillDownValidated(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ValidatedPortfolioDrillDownCell $cell,
    ): ReportDrillDownResult {
        $this->assertIdentity($context, $snapshot);
        $record = $this->rowQuery($context, $snapshot)
            ->where('row_key', $cell->rowKey)
            ->first();
        if (!$record instanceof Model) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }

        $row = $this->row($snapshot, $record);
        $sourceRefs = is_array($row['source_refs'] ?? null) ? $row['source_refs'] : [];
        $details = [];
        foreach ($sourceRefs as $index => $sourceRef) {
            $details[] = [
                'row_key' => $cell->rowKey . ':source:' . $index,
                'column_id' => $cell->columnId,
                'source_ref' => $sourceRef,
            ];
        }

        return new ReportDrillDownResult($details, null, []);
    }

    private function orderedQuery(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): Builder {
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';

        return $this->rowQuery($context, $snapshot)
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
        if (!$record instanceof BudgetingPortfolioSnapshot
            || !hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    private function assertIdentity(ReportExecutionContext $context, ReportSnapshotRef $snapshot): void
    {
        if ($context->scope->canonicalIdentity() !== $snapshot->scope->canonicalIdentity()
            || !in_array($snapshot->kind, [
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
        if (!in_array($sort->field, $allowed, true)) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SORT_UNSUPPORTED,
                ['fields' => ['sort']],
            );
        }
    }

    private function row(ReportSnapshotRef $snapshot, Model $record): array
    {
        $attributes = $record->attributesToArray();
        unset($attributes['id'], $attributes['organization_id'], $attributes['snapshot_id']);
        $attributes['row_key'] = (string) $record->getAttribute('row_key');

        if ($snapshot->kind === BudgetingPortfolioProjectionService::HEALTH_CODE) {
            $attributes['project'] = $attributes['project_name'];
            $attributes['risk'] = $attributes['risk_level'];
        } else {
            $attributes['date'] = $attributes['forecast_date'];
            $attributes['project'] = $attributes['project_name'];
            $attributes['quality'] = $attributes['quality_status'];
        }

        return $attributes;
    }

    private function quality(BudgetingPortfolioSnapshot $snapshot): ReportQuality
    {
        $count = (int) $snapshot->row_count;

        return new ReportQuality(
            ReportQualityStatus::from((string) $snapshot->quality_status),
            new ReportCoverage((string) $count, (string) $count, $count === 0 ? null : '1.00000000'),
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
    }
}
