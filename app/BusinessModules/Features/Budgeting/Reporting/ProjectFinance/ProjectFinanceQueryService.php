<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

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
use App\BusinessModules\Core\Reporting\Support\OwnerSnapshotIdentityGuard;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ProjectFinanceQueryService implements ReportDrillDownProvider, ReportRowQuery
{
    public function __construct(
        private FinanceSourceAccessPolicy $sourceAccess,
        private OwnerSnapshotIdentityGuard $identityGuard,
        private ProjectFinanceOutputRedactor $outputRedactor,
    ) {}

    private const SORTS = [
        'project_margin' => [
            'project_name' => 'project_name',
            'article_name' => 'article_name',
            'currency' => 'currency',
            'plan_revenue' => 'plan_revenue_minor',
            'actual_revenue' => 'actual_revenue_minor',
            'forecast_revenue' => 'forecast_revenue_minor',
            'plan_cost' => 'plan_cost_minor',
            'actual_cost' => 'actual_cost_minor',
            'forecast_cost' => 'forecast_cost_minor',
            'margin' => 'margin_minor',
            'margin_percent' => 'margin_percent',
        ],
        'budget_plan_fact' => [
            'period' => 'period',
            'project_name' => 'project_name',
            'article_name' => 'article_name',
            'currency' => 'currency',
            'plan' => 'plan_minor',
            'actual' => 'actual_minor',
            'committed' => 'committed_minor',
            'available' => 'available_minor',
            'variance' => 'variance_minor',
            'risk' => 'risk',
        ],
        'wip_completion_forecast' => [
            'project_name' => 'project_name',
            'wbs_code' => 'wbs_code',
            'currency' => 'currency',
            'wip' => 'wip_minor',
            'ctc' => 'ctc_minor',
            'eac' => 'eac_minor',
            'forecast_variance' => 'forecast_variance_minor',
            'risk' => 'risk',
        ],
    ];

    private const DRILL_TYPES = [
        'project_margin' => ['budget_line', 'approved_act', 'completed_work', 'payment_transaction', 'warehouse_movement', 'approved_time_entry'],
        'budget_plan_fact' => ['budget_amount', 'reservation', 'payment_document', 'completed_transaction'],
        'wip_completion_forecast' => ['earned_value', 'actual_cost', 'manual_adjustment', 'audit_event'],
    ];

    private const ROW_SCHEMAS = [
        'project_margin' => [
            ['id' => 'project_name', 'type' => 'string'],
            ['id' => 'article_name', 'type' => 'string'],
            ['id' => 'currency', 'type' => 'currency'],
            ['id' => 'plan_revenue', 'type' => 'money_minor'],
            ['id' => 'actual_revenue', 'type' => 'money_minor'],
            ['id' => 'forecast_revenue', 'type' => 'money_minor'],
            ['id' => 'plan_cost', 'type' => 'money_minor'],
            ['id' => 'actual_cost', 'type' => 'money_minor'],
            ['id' => 'forecast_cost', 'type' => 'money_minor'],
            ['id' => 'margin', 'type' => 'money_minor'],
            ['id' => 'margin_percent', 'type' => 'decimal'],
        ],
        'budget_plan_fact' => [
            ['id' => 'period', 'type' => 'date'],
            ['id' => 'project_name', 'type' => 'string'],
            ['id' => 'article_name', 'type' => 'string'],
            ['id' => 'currency', 'type' => 'currency'],
            ['id' => 'plan', 'type' => 'money_minor'],
            ['id' => 'actual', 'type' => 'money_minor'],
            ['id' => 'committed', 'type' => 'money_minor'],
            ['id' => 'available', 'type' => 'money_minor'],
            ['id' => 'variance', 'type' => 'money_minor'],
            ['id' => 'risk', 'type' => 'string'],
        ],
        'wip_completion_forecast' => [
            ['id' => 'project_name', 'type' => 'string'],
            ['id' => 'wbs_code', 'type' => 'string'],
            ['id' => 'currency', 'type' => 'currency'],
            ['id' => 'wip', 'type' => 'money_minor'],
            ['id' => 'bac', 'type' => 'money_minor'],
            ['id' => 'pv', 'type' => 'money_minor'],
            ['id' => 'ev', 'type' => 'money_minor'],
            ['id' => 'ac', 'type' => 'money_minor'],
            ['id' => 'spi', 'type' => 'decimal'],
            ['id' => 'cpi', 'type' => 'decimal'],
            ['id' => 'ctc', 'type' => 'money_minor'],
            ['id' => 'eac', 'type' => 'money_minor'],
            ['id' => 'forecast_variance', 'type' => 'money_minor'],
        ],
    ];

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $quality = $this->quality($record);

        return new ReportResult(
            metadata: new ReportResultMetadata(
                snapshot: $snapshot,
                rowCount: (int) $record->row_count,
                generatedAt: $snapshot->generatedAt,
                staleAt: $snapshot->staleAt,
            ),
            totals: $this->visibleTotals($record, $context),
            freshness: $this->freshness($snapshot),
            quality: $quality,
            provenance: new ReportProvenance(
                sourceOfTruth: 'most',
                sourceRefs: [$this->sourceRef($record)],
                sourceHash: $snapshot->sourceHash,
                externalConfirmationRole: 'confirmation_only',
            ),
            rowSchema: self::ROW_SCHEMAS[(string) $record->report_code],
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
        $column = $this->sortColumn((string) $record->report_code, $sort);
        $query = ProjectFinanceRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('report_code', $record->report_code);

        if ($cursor !== null) {
            $position = $this->cursorPosition($cursor, $snapshot, $sort);
            $this->applyAfter($query, $column, $sort->direction, $position['value'], $position['row_key']);
        }

        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $models = $query
            ->orderByRaw($column.' IS NULL ASC')
            ->orderBy($column, $direction)
            ->orderBy('row_key', $direction)
            ->limit($limit + 1)
            ->get();
        $hasMore = $models->count() > $limit;
        $models = $models->take($limit);
        $rows = $models
            ->map(fn (ProjectFinanceRow $row): array => $this->serialize($row, $context))
            ->values()
            ->all();

        return new ReportPage(
            rows: $rows,
            totals: $this->visibleTotals($record, $context),
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
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new DomainException('report_chunk_size_invalid');
        }
        $record = $this->snapshot($context, $snapshot);
        $column = $this->sortColumn((string) $record->report_code, $sort);
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';

        $query = ProjectFinanceRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('report_code', $record->report_code)
            ->orderByRaw($column.' IS NULL ASC')
            ->orderBy($column, $direction)
            ->orderBy('row_key', $direction);

        foreach ($query->cursor() as $row) {
            yield $this->serialize($row, $context);
        }
    }

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        $record = $this->snapshot($context, $snapshot);
        $row = ProjectFinanceRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $input->cell->rowKey)
            ->firstOrFail();
        $allowed = array_fill_keys(self::DRILL_TYPES[(string) $record->report_code], true);
        $rows = [];
        $links = [];
        foreach ($this->sourceAccess->visibleRefs($context, $row->source_refs, array_keys($allowed)) as $ref) {
            $key = $ref['type'].':'.$ref['id'];
            $rows[] = [
                'row_key' => $key,
                'source_type' => $ref['type'],
                'source_id' => $ref['id'],
                'snapshot_row_key' => $row->row_key,
            ];
            $links[] = new ReportResourceLink(
                resourceType: $ref['type'],
                resourceId: 'r'.$ref['id'],
                routeName: $this->routeName($ref['type']),
                params: ['id' => (int) $ref['id']],
                availability: 'available',
            );
        }

        return new ReportDrillDownResult($rows, null, $links);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ProjectFinanceSnapshot
    {
        $record = ProjectFinanceSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->firstOrFail();
        $this->identityGuard->assert($record, $context, $snapshot, [
            'source_schema_version' => 'source_schema_version',
            'budget_version_id' => 'budget_version_id',
            'forecast_version_id' => 'forecast_version_id',
        ]);

        return $record;
    }

    private function serialize(ProjectFinanceRow $row, ReportExecutionContext $context): array
    {
        $base = [
            'row_key' => (string) $row->row_key,
            'project_id' => $row->project_id,
            'project_name' => $row->project_name,
            'responsibility_center_id' => $row->responsibility_center_id,
            'responsibility_center_name' => $row->responsibility_center_name,
            'budget_article_id' => $row->budget_article_id,
            'article_name' => $row->article_name,
            'currency' => $row->currency,
            'currency_source' => $row->currency_source,
            'tax_basis' => $row->tax_basis,
            'period' => $row->period?->format('Y-m-d'),
            'quality_status' => $row->quality_status,
        ];

        return match ((string) $row->report_code) {
            'project_margin' => [
                ...$base,
                'plan_revenue' => $row->plan_revenue_minor,
                'actual_revenue' => $row->actual_revenue_minor,
                'forecast_revenue' => $row->forecast_revenue_minor,
                'plan_cost' => $row->plan_cost_minor,
                'actual_cost' => $row->actual_cost_minor,
                'forecast_cost' => $row->forecast_cost_minor,
                'margin' => $row->margin_minor,
                'margin_percent' => $row->margin_percent,
            ],
            'budget_plan_fact' => [
                ...$base,
                'scenario' => $row->scenario,
                'direction' => $row->direction,
                'plan' => $row->plan_minor,
                'actual' => $row->actual_minor,
                'committed' => $row->committed_minor,
                'available' => $row->available_minor,
                'variance' => $row->variance_minor,
                'risk' => $row->risk,
            ],
            'wip_completion_forecast' => [
                ...$base,
                'wbs_id' => $row->wbs_id,
                'wbs_code' => $row->wbs_code,
                'wip' => $row->wip_minor,
                'spi' => $row->spi,
                ...($context->visibility->canViewSensitive ? [
                    'bac' => $row->bac_minor,
                    'pv' => $row->pv_minor,
                    'ev' => $row->ev_minor,
                    'ac' => $row->ac_minor,
                    'ctc' => $row->ctc_minor,
                    'eac' => $row->eac_minor,
                    'forecast_variance' => $row->forecast_variance_minor,
                    'cpi' => $row->cpi,
                ] : []),
            ],
            default => throw new DomainException('report_code_invalid'),
        };
    }

    private function visibleTotals(ProjectFinanceSnapshot $snapshot, ReportExecutionContext $context): array
    {
        return $this->outputRedactor->totals(
            (string) $snapshot->report_code,
            is_array($snapshot->totals) ? $snapshot->totals : [],
            $context->visibility->canViewSensitive,
        );
    }

    private function quality(ProjectFinanceSnapshot $snapshot): ReportQuality
    {
        $denominator = (int) $snapshot->coverage_denominator;
        $numerator = (int) $snapshot->coverage_numerator;

        return new ReportQuality(
            status: $snapshot->quality_status === 'complete' ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            coverage: new ReportCoverage(
                (string) $numerator,
                (string) $denominator,
                $denominator === 0 ? null : number_format($numerator / $denominator, 8, '.', ''),
            ),
            warnings: $numerator === $denominator ? [] : [
                new ReportWarning('SOURCE_COVERAGE_INCOMPLETE', ReportWarningSeverity::WARNING, null, max(0, $denominator - $numerator)),
            ],
            unmatchedCount: max(0, $denominator - $numerator),
            reconciliation: $numerator === $denominator
                ? ReportReconciliationStatus::MATCHED
                : ReportReconciliationStatus::MISMATCH,
            unknownMetrics: [],
            excludedSources: [],
        );
    }

    private function sourceRef(ProjectFinanceSnapshot $snapshot): ReportSourceRef
    {
        return new ReportSourceRef(
            source: 'budgeting_epm_data_mart',
            snapshotKind: 'budgeting_epm_data_mart',
            snapshotId: 's'.substr((string) $snapshot->source_snapshot_hash, 0, 32),
            schemaVersion: 'budgeting_epm_data_mart_v1',
            watermark: 'w'.$snapshot->generated_at->format('YmdHis'),
            rowCount: (int) $snapshot->row_count,
            hash: new Sha256Hash((string) $snapshot->source_snapshot_hash),
        );
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new \DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function sortColumn(string $code, ReportWindowSort $sort): string
    {
        $column = self::SORTS[$code][$sort->field] ?? null;
        if (! is_string($column)) {
            throw new DomainException('report_sort_invalid');
        }

        return $column;
    }

    private function applyAfter(
        Builder $query,
        string $column,
        ReportSortDirection $direction,
        mixed $value,
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

    private function cursorPosition(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): array {
        if ($cursor->sourceHash->value !== $snapshot->sourceHash->value
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction) {
            throw new DomainException('report_cursor_identity_mismatch');
        }

        return [
            'value' => $cursor->keyset->lastSortValue,
            'row_key' => $cursor->keyset->lastStableRowKey,
        ];
    }

    private function routeName(string $type): string
    {
        return match ($type) {
            'budget_line', 'budget_amount' => 'admin.budgeting.lines.show',
            'approved_act', 'completed_work' => 'admin.contracts.acts.show',
            'payment_transaction', 'payment_document', 'completed_transaction' => 'admin.payments.show',
            'warehouse_movement' => 'admin.warehouse.movements.show',
            'approved_time_entry' => 'admin.time_tracking.entries.show',
            'reservation' => 'admin.budgeting.reservations.show',
            'earned_value', 'actual_cost' => 'admin.budgeting.wip.show',
            'manual_adjustment' => 'admin.budgeting.wip.adjustments.show',
            'audit_event' => 'admin.budgeting.wip.audit.show',
            default => throw new DomainException('report_drill_down_source_invalid'),
        };
    }
}
