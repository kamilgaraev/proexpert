<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastManualAdjustment;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\WipForecastSourceAggregate;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\WipForecastAssumption;
use App\BusinessModules\Features\Budgeting\Models\WipForecastLine;
use App\BusinessModules\Features\Budgeting\Models\WipForecastVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\EstimateItem;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use function trans_message;

final class WipForecastReportService
{
    public function __construct(
        private readonly WipForecastCalculator $calculator,
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function report(array $input, ?User $user = null): array
    {
        $context = $this->context($input);
        /** @var WipForecastReportFilters $filters */
        $filters = $context['filters'];

        return $this->calculator->calculate(
            filters: $filters,
            aggregates: $context['aggregates'],
            dimensions: $context['dimensions'],
            scenario: $this->scenarioPayload($context['scenario']),
            budgetVersion: $this->budgetVersionPayload($context['budget_version']),
            forecastVersion: $this->forecastVersionPayload($context['version']),
            adjustments: $context['adjustments'],
            assumptions: $context['assumptions'],
            sourceCoverage: $context['source_coverage'],
            freshness: $context['freshness'],
            meta: [
                'generated_at' => now()->toIso8601String(),
                'as_of_date' => $filters->asOfDate,
                'permissions' => $this->permissions($user, $filters->organizationId),
                'version_source' => $context['version'] instanceof WipForecastVersion ? 'stored_version' : 'live_sources',
                'source_snapshot_hash' => $context['source_snapshot_hash'],
                'comparison' => $context['comparison'],
            ],
        );
    }

    public function drillDown(array $input, ?User $user = null): array
    {
        try {
            $key = WipForecastDrillDownKey::decode((string) $input['drill_down_key']);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === WipForecastDrillDownKey::INVALID_KEY_MESSAGE) {
                throw new InvalidArgumentException(trans_message(WipForecastDrillDownKey::INVALID_KEY_MESSAGE));
            }

            throw $exception;
        }

        $context = $this->context($input);
        /** @var WipForecastReportFilters $filters */
        $filters = $context['filters'];
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(500, max(1, (int) ($input['per_page'] ?? 100)));

        if ($context['version'] instanceof WipForecastVersion) {
            $lines = $this->filterLinesByKey($this->storedLines($context['version'], $filters), $key);
            $total = $lines->count();
            $items = $lines
                ->slice(($page - 1) * $perPage, $perPage)
                ->values()
                ->map(fn (WipForecastLine $line): array => $this->storedDrillDownItem($line))
                ->all();
        } else {
            $query = $this->sourceRowsQuery($filters, $key);
            $total = (int) DB::query()->fromSub($query, 'wip_forecast_drill_total')->count();
            $items = DB::query()
                ->fromSub($this->sourceRowsQuery($filters, $key), 'wip_forecast_drill')
                ->orderByDesc('recognition_date')
                ->orderBy('source_type')
                ->orderBy('source_id')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(fn (object $row): array => $this->liveDrillDownItem($row))
                ->all();
        }

        return [
            'filters' => $filters->toArray(),
            'period' => $filters->period(),
            'group' => [
                'group_by' => $key->groupBy,
                'dimensions' => $key->dimensions,
            ],
            'summary' => [
                'items_count' => $total,
                'currency' => $filters->currency,
                'earned_value' => round(array_sum(array_map(static fn (array $item): float => (float) ($item['metrics']['ev'] ?? 0), $items)), 2),
                'actual_cost' => round(array_sum(array_map(static fn (array $item): float => (float) ($item['metrics']['ac'] ?? 0), $items)), 2),
                'wip_total' => round(array_sum(array_map(static fn (array $item): float => (float) ($item['metrics']['wip_total'] ?? 0), $items)), 2),
            ],
            'items' => $items,
            'warnings' => $total === 0 ? [trans_message('budgeting.wip_forecast.warnings.drill_down_empty')] : [],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'forecast_version' => $this->forecastVersionPayload($context['version']),
                'permissions' => $this->permissions($user, $filters->organizationId),
            ],
        ];
    }

    /**
     * @return array{filters: WipForecastReportFilters, budget_version: BudgetVersion|null, scenario: BudgetScenario|null, version: WipForecastVersion|null}
     */
    public function resolveContext(array $input): array
    {
        $organizationId = (int) ($input['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $asOfDate = CarbonImmutable::parse((string) ($input['as_of_date'] ?? $input['period_end'] ?? now()->toDateString()))->toDateString();
        $periodStart = CarbonImmutable::parse((string) ($input['period_start'] ?? CarbonImmutable::parse($asOfDate)->startOfMonth()->toDateString()))->toDateString();
        $periodEnd = CarbonImmutable::parse((string) ($input['period_end'] ?? CarbonImmutable::parse($asOfDate)->endOfMonth()->toDateString()))->toDateString();

        if (CarbonImmutable::parse($periodEnd)->lt(CarbonImmutable::parse($periodStart))) {
            throw new DomainException(trans_message('budgeting.wip_forecast.errors.period_invalid'));
        }

        $scenario = $this->resolveScenario($organizationId, $input['scenario_uuid'] ?? null);
        $budgetVersion = $this->resolveBudgetVersion($organizationId, $periodStart, $periodEnd, $scenario, $input['budget_version_uuid'] ?? null);
        $scenario = $budgetVersion?->scenario ?? $scenario;
        $filters = new WipForecastReportFilters(
            organizationId: $organizationId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            asOfDate: $asOfDate,
            forecastVersionId: null,
            forecastVersionUuid: $this->nullableString($input['forecast_version_uuid'] ?? null),
            budgetVersionId: $budgetVersion instanceof BudgetVersion ? (int) $budgetVersion->id : null,
            budgetVersionUuid: $budgetVersion instanceof BudgetVersion ? (string) $budgetVersion->uuid : $this->nullableString($input['budget_version_uuid'] ?? null),
            scenarioId: $scenario instanceof BudgetScenario ? (int) $scenario->id : null,
            scenarioUuid: $scenario instanceof BudgetScenario ? (string) $scenario->uuid : $this->nullableString($input['scenario_uuid'] ?? null),
            projectId: $this->nullableInt($input['project_id'] ?? null),
            stageId: $this->nullableInt($input['stage_id'] ?? null),
            contractId: $this->nullableInt($input['contract_id'] ?? null),
            estimateItemId: $this->nullableInt($input['estimate_item_id'] ?? null),
            currency: $this->nullableCurrency($input['currency'] ?? null),
            groupBy: $this->groupBy($input['group_by'] ?? null),
        );
        $version = ($input['use_live_sources'] ?? false) === true ? null : $this->forecastVersion($filters);

        return [
            'filters' => $filters,
            'budget_version' => $budgetVersion,
            'scenario' => $scenario,
            'version' => $version,
        ];
    }

    /**
     * @return array{
     *     filters: WipForecastReportFilters,
     *     budget_version: BudgetVersion|null,
     *     scenario: BudgetScenario|null,
     *     version: WipForecastVersion|null,
     *     aggregates: list<WipForecastSourceAggregate>,
     *     dimensions: WipForecastDimensions,
     *     adjustments: list<WipForecastManualAdjustment>,
     *     assumptions: list<array<string, mixed>>,
     *     source_coverage: list<array<string, mixed>>,
     *     freshness: array<string, mixed>,
     *     source_snapshot_hash: string,
     *     comparison: array<string, mixed>
     * }
     */
    private function context(array $input): array
    {
        $context = $this->resolveContext($input);
        /** @var WipForecastReportFilters $filters */
        $filters = $context['filters'];
        $version = $context['version'];

        if ($version instanceof WipForecastVersion) {
            $lines = $this->storedLines($version, $filters);
            $aggregates = $lines
                ->map(static fn (WipForecastLine $line): WipForecastSourceAggregate => WipForecastSourceAggregate::fromStoredLine($line))
                ->values()
                ->all();

            return [
                ...$context,
                'aggregates' => $aggregates,
                'dimensions' => $this->dimensionsForLines($lines),
                'adjustments' => $version->adjustments()->get()->map->toManualAdjustment()->values()->all(),
                'assumptions' => $version->assumptions()->get()->map(fn (WipForecastAssumption $assumption): array => $this->assumptionPayload($assumption))->values()->all(),
                'source_coverage' => is_array($version->source_coverage) ? $version->source_coverage : [],
                'freshness' => is_array($version->freshness) ? $version->freshness : $this->freshness([]),
                'source_snapshot_hash' => (string) ($version->source_snapshot_hash ?? ''),
                'comparison' => $this->comparison($version, $context['budget_version']),
            ];
        }

        $aggregateRows = $this->aggregateRows($filters);
        $aggregates = $aggregateRows
            ->map(static fn (object $row): WipForecastSourceAggregate => WipForecastSourceAggregate::fromDatabaseRow($row))
            ->all();
        $sourceCoverage = $this->sourceCoverage($filters);

        return [
            ...$context,
            'aggregates' => $aggregates,
            'dimensions' => $this->dimensionsForAggregates($filters, $aggregates),
            'adjustments' => [],
            'assumptions' => [[
                'assumption_type' => 'source_of_truth',
                'title' => trans_message('budgeting.wip_forecast.assumptions.management_source_of_truth'),
                'status' => 'active',
            ]],
            'source_coverage' => $sourceCoverage,
            'freshness' => $this->freshness($sourceCoverage),
            'source_snapshot_hash' => $this->sourceSnapshotHash($filters, $aggregateRows),
            'comparison' => $this->comparison(null, $context['budget_version']),
        ];
    }

    private function resolveScenario(int $organizationId, mixed $scenarioUuid): ?BudgetScenario
    {
        if (!is_string($scenarioUuid) || trim($scenarioUuid) === '') {
            return null;
        }

        $scenario = BudgetScenario::query()
            ->where('organization_id', $organizationId)
            ->where('uuid', trim($scenarioUuid))
            ->first();

        if (!$scenario instanceof BudgetScenario) {
            throw new DomainException(trans_message('budgeting.scenarios.not_found'));
        }

        return $scenario;
    }

    private function resolveBudgetVersion(
        int $organizationId,
        string $periodStart,
        string $periodEnd,
        ?BudgetScenario $scenario,
        mixed $versionUuid,
    ): ?BudgetVersion {
        if (is_string($versionUuid) && trim($versionUuid) !== '') {
            $version = BudgetVersion::query()
                ->with(['period', 'scenario'])
                ->where('organization_id', $organizationId)
                ->where('uuid', trim($versionUuid))
                ->whereIn('budget_kind', ['bdr', 'consolidated'])
                ->first();

            if (!$version instanceof BudgetVersion) {
                throw new DomainException(trans_message('budgeting.versions.not_found'));
            }

            return $version;
        }

        return BudgetVersion::query()
            ->with(['period', 'scenario'])
            ->where('organization_id', $organizationId)
            ->where('status', BudgetWorkflowService::STATUS_ACTIVE)
            ->whereIn('budget_kind', ['bdr', 'consolidated'])
            ->when($scenario instanceof BudgetScenario, fn (Builder $query): Builder => $query->where('scenario_id', $scenario->id))
            ->when(!($scenario instanceof BudgetScenario), function (Builder $query): void {
                $query->whereHas('scenario', fn (Builder $scenarioQuery): Builder => $scenarioQuery
                    ->where('is_default', true)
                    ->where('is_active', true));
            })
            ->whereHas('period', function (Builder $query) use ($periodStart, $periodEnd): void {
                $query
                    ->whereDate('starts_at', '<=', $periodEnd)
                    ->whereDate('ends_at', '>=', $periodStart);
            })
            ->orderByRaw("CASE WHEN budget_kind = 'bdr' THEN 0 ELSE 1 END")
            ->orderByDesc('version_number')
            ->first();
    }

    private function forecastVersion(WipForecastReportFilters $filters): ?WipForecastVersion
    {
        $query = WipForecastVersion::query()
            ->where('organization_id', $filters->organizationId)
            ->when($filters->projectId, fn (Builder $query, int $projectId): Builder => $query->where('project_id', $projectId))
            ->when($filters->budgetVersionId, fn (Builder $query, int $versionId): Builder => $query->where('budget_version_id', $versionId))
            ->when($filters->scenarioId, fn (Builder $query, int $scenarioId): Builder => $query->where('scenario_id', $scenarioId));

        if ($filters->forecastVersionUuid !== null) {
            return $query->where('uuid', $filters->forecastVersionUuid)->first();
        }

        return $query
            ->where('status', WipForecastVersionService::STATUS_ACTIVE)
            ->whereDate('period_start', '<=', $filters->periodEnd)
            ->whereDate('period_end', '>=', $filters->periodStart)
            ->latest('activated_at')
            ->latest('id')
            ->first();
    }

    private function aggregateRows(WipForecastReportFilters $filters): Collection
    {
        return DB::query()
            ->fromSub($this->sourceRowsQuery($filters), 'wip_forecast_sources')
            ->selectRaw('period_month AS period_month')
            ->selectRaw('project_id AS project_id')
            ->selectRaw('stage_id AS stage_id')
            ->selectRaw('contract_id AS contract_id')
            ->selectRaw('estimate_item_id AS estimate_item_id')
            ->selectRaw('currency AS currency')
            ->selectRaw('SUM(bac) AS bac')
            ->selectRaw('SUM(pv) AS pv')
            ->selectRaw('CASE WHEN SUM(bac) > 0 THEN ROUND((SUM(ev) / NULLIF(SUM(bac), 0)) * 100, 2) ELSE NULL END AS percent_complete')
            ->selectRaw('SUM(ev) AS ev')
            ->selectRaw('SUM(approved_act_value) AS approved_act_value')
            ->selectRaw('SUM(actual_cost_accrual) AS actual_cost_accrual')
            ->selectRaw('SUM(cash_only_payments) AS cash_only_payments')
            ->selectRaw('SUM(bottom_up_etc) AS bottom_up_etc')
            ->selectRaw('SUM(forecast_revenue) AS forecast_revenue')
            ->selectRaw("STRING_AGG(DISTINCT NULLIF(source_type, ''), ',') AS source_types")
            ->selectRaw("STRING_AGG(DISTINCT NULLIF(problem_flags, ''), ',') AS problem_flags")
            ->selectRaw("STRING_AGG(DISTINCT NULLIF(risk_flags, ''), ',') AS risk_flags")
            ->selectRaw('COUNT(*) AS source_rows_count')
            ->selectRaw("MD5(COALESCE(STRING_AGG(source_type || ':' || COALESCE(source_id::text, '') || ':' || COALESCE(source_line_id::text, ''), ',' ORDER BY source_type, source_id, source_line_id), '')) AS source_snapshot_hash")
            ->groupBy([
                'period_month',
                'project_id',
                'stage_id',
                'contract_id',
                'estimate_item_id',
                'currency',
            ])
            ->get();
    }

    private function sourceRowsQuery(WipForecastReportFilters $filters, ?WipForecastDrillDownKey $key = null): QueryBuilder
    {
        $union = $this->budgetSourceQuery($filters);
        $union->unionAll($this->performanceActSourceQuery($filters));
        $union->unionAll($this->completedWorkSourceQuery($filters));
        $union->unionAll($this->paymentDocumentSourceQuery($filters));
        $union->unionAll($this->warehouseMovementSourceQuery($filters));
        $union->unionAll($this->timeEntrySourceQuery($filters));

        $query = DB::query()->fromSub($union, 'wip_sources');
        $this->applyNormalizedFilters($query, $filters);

        if ($key instanceof WipForecastDrillDownKey) {
            $this->applyDrillDownDimensions($query, $key);
        }

        return $query;
    }

    private function emptySourceQuery(): QueryBuilder
    {
        return DB::table('projects')
            ->whereRaw('1 = 0')
            ->selectRaw("'empty' AS source_type")
            ->selectRaw('NULL::bigint AS source_id')
            ->selectRaw('NULL::bigint AS source_line_id')
            ->selectRaw('NULL::date AS period_month')
            ->selectRaw('NULL::date AS recognition_date')
            ->selectRaw('NULL::bigint AS project_id')
            ->selectRaw('NULL::bigint AS stage_id')
            ->selectRaw('NULL::bigint AS contract_id')
            ->selectRaw('NULL::bigint AS estimate_item_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw('0::numeric AS bac')
            ->selectRaw('0::numeric AS pv')
            ->selectRaw('NULL::numeric AS percent_complete')
            ->selectRaw('0::numeric AS ev')
            ->selectRaw('0::numeric AS approved_act_value')
            ->selectRaw('0::numeric AS actual_cost_accrual')
            ->selectRaw('0::numeric AS cash_only_payments')
            ->selectRaw('0::numeric AS bottom_up_etc')
            ->selectRaw('0::numeric AS forecast_revenue')
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('NULL::text AS source_title')
            ->selectRaw("'actual' AS quality_status")
            ->selectRaw("'' AS problem_flags")
            ->selectRaw("'' AS risk_flags")
            ->selectRaw("'prohelper_management' AS source_of_truth");
    }

    private function budgetSourceQuery(WipForecastReportFilters $filters): QueryBuilder
    {
        if ($filters->budgetVersionId === null) {
            return $this->emptySourceQuery();
        }

        $currencyExpression = "UPPER(COALESCE(NULLIF(budget_amounts.currency, ''), budget_lines.currency, 'RUB'))";
        $estimateItemExpression = "CASE WHEN budget_lines.metadata->>'estimate_item_id' ~ '^[0-9]+$' THEN (budget_lines.metadata->>'estimate_item_id')::bigint ELSE NULL END";
        $stageExpression = "CASE WHEN budget_lines.metadata->>'stage_id' ~ '^[0-9]+$' THEN (budget_lines.metadata->>'stage_id')::bigint ELSE NULL END";
        $amountExpression = 'COALESCE(budget_amounts.forecast_amount, budget_amounts.plan_amount, 0)';
        $isCost = "budget_articles.flow_direction IN ('expense', 'outflow')";
        $isRevenue = "budget_articles.flow_direction IN ('income', 'inflow')";

        return DB::table('budget_amounts')
            ->join('budget_lines', 'budget_amounts.budget_line_id', '=', 'budget_lines.id')
            ->join('budget_articles', 'budget_lines.budget_article_id', '=', 'budget_articles.id')
            ->where('budget_lines.budget_version_id', $filters->budgetVersionId)
            ->whereNull('budget_lines.deleted_at')
            ->whereBetween('budget_amounts.month', [$filters->periodStartMonth(), $filters->periodEndMonth()])
            ->selectRaw("'budget_amount' AS source_type")
            ->selectRaw('budget_amounts.id AS source_id')
            ->selectRaw('budget_lines.id AS source_line_id')
            ->selectRaw("DATE_TRUNC('month', budget_amounts.month)::date AS period_month")
            ->selectRaw('budget_amounts.month::date AS recognition_date')
            ->selectRaw('budget_lines.project_id AS project_id')
            ->selectRaw("{$stageExpression} AS stage_id")
            ->selectRaw('budget_lines.contract_id AS contract_id')
            ->selectRaw("{$estimateItemExpression} AS estimate_item_id")
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw("CASE WHEN {$isCost} THEN {$amountExpression} ELSE 0 END AS bac")
            ->selectRaw("CASE WHEN {$isCost} AND budget_amounts.month <= ? THEN {$amountExpression} ELSE 0 END AS pv", [$filters->period()['as_of_month']])
            ->selectRaw('NULL::numeric AS percent_complete')
            ->selectRaw('0::numeric AS ev')
            ->selectRaw('0::numeric AS approved_act_value')
            ->selectRaw('0::numeric AS actual_cost_accrual')
            ->selectRaw('0::numeric AS cash_only_payments')
            ->selectRaw("CASE WHEN {$isCost} THEN COALESCE(budget_amounts.forecast_amount, 0) ELSE 0 END AS bottom_up_etc")
            ->selectRaw("CASE WHEN {$isRevenue} THEN {$amountExpression} ELSE 0 END AS forecast_revenue")
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('budget_lines.description AS source_title')
            ->selectRaw("'actual' AS quality_status")
            ->selectRaw("CASE WHEN budget_lines.project_id IS NULL THEN 'missing_project' ELSE '' END AS problem_flags")
            ->selectRaw("'' AS risk_flags")
            ->selectRaw("'prohelper_management_budget' AS source_of_truth");
    }

    private function performanceActSourceQuery(WipForecastReportFilters $filters): QueryBuilder
    {
        $dateExpression = 'COALESCE(contract_performance_acts.approval_date, contract_performance_acts.act_date)';
        $projectExpression = 'COALESCE(contract_performance_acts.project_id, contracts.project_id)';
        $amountExpression = 'COALESCE(performance_act_lines.amount, contract_performance_acts.amount, 0)';

        return DB::table('contract_performance_acts')
            ->join('contracts', 'contract_performance_acts.contract_id', '=', 'contracts.id')
            ->leftJoin('performance_act_lines', 'performance_act_lines.performance_act_id', '=', 'contract_performance_acts.id')
            ->where('contracts.organization_id', $filters->organizationId)
            ->whereNull('contracts.deleted_at')
            ->where('contract_performance_acts.is_approved', true)
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("'contract_performance_act' AS source_type")
            ->selectRaw('contract_performance_acts.id AS source_id')
            ->selectRaw('performance_act_lines.id AS source_line_id')
            ->selectRaw("DATE_TRUNC('month', {$dateExpression})::date AS period_month")
            ->selectRaw("{$dateExpression}::date AS recognition_date")
            ->selectRaw("{$projectExpression} AS project_id")
            ->selectRaw('NULL::bigint AS stage_id')
            ->selectRaw('contract_performance_acts.contract_id AS contract_id')
            ->selectRaw('performance_act_lines.estimate_item_id AS estimate_item_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw('0::numeric AS bac')
            ->selectRaw('0::numeric AS pv')
            ->selectRaw('NULL::numeric AS percent_complete')
            ->selectRaw("{$amountExpression} AS ev")
            ->selectRaw("{$amountExpression} AS approved_act_value")
            ->selectRaw('0::numeric AS actual_cost_accrual')
            ->selectRaw('0::numeric AS cash_only_payments')
            ->selectRaw('0::numeric AS bottom_up_etc')
            ->selectRaw("{$amountExpression} AS forecast_revenue")
            ->selectRaw('contract_performance_acts.act_document_number AS source_document_number')
            ->selectRaw('COALESCE(performance_act_lines.title, contract_performance_acts.description) AS source_title')
            ->selectRaw("CASE WHEN performance_act_lines.id IS NULL THEN 'attention' ELSE 'actual' END AS quality_status")
            ->selectRaw("CASE WHEN performance_act_lines.id IS NULL THEN 'act_without_work_volume' ELSE '' END AS problem_flags")
            ->selectRaw("'' AS risk_flags")
            ->selectRaw("'prohelper_management_act_confirmation' AS source_of_truth");
    }

    private function completedWorkSourceQuery(WipForecastReportFilters $filters): QueryBuilder
    {
        $amountExpression = 'COALESCE(completed_works.total_amount, completed_works.quantity * COALESCE(completed_works.price, 0), 0)';

        return DB::table('completed_works')
            ->where('completed_works.organization_id', $filters->organizationId)
            ->whereNull('completed_works.deleted_at')
            ->where('completed_works.status', 'confirmed')
            ->whereBetween('completed_works.completion_date', [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->whereNotExists(function (QueryBuilder $subQuery): void {
                $subQuery->select(DB::raw('1'))
                    ->from('performance_act_completed_works')
                    ->whereColumn('performance_act_completed_works.completed_work_id', 'completed_works.id');
            })
            ->whereNotExists(function (QueryBuilder $subQuery): void {
                $subQuery->select(DB::raw('1'))
                    ->from('performance_act_lines')
                    ->whereColumn('performance_act_lines.completed_work_id', 'completed_works.id');
            })
            ->selectRaw("'completed_work' AS source_type")
            ->selectRaw('completed_works.id AS source_id')
            ->selectRaw('completed_works.id AS source_line_id')
            ->selectRaw("DATE_TRUNC('month', completed_works.completion_date)::date AS period_month")
            ->selectRaw('completed_works.completion_date::date AS recognition_date')
            ->selectRaw('completed_works.project_id AS project_id')
            ->selectRaw('NULL::bigint AS stage_id')
            ->selectRaw('completed_works.contract_id AS contract_id')
            ->selectRaw('completed_works.estimate_item_id AS estimate_item_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw('0::numeric AS bac')
            ->selectRaw('0::numeric AS pv')
            ->selectRaw('NULL::numeric AS percent_complete')
            ->selectRaw("{$amountExpression} AS ev")
            ->selectRaw('0::numeric AS approved_act_value')
            ->selectRaw('0::numeric AS actual_cost_accrual')
            ->selectRaw('0::numeric AS cash_only_payments')
            ->selectRaw('0::numeric AS bottom_up_etc')
            ->selectRaw("{$amountExpression} AS forecast_revenue")
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('completed_works.notes AS source_title')
            ->selectRaw("'attention' AS quality_status")
            ->selectRaw("CASE WHEN completed_works.estimate_item_id IS NULL THEN 'missing_estimate_item' ELSE '' END AS problem_flags")
            ->selectRaw("'edo_pending' AS risk_flags")
            ->selectRaw("'prohelper_management_work' AS source_of_truth");
    }

    private function paymentDocumentSourceQuery(WipForecastReportFilters $filters): QueryBuilder
    {
        $dateExpression = 'COALESCE(payment_documents.approved_at::date, payment_documents.document_date, payment_documents.due_date)';
        $amountExpression = 'GREATEST(COALESCE(payment_documents.amount_without_vat, payment_documents.amount - COALESCE(payment_documents.vat_amount, 0), payment_documents.amount), 0)';
        $currencyExpression = "UPPER(COALESCE(NULLIF(payment_documents.currency, ''), 'RUB'))";
        $contractExpression = $this->paymentDocumentContractExpression();
        $estimateItemExpression = "CASE WHEN payment_documents.metadata->>'estimate_item_id' ~ '^[0-9]+$' THEN (payment_documents.metadata->>'estimate_item_id')::bigint ELSE NULL END";
        $isCashOnly = "payment_documents.source_id IS NULL AND payment_documents.invoiceable_id IS NULL AND COALESCE(payment_documents.metadata->>'accrual_source', '') <> 'management_accrual'";

        return DB::table('payment_documents')
            ->where('payment_documents.organization_id', $filters->organizationId)
            ->whereNull('payment_documents.deleted_at')
            ->whereIn('payment_documents.status', $this->actualCostStatusValues())
            ->where('payment_documents.direction', InvoiceDirection::OUTGOING->value)
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("CASE WHEN {$isCashOnly} THEN 'cash_only_payment' ELSE 'payment_document_accrual' END AS source_type")
            ->selectRaw('payment_documents.id AS source_id')
            ->selectRaw('payment_documents.id AS source_line_id')
            ->selectRaw("DATE_TRUNC('month', {$dateExpression})::date AS period_month")
            ->selectRaw("{$dateExpression}::date AS recognition_date")
            ->selectRaw('payment_documents.project_id AS project_id')
            ->selectRaw('NULL::bigint AS stage_id')
            ->selectRaw("{$contractExpression} AS contract_id")
            ->selectRaw("{$estimateItemExpression} AS estimate_item_id")
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('0::numeric AS bac')
            ->selectRaw('0::numeric AS pv')
            ->selectRaw('NULL::numeric AS percent_complete')
            ->selectRaw('0::numeric AS ev')
            ->selectRaw('0::numeric AS approved_act_value')
            ->selectRaw("CASE WHEN {$isCashOnly} THEN 0 ELSE {$amountExpression} END AS actual_cost_accrual")
            ->selectRaw("CASE WHEN {$isCashOnly} THEN {$amountExpression} ELSE 0 END AS cash_only_payments")
            ->selectRaw('0::numeric AS bottom_up_etc')
            ->selectRaw('0::numeric AS forecast_revenue')
            ->selectRaw('payment_documents.document_number AS source_document_number')
            ->selectRaw('COALESCE(payment_documents.description, payment_documents.payment_purpose) AS source_title')
            ->selectRaw("CASE WHEN {$isCashOnly} THEN 'attention' ELSE 'actual' END AS quality_status")
            ->selectRaw("CASE WHEN payment_documents.project_id IS NULL THEN 'missing_project' ELSE '' END AS problem_flags")
            ->selectRaw("CASE WHEN {$isCashOnly} THEN 'cash_only_source' ELSE '' END AS risk_flags")
            ->selectRaw("CASE WHEN {$isCashOnly} THEN 'cash_confirmation_only' ELSE 'prohelper_management_accrual' END AS source_of_truth");
    }

    private function warehouseMovementSourceQuery(WipForecastReportFilters $filters): QueryBuilder
    {
        $amountExpression = 'GREATEST(COALESCE(warehouse_movements.quantity, 0) * COALESCE(warehouse_movements.price, 0), 0)';

        return DB::table('warehouse_movements')
            ->where('warehouse_movements.organization_id', $filters->organizationId)
            ->whereIn('warehouse_movements.movement_type', ['write_off', 'transfer_out'])
            ->whereBetween(DB::raw('warehouse_movements.movement_date::date'), [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("'warehouse_movement' AS source_type")
            ->selectRaw('warehouse_movements.id AS source_id')
            ->selectRaw('warehouse_movements.id AS source_line_id')
            ->selectRaw("DATE_TRUNC('month', warehouse_movements.movement_date)::date AS period_month")
            ->selectRaw('warehouse_movements.movement_date::date AS recognition_date')
            ->selectRaw('warehouse_movements.project_id AS project_id')
            ->selectRaw('NULL::bigint AS stage_id')
            ->selectRaw('NULL::bigint AS contract_id')
            ->selectRaw('NULL::bigint AS estimate_item_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw('0::numeric AS bac')
            ->selectRaw('0::numeric AS pv')
            ->selectRaw('NULL::numeric AS percent_complete')
            ->selectRaw('0::numeric AS ev')
            ->selectRaw('0::numeric AS approved_act_value')
            ->selectRaw("{$amountExpression} AS actual_cost_accrual")
            ->selectRaw('0::numeric AS cash_only_payments')
            ->selectRaw('0::numeric AS bottom_up_etc')
            ->selectRaw('0::numeric AS forecast_revenue')
            ->selectRaw('warehouse_movements.document_number AS source_document_number')
            ->selectRaw('warehouse_movements.reason AS source_title')
            ->selectRaw("'attention' AS quality_status")
            ->selectRaw("CASE WHEN warehouse_movements.project_id IS NULL THEN 'missing_project' ELSE '' END AS problem_flags")
            ->selectRaw("'indirect_cost_policy_sensitive' AS risk_flags")
            ->selectRaw("'prohelper_management_warehouse' AS source_of_truth");
    }

    private function timeEntrySourceQuery(WipForecastReportFilters $filters): QueryBuilder
    {
        $amountExpression = 'GREATEST(COALESCE(time_entries.hours_worked, 0) * COALESCE(time_entries.hourly_rate, 0), 0)';

        return DB::table('time_entries')
            ->where('time_entries.organization_id', $filters->organizationId)
            ->whereNull('time_entries.deleted_at')
            ->where('time_entries.status', 'approved')
            ->whereBetween('time_entries.work_date', [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("'time_entry' AS source_type")
            ->selectRaw('time_entries.id AS source_id')
            ->selectRaw('time_entries.id AS source_line_id')
            ->selectRaw("DATE_TRUNC('month', time_entries.work_date)::date AS period_month")
            ->selectRaw('time_entries.work_date::date AS recognition_date')
            ->selectRaw('time_entries.project_id AS project_id')
            ->selectRaw('NULL::bigint AS stage_id')
            ->selectRaw('NULL::bigint AS contract_id')
            ->selectRaw('NULL::bigint AS estimate_item_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw('0::numeric AS bac')
            ->selectRaw('0::numeric AS pv')
            ->selectRaw('NULL::numeric AS percent_complete')
            ->selectRaw('0::numeric AS ev')
            ->selectRaw('0::numeric AS approved_act_value')
            ->selectRaw("{$amountExpression} AS actual_cost_accrual")
            ->selectRaw('0::numeric AS cash_only_payments')
            ->selectRaw('0::numeric AS bottom_up_etc')
            ->selectRaw('0::numeric AS forecast_revenue')
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('time_entries.title AS source_title')
            ->selectRaw("'attention' AS quality_status")
            ->selectRaw("CASE WHEN time_entries.project_id IS NULL THEN 'missing_project' ELSE '' END AS problem_flags")
            ->selectRaw("'indirect_cost_policy_sensitive' AS risk_flags")
            ->selectRaw("'prohelper_management_labor' AS source_of_truth");
    }

    private function applyNormalizedFilters(QueryBuilder $query, WipForecastReportFilters $filters): void
    {
        $query
            ->when($filters->projectId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('project_id', $filters->projectId))
            ->when($filters->stageId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('stage_id', $filters->stageId))
            ->when($filters->contractId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('contract_id', $filters->contractId))
            ->when($filters->estimateItemId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('estimate_item_id', $filters->estimateItemId))
            ->when($filters->currency !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('currency', $filters->currency));
    }

    private function applyDrillDownDimensions(QueryBuilder $query, WipForecastDrillDownKey $key): void
    {
        if ($key->hasDimension(WipForecastReportFilters::GROUP_PERIOD)) {
            $period = (string) $key->value(WipForecastReportFilters::GROUP_PERIOD);
            $query->where('period_month', CarbonImmutable::parse($period . '-01')->toDateString());
        }

        $this->applyNullableDrillDimension($query, $key, WipForecastReportFilters::GROUP_PROJECT, 'project_id');
        $this->applyNullableDrillDimension($query, $key, WipForecastReportFilters::GROUP_STAGE, 'stage_id');
        $this->applyNullableDrillDimension($query, $key, WipForecastReportFilters::GROUP_CONTRACT, 'contract_id');
        $this->applyNullableDrillDimension($query, $key, WipForecastReportFilters::GROUP_ESTIMATE_ITEM, 'estimate_item_id');

        if ($key->hasDimension(WipForecastReportFilters::GROUP_CURRENCY)) {
            $query->where('currency', (string) $key->value(WipForecastReportFilters::GROUP_CURRENCY));
        }
    }

    private function applyNullableDrillDimension(
        QueryBuilder $query,
        WipForecastDrillDownKey $key,
        string $dimension,
        string $column,
    ): void {
        if (!$key->hasDimension($dimension)) {
            return;
        }

        $value = $key->value($dimension);
        if ($value === null || $value === '') {
            $query->whereNull($column);
            return;
        }

        $query->where($column, (int) $value);
    }

    private function storedLines(WipForecastVersion $version, WipForecastReportFilters $filters): Collection
    {
        return $version->lines()
            ->where('organization_id', $filters->organizationId)
            ->whereDate('period', '>=', $filters->periodStartMonth())
            ->whereDate('period', '<=', $filters->periodEndMonth())
            ->when($filters->projectId, fn (Builder $query, int $projectId): Builder => $query->where('project_id', $projectId))
            ->when($filters->stageId, fn (Builder $query, int $stageId): Builder => $query->where('stage_id', $stageId))
            ->when($filters->contractId, fn (Builder $query, int $contractId): Builder => $query->where('contract_id', $contractId))
            ->when($filters->estimateItemId, fn (Builder $query, int $itemId): Builder => $query->where('estimate_item_id', $itemId))
            ->when($filters->currency, fn (Builder $query, string $currency): Builder => $query->where('currency', mb_strtoupper($currency)))
            ->get();
    }

    private function dimensionsForLines(Collection $lines): WipForecastDimensions
    {
        return $this->dimensionsFromIds(
            projectIds: $this->ids($lines, 'project_id'),
            stageIds: $this->ids($lines, 'stage_id'),
            contractIds: $this->ids($lines, 'contract_id'),
            estimateItemIds: $this->ids($lines, 'estimate_item_id'),
            organizationId: null,
        );
    }

    /**
     * @param list<WipForecastSourceAggregate> $aggregates
     */
    private function dimensionsForAggregates(WipForecastReportFilters $filters, array $aggregates): WipForecastDimensions
    {
        $projectIds = [];
        $stageIds = [];
        $contractIds = [];
        $estimateItemIds = [];

        foreach ($aggregates as $aggregate) {
            $this->collectId($projectIds, $aggregate->projectId);
            $this->collectId($stageIds, $aggregate->stageId);
            $this->collectId($contractIds, $aggregate->contractId);
            $this->collectId($estimateItemIds, $aggregate->estimateItemId);
        }

        return $this->dimensionsFromIds($projectIds, $stageIds, $contractIds, $estimateItemIds, $filters->organizationId);
    }

    private function dimensionsFromIds(array $projectIds, array $stageIds, array $contractIds, array $estimateItemIds, ?int $organizationId): WipForecastDimensions
    {
        return new WipForecastDimensions(
            projects: Project::query()
                ->whereIn('id', $projectIds)
                ->when($organizationId !== null, fn (Builder $query): Builder => $query->accessibleByOrganization($organizationId))
                ->get(['id', 'name', 'status'])
                ->mapWithKeys(static fn (Project $project): array => [(int) $project->id => [
                    'id' => (int) $project->id,
                    'name' => (string) $project->name,
                    'status' => $project->status,
                ]])
                ->all(),
            stages: $this->stageDimensions($stageIds),
            contracts: Contract::query()
                ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
                ->whereIn('id', $contractIds)
                ->get(['id', 'number', 'subject', 'status'])
                ->mapWithKeys(static fn (Contract $contract): array => [(int) $contract->id => [
                    'id' => (int) $contract->id,
                    'number' => (string) $contract->number,
                    'subject' => $contract->subject,
                    'status' => $contract->status,
                ]])
                ->all(),
            estimateItems: EstimateItem::query()
                ->whereIn('id', $estimateItemIds)
                ->get(['id', 'name', 'position_number'])
                ->mapWithKeys(static fn (EstimateItem $item): array => [(int) $item->id => [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                    'position_number' => $item->position_number,
                ]])
                ->all(),
        );
    }

    private function filterLinesByKey(Collection $lines, WipForecastDrillDownKey $key): Collection
    {
        return $lines->filter(function (WipForecastLine $line) use ($key): bool {
            foreach ($key->groupBy as $dimension) {
                $actual = match ($dimension) {
                    WipForecastReportFilters::GROUP_PROJECT => $line->project_id,
                    WipForecastReportFilters::GROUP_STAGE => $line->stage_id,
                    WipForecastReportFilters::GROUP_CONTRACT => $line->contract_id,
                    WipForecastReportFilters::GROUP_ESTIMATE_ITEM => $line->estimate_item_id,
                    WipForecastReportFilters::GROUP_PERIOD => $line->period === null ? null : CarbonImmutable::parse($line->period)->format('Y-m'),
                    WipForecastReportFilters::GROUP_CURRENCY => $line->currency,
                    default => ($line->group_values ?? [])[$dimension] ?? null,
                };

                if ((string) $actual !== (string) $key->value($dimension)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    private function storedDrillDownItem(WipForecastLine $line): array
    {
        return [
            'id' => $line->uuid ?? $line->id,
            'source_type' => 'wip_forecast_line',
            'period' => $line->period,
            'currency' => $line->currency,
            'group' => $line->group_values ?? [],
            'dimensions' => $line->dimensions ?? [],
            'metrics' => [
                'bac' => (float) $line->bac,
                'percent_complete' => $line->percent_complete === null ? null : (float) $line->percent_complete,
                'ev' => (float) $line->ev,
                'pv' => (float) $line->pv,
                'ac' => (float) $line->ac,
                'wip_total' => (float) $line->wip_total,
                'ctc' => (float) $line->ctc,
                'etc' => (float) $line->etc,
                'ftc' => (float) $line->ftc,
                'eac' => (float) $line->eac,
                'forecast_revenue_at_completion' => (float) $line->forecast_revenue_at_completion,
                'forecast_gross_margin' => (float) $line->forecast_gross_margin,
                'forecast_margin_percent' => $line->forecast_margin_percent === null ? null : (float) $line->forecast_margin_percent,
                'cpi' => $line->cpi === null ? null : (float) $line->cpi,
                'spi' => $line->spi === null ? null : (float) $line->spi,
            ],
            'source_row_refs' => $line->source_row_refs ?? [],
            'formula_components' => $line->formula_components ?? [],
            'problem_flags' => $line->problem_flags ?? [],
            'risk_flags' => $line->risk_flags ?? [],
            'freshness_status' => $line->quality_status,
            'quality_status' => $line->quality_status,
        ];
    }

    private function liveDrillDownItem(object $row): array
    {
        $ev = round((float) ($row->ev ?? 0), 2);
        $approvedAct = round((float) ($row->approved_act_value ?? 0), 2);
        $wip = max($ev - $approvedAct, 0.0);

        return [
            'source_type' => (string) $row->source_type,
            'source_id' => $this->nullableScalar($row->source_id ?? null),
            'source_line_id' => $this->nullableScalar($row->source_line_id ?? null),
            'source_document_number' => $this->nullableString($row->source_document_number ?? null),
            'title' => $this->nullableString($row->source_title ?? null),
            'period' => CarbonImmutable::parse((string) $row->period_month)->format('Y-m'),
            'recognition_date' => CarbonImmutable::parse((string) $row->recognition_date)->toDateString(),
            'currency' => mb_strtoupper((string) $row->currency),
            'metrics' => [
                'bac' => round((float) ($row->bac ?? 0), 2),
                'pv' => round((float) ($row->pv ?? 0), 2),
                'ev' => $ev,
                'approved_act_value' => $approvedAct,
                'ac' => round((float) ($row->actual_cost_accrual ?? 0), 2),
                'cash_only_payments_excluded' => round((float) ($row->cash_only_payments ?? 0), 2),
                'wip_total' => round($wip, 2),
                'forecast_revenue_at_completion' => round((float) ($row->forecast_revenue ?? 0), 2),
            ],
            'source_of_truth' => (string) $row->source_of_truth,
            'freshness_status' => (string) $row->quality_status,
            'quality_status' => (string) $row->quality_status,
            'problem_flags' => $this->csv((string) ($row->problem_flags ?? '')),
            'risk_flags' => $this->csv((string) ($row->risk_flags ?? '')),
        ];
    }

    private function sourceCoverage(WipForecastReportFilters $filters): array
    {
        $counts = DB::query()
            ->fromSub($this->sourceRowsQuery($filters), 'wip_forecast_coverage')
            ->selectRaw('source_type')
            ->selectRaw('COUNT(*) AS rows_count')
            ->selectRaw("SUM(CASE WHEN problem_flags <> '' THEN 1 ELSE 0 END) AS problem_rows_count")
            ->groupBy('source_type')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->source_type => [
                    'rows_count' => (int) $row->rows_count,
                    'problem_rows_count' => (int) $row->problem_rows_count,
                ],
            ])
            ->all();

        return [
            $this->coverageItem('budget_amount', $filters->budgetVersionId !== null, $counts, 'budget_amounts'),
            $this->coverageItem('contract_performance_act', true, $counts, 'contract_performance_acts'),
            $this->coverageItem('completed_work', true, $counts, 'completed_works'),
            $this->coverageItem('payment_document_accrual', true, $counts, 'payment_documents'),
            $this->coverageItem('cash_only_payment', true, $counts, 'payment_documents'),
            $this->coverageItem('warehouse_movement', true, $counts, 'warehouse_movements'),
            $this->coverageItem('time_entry', true, $counts, 'time_entries'),
        ];
    }

    private function coverageItem(string $sourceType, bool $available, array $counts, string $translationKey): array
    {
        return [
            'source_type' => $sourceType,
            'available' => $available,
            'included_source_rows' => (int) ($counts[$sourceType]['rows_count'] ?? 0),
            'problem_rows_count' => (int) ($counts[$sourceType]['problem_rows_count'] ?? 0),
            'freshness_status' => $available ? 'actual' : 'partial',
            'coverage_note' => trans_message("budgeting.wip_forecast.sources.{$translationKey}"),
        ];
    }

    private function freshness(array $sourceCoverage): array
    {
        $status = 'actual';

        foreach ($sourceCoverage as $source) {
            if (($source['available'] ?? true) === false) {
                $status = 'partial';
                break;
            }

            if (($source['freshness_status'] ?? 'actual') === 'stale') {
                $status = 'stale';
            }
        }

        return [
            'status' => $status,
            'calculated_at' => now()->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
            'sla' => [
                'calculation_max_age_minutes' => 60,
                'progress_max_age_working_days' => 3,
            ],
        ];
    }

    private function comparison(?WipForecastVersion $version, ?BudgetVersion $budgetVersion): array
    {
        $previous = $version?->previousVersion;

        return [
            'active_forecast' => $version instanceof WipForecastVersion ? $this->forecastVersionPayload($version) : null,
            'previous_forecast' => $previous instanceof WipForecastVersion ? $this->forecastVersionPayload($previous) : null,
            'approved_budget' => $this->budgetVersionPayload($budgetVersion),
        ];
    }

    private function permissions(?User $user, int $organizationId): array
    {
        return [
            'create_version' => $this->can($user, 'budgeting.wip_forecast.create_version', $organizationId),
            'update_version' => $this->can($user, 'budgeting.wip_forecast.update_version', $organizationId),
            'submit_version' => $this->can($user, 'budgeting.wip_forecast.submit_version', $organizationId),
            'approve_version' => $this->can($user, 'budgeting.wip_forecast.approve_version', $organizationId),
            'activate_version' => $this->can($user, 'budgeting.wip_forecast.activate_version', $organizationId),
            'manage_adjustments' => $this->can($user, 'budgeting.wip_forecast.manage_adjustments', $organizationId),
            'export' => $this->can($user, 'budgeting.wip_forecast.export', $organizationId),
            'view_audit' => $this->can($user, 'budgeting.wip_forecast.view_audit', $organizationId),
        ];
    }

    private function can(?User $user, string $permission, int $organizationId): bool
    {
        return $user instanceof User
            && $this->authorization->can($user, $permission, ['organization_id' => $organizationId]);
    }

    private function forecastVersionPayload(mixed $version): ?array
    {
        if (!$version instanceof WipForecastVersion) {
            return null;
        }

        return [
            'id' => $version->uuid,
            'uuid' => $version->uuid,
            'name' => $version->name,
            'status' => $version->status,
            'version_number' => (int) $version->version_number,
            'period_start' => $version->period_start?->toDateString(),
            'period_end' => $version->period_end?->toDateString(),
            'as_of_date' => $version->as_of_date?->toDateString(),
            'activated_at' => $version->activated_at?->toIso8601String(),
        ];
    }

    private function budgetVersionPayload(mixed $version): ?array
    {
        if (!$version instanceof BudgetVersion) {
            return null;
        }

        return [
            'id' => $version->uuid,
            'uuid' => $version->uuid,
            'name' => $version->name,
            'status' => $version->status,
            'version_number' => (int) $version->version_number,
            'budget_kind' => $version->budget_kind,
        ];
    }

    private function scenarioPayload(mixed $scenario): ?array
    {
        if (!$scenario instanceof BudgetScenario) {
            return null;
        }

        return [
            'id' => $scenario->uuid,
            'uuid' => $scenario->uuid,
            'code' => $scenario->code,
            'name' => $scenario->name,
            'scenario_type' => $scenario->scenario_type,
        ];
    }

    private function assumptionPayload(WipForecastAssumption $assumption): array
    {
        return [
            'uuid' => $assumption->uuid,
            'assumption_type' => $assumption->assumption_type,
            'scope' => $assumption->scope,
            'scope_id' => $assumption->scope_id,
            'title' => $assumption->title,
            'description' => $assumption->description,
            'amount' => $assumption->amount === null ? null : (float) $assumption->amount,
            'percent' => $assumption->percent === null ? null : (float) $assumption->percent,
            'currency' => $assumption->currency,
            'status' => $assumption->status,
            'valid_until' => $assumption->valid_until?->toDateString(),
            'source_row_refs' => $assumption->source_row_refs ?? [],
        ];
    }

    private function sourceSnapshotHash(WipForecastReportFilters $filters, Collection $aggregateRows): string
    {
        return hash('sha256', json_encode([
            'filters' => $filters->toArray(),
            'rows' => $aggregateRows->map(static fn (object $row): array => (array) $row)->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function stageDimensions(array $stageIds): array
    {
        $stages = [];

        foreach ($stageIds as $stageId) {
            if (!is_numeric($stageId)) {
                continue;
            }

            $id = (int) $stageId;
            $stages[$id] = [
                'id' => $id,
                'name' => 'Этап ' . $id,
            ];
        }

        return $stages;
    }

    private function groupBy(mixed $value): array
    {
        $groups = is_array($value)
            ? $value
            : (is_string($value) ? preg_split('/\s*,\s*/', $value) : []);
        $groups = array_values(array_intersect(
            array_filter($groups ?: [], 'is_string'),
            WipForecastReportFilters::ALLOWED_GROUP_BY,
        ));

        if ($groups === []) {
            $groups = WipForecastReportFilters::DEFAULT_GROUP_BY;
        }

        if (!in_array(WipForecastReportFilters::GROUP_CURRENCY, $groups, true)) {
            $groups[] = WipForecastReportFilters::GROUP_CURRENCY;
        }

        return array_values(array_unique($groups));
    }

    private function ids(Collection $lines, string $field): array
    {
        return $lines
            ->pluck($field)
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function collectId(array &$ids, ?int $id): void
    {
        if ($id !== null) {
            $ids[$id] = $id;
        }
    }

    private function paymentDocumentContractExpression(): string
    {
        return "CASE
            WHEN payment_documents.invoiceable_type = 'App\\Models\\Contract' AND payment_documents.invoiceable_id IS NOT NULL
                THEN payment_documents.invoiceable_id
            WHEN payment_documents.source_type = 'App\\Models\\Contract' AND payment_documents.source_id IS NOT NULL
                THEN payment_documents.source_id
            WHEN payment_documents.metadata->>'contract_id' ~ '^[0-9]+$'
                THEN (payment_documents.metadata->>'contract_id')::bigint
            ELSE NULL
        END";
    }

    /**
     * @return list<string>
     */
    private function actualCostStatusValues(): array
    {
        return [
            PaymentDocumentStatus::APPROVED->value,
            PaymentDocumentStatus::SCHEDULED->value,
            PaymentDocumentStatus::PARTIALLY_PAID->value,
            PaymentDocumentStatus::PAID->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function csv(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $items = preg_split('/\s*,\s*/', $value) ?: [];

        return array_values(array_unique(array_filter($items, static fn (string $item): bool => $item !== '')));
    }

    private function nullableCurrency(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strtoupper(trim($value));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableScalar(mixed $value): int|string|null
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
