<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

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
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlRecord;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlSnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ManagementPnlQueryService implements ReportRowQuery, ReportDrillDownProvider
{
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
                [new ReportSourceRef(
                    'management_pnl_components',
                    'management_pnl',
                    's'.$snapshot->id,
                    'management_pnl_v1',
                    'w'.$snapshot->generatedAt->format('YmdHis'),
                    (int) $record->row_count,
                    new Sha256Hash((string) $record->source_hash),
                )],
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

    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
    ): ReportDrillDownResult {
        $this->snapshot($context, $snapshot);
        $payload = $this->tokenPayload($request->token);
        if (($payload['organization_id'] ?? null) !== $context->scope->organizationId
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || !is_string($payload['row_key'] ?? null)) {
            throw new DomainException('report_drill_down_token_invalid');
        }
        $record = $this->rows($context, $snapshot)->where('row_key', $payload['row_key'])->firstOrFail();
        $scoped = [];
        foreach ($context->scope->resources as $resource) {
            $scoped[$resource->kind.':'.$resource->id] = true;
        }
        $rows = [];
        $links = [];
        foreach ((array) $record->source_refs as $allocation) {
            foreach ((array) ($allocation['sources'] ?? []) as $ref) {
                if (!is_array($ref) || !is_string($ref['type'] ?? null)) {
                    continue;
                }
                $type = $ref['type'];
                $id = (string) ($ref['id'] ?? '');
                if (!isset($scoped[$type.':'.$id]) || !ctype_digit($id)) {
                    continue;
                }
                $rows[] = [
                    'row_key' => $type.':'.$id,
                    'source_type' => $type,
                    'source_id' => $id,
                    'policy_version' => (string) $record->policy_version,
                ];
                $links[] = new ReportResourceLink($type, 'r'.$id, $this->routeName($type), ['id' => (int) $id], 'available');
            }
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
            [],
            max(0, $d - $n),
            $n === $d ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH,
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
            'budget_line', 'budget_amount' => 'admin.budgeting.lines.show',
            'approved_act', 'completed_work' => 'admin.contracts.acts.show',
            'payment_transaction', 'payment_document', 'completed_transaction' => 'admin.payments.show',
            'approved_time_entry', 'time_entry' => 'admin.time_tracking.entries.show',
            'payroll_row' => 'admin.workforce.payroll.show',
            default => throw new DomainException('report_drill_down_source_invalid'),
        };
    }
}
