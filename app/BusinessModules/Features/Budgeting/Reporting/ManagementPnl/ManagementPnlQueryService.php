<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

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
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlRecord;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlSnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ManagementPnlQueryService implements ReportRowQuery, ReportDrillDownProvider
{
    public function __construct(private FinanceSourceAccessPolicy $sourceAccess)
    {
    }

    private const SORTS = [
        'period' => 'period',
        'organization_name' => 'organization_id',
        'project_name' => 'project_id',
        'article_name' => 'budget_article_id',
        'currency' => 'currency',
        'revenue' => 'revenue_minor',
        'direct_cost' => 'direct_cost_minor',
        'gross_margin' => 'gross_margin_minor',
        'operating_expense' => 'operating_expense_minor',
        'operating_result' => 'operating_result_minor',
    ];

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);

        return new ReportResult(
            new ReportResultMetadata($snapshot, (int) $record->row_count, $snapshot->generatedAt, $snapshot->staleAt),
            (array) $record->totals,
            $this->freshness($snapshot),
            $this->quality($record),
            new ReportProvenance(
                'most',
                $this->sourceRefs($record),
                $snapshot->sourceHash,
                'confirmation_only',
            ),
            [
                ['id' => 'period', 'type' => 'date'],
                ['id' => 'scenario', 'type' => 'string'],
                ['id' => 'organization_id', 'type' => 'integer'],
                ['id' => 'project_id', 'type' => 'integer'],
                ['id' => 'responsibility_center_id', 'type' => 'integer'],
                ['id' => 'budget_article_id', 'type' => 'integer'],
                ['id' => 'currency', 'type' => 'currency'],
                ['id' => 'revenue', 'type' => 'money_minor'],
                ['id' => 'direct_cost', 'type' => 'money_minor'],
                ['id' => 'gross_margin', 'type' => 'money_minor'],
                ['id' => 'operating_expense', 'type' => 'money_minor'],
                ['id' => 'operating_result', 'type' => 'money_minor'],
                ['id' => 'policy_version', 'type' => 'string'],
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
            $this->assertCursor($cursor, $snapshot, $sort);
            $this->applyAfter(
                $query,
                $column,
                $direction,
                $cursor->keyset->lastSortValue,
                $cursor->keyset->lastStableRowKey,
            );
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
        $this->snapshot($context, $snapshot);
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
        $sourceRefs = [];
        foreach ((array) $record->source_refs as $allocation) {
            foreach ((array) ($allocation['sources'] ?? []) as $ref) {
                $sourceRefs[] = $ref;
            }
        }
        $rows = [];
        $links = [];
        foreach ($this->sourceAccess->visibleRefs(
            $context,
            $sourceRefs,
            ['budget_line', 'budget_amount', 'approved_act', 'completed_work', 'payment_transaction',
                'payment_document', 'completed_transaction', 'approved_time_entry', 'time_entry', 'payroll_row'],
        ) as $ref) {
            $type = $ref['type'];
            $id = (string) $ref['id'];
            $rows[] = [
                'row_key' => $type.':'.$id,
                'source_type' => $type,
                'source_id' => $id,
                'policy_version' => (string) $record->policy_version,
            ];
            $links[] = new ReportResourceLink($type, 'r'.$id, $this->routeName($type), ['id' => (int) $id], 'available');
        }

        return new ReportDrillDownResult($rows, null, $links);
    }

    private function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ManagementPnlSnapshot
    {
        if ($snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw new DomainException('report_snapshot_scope_mismatch');
        }
        $record = ManagementPnlSnapshot::query()->where('organization_id', $context->scope->organizationId)->whereKey($snapshot->id)->firstOrFail();
        if (!hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)) {
            throw new DomainException('report_snapshot_source_hash_mismatch');
        }

        return $record;
    }

    private function rows(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        return ManagementPnlRecord::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function serialize(ManagementPnlRecord $row): array
    {
        return [
            'row_key' => (string) $row->row_key,
            'period' => $row->period->format('Y-m-d'),
            'scenario' => (string) $row->scenario,
            'organization_id' => (int) $row->organization_id,
            'project_id' => $row->project_id,
            'responsibility_center_id' => $row->responsibility_center_id,
            'budget_article_id' => $row->budget_article_id,
            'currency' => (string) $row->currency,
            'revenue' => (int) $row->revenue_minor,
            'direct_cost' => (int) $row->direct_cost_minor,
            'gross_margin' => (int) $row->gross_margin_minor,
            'gross_margin_percent' => $row->gross_margin_percent,
            'operating_expense' => (int) $row->operating_expense_minor,
            'operating_result' => (int) $row->operating_result_minor,
            'policy_version' => (string) $row->policy_version,
        ];
    }

    private function quality(ManagementPnlSnapshot $snapshot): ReportQuality
    {
        $n = (int) $snapshot->coverage_numerator;
        $d = (int) $snapshot->coverage_denominator;

        return new ReportQuality(
            ReportQualityStatus::COMPLETE,
            new ReportCoverage((string) $n, (string) $d, number_format($n / max(1, $d), 8, '.', '')),
            $n === $d ? [] : [new ReportWarning('SOURCE_COVERAGE_INCOMPLETE', ReportWarningSeverity::WARNING, null, max(0, $d - $n))],
            max(0, $d - $n),
            $n === $d ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
            [],
            [],
        );
    }

    private function sourceRefs(ManagementPnlSnapshot $snapshot): array
    {
        $refs = [];
        foreach ((array) $snapshot->component_snapshots as $component) {
            if (!is_array($component)
                || !is_string($component['component_code'] ?? null)
                || !is_string($component['snapshot_id'] ?? null)
                || !is_string($component['source_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $component['source_hash']) !== 1) {
                throw new DomainException('management_pnl_component_provenance_invalid');
            }
            $source = preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($component['component_code'])) ?? '';
            $schema = 'v_'.substr(
                preg_replace('/[^a-z0-9_]/', '_', mb_strtolower((string) ($component['source_schema_version'] ?? 'v1'))) ?? '',
                0,
                61,
            );
            $refs[] = new ReportSourceRef(
                $source,
                $source,
                's'.substr(hash('sha256', $component['snapshot_id']), 0, 32),
                $schema,
                'w'.substr(hash('sha256', $component['snapshot_id']), 0, 32),
                0,
                new Sha256Hash($component['source_hash']),
            );
        }
        if ($refs === []) {
            throw new DomainException('management_pnl_component_provenance_missing');
        }

        return $refs;
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable()
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
            'budget_line', 'budget_amount' => 'admin.budgeting.lines.show',
            'approved_act', 'completed_work' => 'admin.contracts.acts.show',
            'payment_transaction', 'payment_document', 'completed_transaction' => 'admin.payments.show',
            'approved_time_entry', 'time_entry' => 'admin.time_tracking.entries.show',
            'payroll_row' => 'admin.workforce.payroll.show',
            default => throw new DomainException('report_drill_down_source_invalid'),
        };
    }
}
