<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDrillDownItem;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactDrillDownResult;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactSourceAggregate;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\Contractor;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use function trans_message;

final class PlanFactReportService
{
    private const ACTIVE_COMMITMENT_STATUSES = [
        PaymentDocumentStatus::SUBMITTED,
        PaymentDocumentStatus::PENDING_APPROVAL,
        PaymentDocumentStatus::APPROVED,
        PaymentDocumentStatus::SCHEDULED,
        PaymentDocumentStatus::PARTIALLY_PAID,
    ];

    public function __construct(
        private readonly PlanFactCalculator $calculator,
        private readonly ?EpmDataMartFreshnessService $dataMartFreshness = null,
    ) {
    }

    public function report(array $input): array
    {
        $context = $this->resolveContext($input);
        /** @var PlanFactReportFilters $filters */
        $filters = $context['filters'];

        $planAggregates = $this->planAggregates($filters);
        $actualAggregates = $this->actualAggregates($filters);
        $reservationAggregates = $this->reservationCommitmentAggregates($filters);
        $documentAggregates = $this->documentCommitmentAggregates($filters);
        $aggregates = [
            ...$planAggregates,
            ...$actualAggregates,
            ...$reservationAggregates,
            ...$documentAggregates,
        ];
        $dimensions = $this->dimensionsForAggregates($filters, $aggregates);
        [$coverage, $warnings] = $this->sourcesCoverage(
            $filters,
            $planAggregates,
            $actualAggregates,
            $reservationAggregates,
            $documentAggregates,
        );

        $payload = $this->calculator->calculate(
            filters: $filters,
            aggregates: $aggregates,
            dimensions: $dimensions,
            scenario: $this->scenarioToArray($context['scenario']),
            budgetVersion: $this->versionToArray($context['version']),
            sourcesCoverage: $coverage,
            warnings: $warnings,
            meta: [
                'generated_at' => now()->toIso8601String(),
                'drill_down_endpoint' => '/api/v1/admin/budgeting/plan-fact/drill-down',
            ],
        )->toArray();

        if (($input['_skip_data_mart_meta'] ?? false) === true) {
            return $payload;
        }

        return $this->dataMartFreshness()->decoratePayload(
            $payload,
            EpmDataMartScope::fromInput(EpmDataMartScope::PLAN_FACT, [
                ...$filters->toArray(),
                'period_start' => $filters->periodStart,
                'period_end' => $filters->periodEnd,
            ]),
        );
    }

    private function dataMartFreshness(): EpmDataMartFreshnessService
    {
        return $this->dataMartFreshness ?? app(EpmDataMartFreshnessService::class);
    }

    public function drillDown(array $input): array
    {
        $context = $this->resolveContext($input);
        /** @var PlanFactReportFilters $filters */
        $filters = $context['filters'];
        $key = PlanFactDrillDownKey::decode((string) $input['drill_down_key']);
        $this->assertDrillDownKeyMatchesFilters($filters, $key);

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(500, max(1, (int) ($input['per_page'] ?? 100)));
        $offset = ($page - 1) * $perPage;
        $unionForTotal = $this->drillDownUnionQuery($filters, $key);
        $total = (int) DB::query()->fromSub($unionForTotal, 'plan_fact_sources')->count();
        $rows = DB::query()
            ->fromSub($this->drillDownUnionQuery($filters, $key), 'plan_fact_sources')
            ->orderByDesc('source_date')
            ->orderBy('source_type')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $items = $rows
            ->map(fn (object $row): PlanFactDrillDownItem => $this->drillDownItem($row))
            ->all();

        $summary = $this->drillDownSummary($items);

        return (new PlanFactDrillDownResult(
            filters: $filters->toArray(),
            period: $filters->period(),
            group: $this->drillDownGroup($key),
            summary: $summary,
            items: $items,
            warnings: $total === 0 ? [trans_message('budgeting.plan_fact.warnings.drill_down_empty')] : [],
            meta: [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'budget_version' => $this->versionToArray($context['version']),
                'scenario' => $this->scenarioToArray($context['scenario']),
            ],
        ))->toArray();
    }

    private function resolveContext(array $input): array
    {
        $organizationId = (int) ($input['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $periodStart = CarbonImmutable::parse((string) $input['period_start'])->toDateString();
        $periodEnd = CarbonImmutable::parse((string) $input['period_end'])->toDateString();

        if (CarbonImmutable::parse($periodEnd)->lt(CarbonImmutable::parse($periodStart))) {
            throw new DomainException(trans_message('budgeting.plan_fact.errors.period_invalid'));
        }

        $scenario = $this->resolveScenario($organizationId, $input['scenario_uuid'] ?? null);
        $version = $this->resolveVersion($organizationId, $periodStart, $periodEnd, $scenario, $input['budget_version_uuid'] ?? null);
        $scenario = $version->scenario;

        if (!$scenario instanceof BudgetScenario) {
            throw new DomainException(trans_message('budgeting.scenarios.not_found'));
        }

        [$budgetArticleId, $budgetArticleUuid] = $this->resolveCatalogFilter(
            BudgetArticle::class,
            $organizationId,
            $input['budget_article_id'] ?? null,
            trans_message('budgeting.articles.not_found'),
        );
        [$responsibilityCenterId, $responsibilityCenterUuid] = $this->resolveCatalogFilter(
            ResponsibilityCenter::class,
            $organizationId,
            $input['responsibility_center_id'] ?? null,
            trans_message('budgeting.cfo.not_found'),
        );
        $projectId = $this->resolveProjectFilter($organizationId, $input['project_id'] ?? null);
        $counterpartyId = $this->resolveCounterpartyFilter($organizationId, $input['counterparty_id'] ?? null);
        $currency = $this->nullableCurrency($input['currency'] ?? null);

        return [
            'filters' => new PlanFactReportFilters(
                organizationId: $organizationId,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                budgetVersionId: (int) $version->id,
                budgetVersionUuid: (string) $version->uuid,
                scenarioId: (int) $scenario->id,
                scenarioUuid: (string) $scenario->uuid,
                projectId: $projectId,
                responsibilityCenterId: $responsibilityCenterId,
                responsibilityCenterUuid: $responsibilityCenterUuid,
                budgetArticleId: $budgetArticleId,
                budgetArticleUuid: $budgetArticleUuid,
                counterpartyId: $counterpartyId,
                currency: $currency,
                groupBy: $this->normalizeGroupBy($input['group_by'] ?? null),
            ),
            'version' => $version,
            'scenario' => $scenario,
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

    private function resolveVersion(
        int $organizationId,
        string $periodStart,
        string $periodEnd,
        ?BudgetScenario $scenario,
        mixed $versionUuid,
    ): BudgetVersion {
        if (is_string($versionUuid) && trim($versionUuid) !== '') {
            $version = BudgetVersion::query()
                ->with(['period', 'scenario'])
                ->where('organization_id', $organizationId)
                ->where('uuid', trim($versionUuid))
                ->first();

            if (!$version instanceof BudgetVersion) {
                throw new DomainException(trans_message('budgeting.versions.not_found'));
            }

            if ($scenario instanceof BudgetScenario && (int) $version->scenario_id !== (int) $scenario->id) {
                throw new DomainException(trans_message('budgeting.plan_fact.errors.scenario_mismatch'));
            }

            $this->assertVersionPeriodOverlaps($version, $periodStart, $periodEnd);

            return $version;
        }

        $versions = BudgetVersion::query()
            ->with(['period', 'scenario'])
            ->where('organization_id', $organizationId)
            ->whereIn('status', [BudgetWorkflowService::STATUS_ACTIVE])
            ->whereIn('budget_kind', ['bdds', 'consolidated'])
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
            ->orderByRaw("CASE WHEN budget_kind = 'bdds' THEN 0 ELSE 1 END")
            ->orderByDesc('version_number')
            ->get();

        if ($versions->count() === 1) {
            $version = $versions->first();
            if ($version instanceof BudgetVersion) {
                return $version;
            }
        }

        $bddsVersions = $versions->filter(static fn (BudgetVersion $version): bool => $version->budget_kind === 'bdds');
        if ($bddsVersions->count() === 1) {
            $version = $bddsVersions->first();
            if ($version instanceof BudgetVersion) {
                return $version;
            }
        }

        throw new DomainException(trans_message('budgeting.plan_fact.errors.version_required'));
    }

    private function assertVersionPeriodOverlaps(BudgetVersion $version, string $periodStart, string $periodEnd): void
    {
        $version->loadMissing('period');
        $startsAt = $version->period?->starts_at;
        $endsAt = $version->period?->ends_at;

        if ($startsAt === null || $endsAt === null) {
            throw new DomainException(trans_message('budgeting.periods.not_found'));
        }

        if (
            CarbonImmutable::parse((string) $startsAt)->gt(CarbonImmutable::parse($periodEnd))
            || CarbonImmutable::parse((string) $endsAt)->lt(CarbonImmutable::parse($periodStart))
        ) {
            throw new DomainException(trans_message('budgeting.plan_fact.errors.version_period_mismatch'));
        }
    }

    /**
     * @param class-string<BudgetArticle|ResponsibilityCenter> $modelClass
     * @return array{0:?int,1:?string}
     */
    private function resolveCatalogFilter(string $modelClass, int $organizationId, mixed $value, string $message): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        $model = $modelClass::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($value): void {
                if (is_numeric($value)) {
                    $query->whereKey((int) $value);
                }

                $query->orWhere('uuid', (string) $value);
            })
            ->first();

        if (!$model) {
            throw new DomainException($message);
        }

        return [(int) $model->id, (string) $model->uuid];
    }

    private function resolveProjectFilter(int $organizationId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $projectId = (int) $value;
        $exists = Project::query()
            ->whereKey($projectId)
            ->accessibleByOrganization($organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('budgeting.lines.project_not_found'));
        }

        return $projectId;
    }

    private function resolveCounterpartyFilter(int $organizationId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $counterpartyId = (int) $value;
        $exists = Contractor::query()
            ->where('organization_id', $organizationId)
            ->whereKey($counterpartyId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('budgeting.lines.counterparty_not_found'));
        }

        return $counterpartyId;
    }

    private function normalizeGroupBy(mixed $value): array
    {
        if (is_array($value)) {
            $groups = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $groups = preg_split('/\s*,\s*/', trim($value)) ?: [];
        } else {
            $groups = PlanFactReportFilters::DEFAULT_GROUP_BY;
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (!is_string($group) || trim($group) === '') {
                continue;
            }

            $group = trim($group);
            if (!in_array($group, PlanFactReportFilters::ALLOWED_GROUP_BY, true)) {
                throw new DomainException(trans_message('budgeting.plan_fact.errors.group_by_invalid'));
            }

            $normalized[] = $group;
        }

        if ($normalized === []) {
            $normalized = PlanFactReportFilters::DEFAULT_GROUP_BY;
        }

        if (!in_array(PlanFactReportFilters::GROUP_CURRENCY, $normalized, true)) {
            $normalized[] = PlanFactReportFilters::GROUP_CURRENCY;
        }

        return array_values(array_unique($normalized));
    }

    private function nullableCurrency(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strtoupper(trim($value));
    }

    /**
     * @return list<PlanFactSourceAggregate>
     */
    private function planAggregates(PlanFactReportFilters $filters): array
    {
        $currencyExpression = "UPPER(COALESCE(NULLIF(budget_amounts.currency, ''), budget_lines.currency, 'RUB'))";
        $monthExpression = "DATE_TRUNC('month', budget_amounts.month)::date";
        $query = DB::table('budget_amounts')
            ->join('budget_lines', 'budget_amounts.budget_line_id', '=', 'budget_lines.id')
            ->join('budget_articles', 'budget_lines.budget_article_id', '=', 'budget_articles.id')
            ->where('budget_lines.budget_version_id', $filters->budgetVersionId)
            ->whereBetween('budget_amounts.month', [$filters->periodStartMonth(), $filters->periodEndMonth()])
            ->whereNull('budget_lines.deleted_at')
            ->selectRaw("{$monthExpression} AS period_month")
            ->selectRaw('budget_lines.budget_article_id AS budget_article_id')
            ->selectRaw('budget_lines.responsibility_center_id AS responsibility_center_id')
            ->selectRaw('budget_lines.project_id AS project_id')
            ->selectRaw('budget_lines.counterparty_id AS counterparty_id')
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('budget_articles.flow_direction AS flow_direction')
            ->selectRaw('SUM(budget_amounts.plan_amount) AS plan_amount')
            ->selectRaw('SUM(budget_amounts.forecast_amount) AS forecast_amount')
            ->groupByRaw($monthExpression)
            ->groupBy([
                'budget_lines.budget_article_id',
                'budget_lines.responsibility_center_id',
                'budget_lines.project_id',
                'budget_lines.counterparty_id',
                'budget_articles.flow_direction',
            ])
            ->groupByRaw($currencyExpression);

        $this->applyBudgetLineFilters($query, $filters, $currencyExpression);

        return $this->mapAggregates($query->get(), 'budget_amount');
    }

    /**
     * @return list<PlanFactSourceAggregate>
     */
    private function actualAggregates(PlanFactReportFilters $filters): array
    {
        $dateExpression = 'COALESCE(payment_transactions.value_date, payment_transactions.transaction_date)';
        $monthExpression = "DATE_TRUNC('month', {$dateExpression})::date";
        $projectExpression = 'COALESCE(payment_transactions.project_id, payment_documents.project_id)';
        $counterpartyExpression = $this->transactionCounterpartyExpression();
        $currencyExpression = "UPPER(COALESCE(NULLIF(payment_transactions.currency, ''), payment_documents.currency, 'RUB'))";

        $query = DB::table('payment_transactions')
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->join('budget_articles', 'payment_documents.budget_article_id', '=', 'budget_articles.id')
            ->where('payment_transactions.organization_id', $filters->organizationId)
            ->where('payment_documents.organization_id', $filters->organizationId)
            ->where('payment_transactions.status', PaymentTransactionStatus::COMPLETED->value)
            ->where('payment_transactions.amount', '>', 0)
            ->whereNull('payment_documents.deleted_at')
            ->whereNotNull('payment_documents.budget_article_id')
            ->whereNotNull('payment_documents.responsibility_center_id')
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->selectRaw("{$monthExpression} AS period_month")
            ->selectRaw('payment_documents.budget_article_id AS budget_article_id')
            ->selectRaw('payment_documents.responsibility_center_id AS responsibility_center_id')
            ->selectRaw("{$projectExpression} AS project_id")
            ->selectRaw("{$counterpartyExpression} AS counterparty_id")
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('budget_articles.flow_direction AS flow_direction')
            ->selectRaw('SUM(payment_transactions.amount) AS actual_amount')
            ->groupByRaw($monthExpression)
            ->groupBy([
                'payment_documents.budget_article_id',
                'payment_documents.responsibility_center_id',
                'budget_articles.flow_direction',
            ])
            ->groupByRaw($projectExpression)
            ->groupByRaw($counterpartyExpression)
            ->groupByRaw($currencyExpression);

        $this->applyOperationalFilters(
            $query,
            $filters,
            'payment_documents.budget_article_id',
            'payment_documents.responsibility_center_id',
            $projectExpression,
            $counterpartyExpression,
            $currencyExpression,
        );

        return $this->mapAggregates($query->get(), 'payment_transaction');
    }

    /**
     * @return list<PlanFactSourceAggregate>
     */
    private function reservationCommitmentAggregates(PlanFactReportFilters $filters): array
    {
        $monthExpression = "DATE_TRUNC('month', budget_limit_reservations.period_month)::date";
        $currencyExpression = "UPPER(COALESCE(NULLIF(budget_limit_reservations.currency, ''), 'RUB'))";
        $query = DB::table('budget_limit_reservations')
            ->join('budget_articles', 'budget_limit_reservations.budget_article_id', '=', 'budget_articles.id')
            ->where('budget_limit_reservations.organization_id', $filters->organizationId)
            ->where('budget_limit_reservations.status', BudgetLimitReservation::STATUS_RESERVED)
            ->whereBetween('budget_limit_reservations.period_month', [$filters->periodStartMonth(), $filters->periodEndMonth()])
            ->selectRaw("{$monthExpression} AS period_month")
            ->selectRaw('budget_limit_reservations.budget_article_id AS budget_article_id')
            ->selectRaw('budget_limit_reservations.responsibility_center_id AS responsibility_center_id')
            ->selectRaw('budget_limit_reservations.project_id AS project_id')
            ->selectRaw('budget_limit_reservations.counterparty_id AS counterparty_id')
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('budget_articles.flow_direction AS flow_direction')
            ->selectRaw('SUM(budget_limit_reservations.amount) AS committed_amount')
            ->groupByRaw($monthExpression)
            ->groupBy([
                'budget_limit_reservations.budget_article_id',
                'budget_limit_reservations.responsibility_center_id',
                'budget_limit_reservations.project_id',
                'budget_limit_reservations.counterparty_id',
                'budget_articles.flow_direction',
            ])
            ->groupByRaw($currencyExpression);

        $this->applyOperationalFilters(
            $query,
            $filters,
            'budget_limit_reservations.budget_article_id',
            'budget_limit_reservations.responsibility_center_id',
            'budget_limit_reservations.project_id',
            'budget_limit_reservations.counterparty_id',
            $currencyExpression,
        );

        return $this->mapAggregates($query->get(), 'budget_limit_reservation');
    }

    /**
     * @return list<PlanFactSourceAggregate>
     */
    private function documentCommitmentAggregates(PlanFactReportFilters $filters): array
    {
        $dateExpression = 'COALESCE(payment_documents.scheduled_at::date, payment_documents.due_date, payment_documents.document_date)';
        $monthExpression = "DATE_TRUNC('month', {$dateExpression})::date";
        $counterpartyExpression = $this->documentCounterpartyExpression();
        $currencyExpression = "UPPER(COALESCE(NULLIF(payment_documents.currency, ''), 'RUB'))";
        $amountExpression = 'GREATEST(COALESCE(payment_documents.remaining_amount, payment_documents.amount - COALESCE(payment_documents.paid_amount, 0), payment_documents.amount), 0)';
        $query = DB::table('payment_documents')
            ->join('budget_articles', 'payment_documents.budget_article_id', '=', 'budget_articles.id')
            ->where('payment_documents.organization_id', $filters->organizationId)
            ->whereIn('payment_documents.status', $this->activeCommitmentStatusValues())
            ->whereNull('payment_documents.deleted_at')
            ->whereNotNull('payment_documents.budget_article_id')
            ->whereNotNull('payment_documents.responsibility_center_id')
            ->whereRaw("{$amountExpression} > 0")
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->whereNotExists(function (QueryBuilder $subQuery): void {
                $subQuery->select(DB::raw('1'))
                    ->from('budget_limit_reservations')
                    ->whereColumn('budget_limit_reservations.payment_document_id', 'payment_documents.id')
                    ->where('budget_limit_reservations.status', BudgetLimitReservation::STATUS_RESERVED);
            })
            ->selectRaw("{$monthExpression} AS period_month")
            ->selectRaw('payment_documents.budget_article_id AS budget_article_id')
            ->selectRaw('payment_documents.responsibility_center_id AS responsibility_center_id')
            ->selectRaw('payment_documents.project_id AS project_id')
            ->selectRaw("{$counterpartyExpression} AS counterparty_id")
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('budget_articles.flow_direction AS flow_direction')
            ->selectRaw("SUM({$amountExpression}) AS committed_amount")
            ->groupByRaw($monthExpression)
            ->groupBy([
                'payment_documents.budget_article_id',
                'payment_documents.responsibility_center_id',
                'payment_documents.project_id',
                'budget_articles.flow_direction',
            ])
            ->groupByRaw($counterpartyExpression)
            ->groupByRaw($currencyExpression);

        $this->applyOperationalFilters(
            $query,
            $filters,
            'payment_documents.budget_article_id',
            'payment_documents.responsibility_center_id',
            'payment_documents.project_id',
            $counterpartyExpression,
            $currencyExpression,
        );

        return $this->mapAggregates($query->get(), 'payment_document_commitment');
    }

    /**
     * @param Collection<int, object> $rows
     * @return list<PlanFactSourceAggregate>
     */
    private function mapAggregates(Collection $rows, string $sourceType): array
    {
        return $rows
            ->map(static fn (object $row): PlanFactSourceAggregate => PlanFactSourceAggregate::fromDatabaseRow($row, $sourceType))
            ->all();
    }

    private function applyBudgetLineFilters(QueryBuilder $query, PlanFactReportFilters $filters, string $currencyExpression): void
    {
        $query
            ->when($filters->projectId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('budget_lines.project_id', $filters->projectId))
            ->when($filters->counterpartyId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('budget_lines.counterparty_id', $filters->counterpartyId))
            ->when($filters->budgetArticleId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('budget_lines.budget_article_id', $filters->budgetArticleId))
            ->when($filters->responsibilityCenterId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('budget_lines.responsibility_center_id', $filters->responsibilityCenterId))
            ->when($filters->currency !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->whereRaw("{$currencyExpression} = ?", [$filters->currency]));
    }

    private function applyOperationalFilters(
        QueryBuilder $query,
        PlanFactReportFilters $filters,
        string $budgetArticleColumn,
        string $responsibilityCenterColumn,
        string $projectExpression,
        string $counterpartyExpression,
        string $currencyExpression,
    ): void {
        $query
            ->when($filters->projectId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->whereRaw("{$projectExpression} = ?", [$filters->projectId]))
            ->when($filters->counterpartyId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->whereRaw("{$counterpartyExpression} = ?", [$filters->counterpartyId]))
            ->when($filters->budgetArticleId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where($budgetArticleColumn, $filters->budgetArticleId))
            ->when($filters->responsibilityCenterId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where($responsibilityCenterColumn, $filters->responsibilityCenterId))
            ->when($filters->currency !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->whereRaw("{$currencyExpression} = ?", [$filters->currency]));
    }

    private function dimensionsForAggregates(PlanFactReportFilters $filters, array $aggregates): PlanFactDimensions
    {
        $articleIds = [];
        $centerIds = [];
        $projectIds = [];
        $counterpartyIds = [];

        foreach ($aggregates as $aggregate) {
            if (!$aggregate instanceof PlanFactSourceAggregate) {
                continue;
            }

            $this->collectId($articleIds, $aggregate->budgetArticleId);
            $this->collectId($centerIds, $aggregate->responsibilityCenterId);
            $this->collectId($projectIds, $aggregate->projectId);
            $this->collectId($counterpartyIds, $aggregate->counterpartyId);
        }

        return $this->dimensionRegistry($filters->organizationId, $articleIds, $centerIds, $projectIds, $counterpartyIds);
    }

    private function dimensionRegistry(
        int $organizationId,
        array $articleIds,
        array $centerIds,
        array $projectIds,
        array $counterpartyIds,
    ): PlanFactDimensions
    {
        $articles = BudgetArticle::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $articleIds)
            ->get(['id', 'uuid', 'code', 'name', 'budget_kind', 'flow_direction'])
            ->mapWithKeys(static fn (BudgetArticle $article): array => [
                (int) $article->id => [
                    'id' => $article->uuid,
                    'code' => $article->code,
                    'name' => $article->name,
                    'budget_kind' => $article->budget_kind,
                    'flow_direction' => $article->flow_direction,
                ],
            ])
            ->all();
        $centers = ResponsibilityCenter::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $centerIds)
            ->get(['id', 'uuid', 'code', 'name', 'center_type'])
            ->mapWithKeys(static fn (ResponsibilityCenter $center): array => [
                (int) $center->id => [
                    'id' => $center->uuid,
                    'code' => $center->code,
                    'name' => $center->name,
                    'center_type' => $center->center_type,
                ],
            ])
            ->all();
        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->accessibleByOrganization($organizationId)
            ->get(['id', 'name', 'status'])
            ->mapWithKeys(static fn (Project $project): array => [
                (int) $project->id => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status,
                ],
            ])
            ->all();
        $counterparties = Contractor::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $counterpartyIds)
            ->get(['id', 'name', 'inn'])
            ->mapWithKeys(static fn (Contractor $contractor): array => [
                (int) $contractor->id => [
                    'id' => $contractor->id,
                    'name' => $contractor->name,
                    'inn' => $contractor->inn,
                ],
            ])
            ->all();

        return new PlanFactDimensions($articles, $centers, $projects, $counterparties);
    }

    private function collectId(array &$ids, ?int $id): void
    {
        if ($id !== null) {
            $ids[$id] = $id;
        }
    }

    private function drillDownUnionQuery(PlanFactReportFilters $filters, PlanFactDrillDownKey $key): QueryBuilder
    {
        $actual = $this->actualDrillDownQuery($filters, $key);
        $actual->unionAll($this->reservationDrillDownQuery($filters, $key));
        $actual->unionAll($this->documentDrillDownQuery($filters, $key));

        return $actual;
    }

    private function actualDrillDownQuery(PlanFactReportFilters $filters, PlanFactDrillDownKey $key): QueryBuilder
    {
        $dateExpression = 'COALESCE(payment_transactions.value_date, payment_transactions.transaction_date)';
        $monthExpression = "DATE_TRUNC('month', {$dateExpression})::date";
        $projectExpression = 'COALESCE(payment_transactions.project_id, payment_documents.project_id)';
        $counterpartyExpression = $this->transactionCounterpartyExpression();
        $currencyExpression = "UPPER(COALESCE(NULLIF(payment_transactions.currency, ''), payment_documents.currency, 'RUB'))";
        $query = DB::table('payment_transactions')
            ->join('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->join('budget_articles', 'payment_documents.budget_article_id', '=', 'budget_articles.id')
            ->where('payment_transactions.organization_id', $filters->organizationId)
            ->where('payment_documents.organization_id', $filters->organizationId)
            ->where('payment_transactions.status', PaymentTransactionStatus::COMPLETED->value)
            ->where('payment_transactions.amount', '>', 0)
            ->whereNull('payment_documents.deleted_at')
            ->whereNotNull('payment_documents.budget_article_id')
            ->whereNotNull('payment_documents.responsibility_center_id')
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->selectRaw("'payment_transaction' AS source_type")
            ->selectRaw('payment_transactions.id AS source_id')
            ->selectRaw('payment_documents.id AS payment_document_id')
            ->selectRaw('payment_transactions.reference_number AS number')
            ->selectRaw('COALESCE(payment_transactions.reference_number, payment_documents.document_number) AS title')
            ->selectRaw("{$dateExpression} AS source_date")
            ->selectRaw('payment_transactions.amount AS amount')
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('payment_transactions.status AS status')
            ->selectRaw('budget_articles.flow_direction AS flow_direction');

        $this->applyOperationalFilters(
            $query,
            $filters,
            'payment_documents.budget_article_id',
            'payment_documents.responsibility_center_id',
            $projectExpression,
            $counterpartyExpression,
            $currencyExpression,
        );
        $this->applyDrillDownDimensions(
            $query,
            $key,
            $monthExpression,
            'payment_documents.budget_article_id',
            'payment_documents.responsibility_center_id',
            $projectExpression,
            $currencyExpression,
        );

        return $query;
    }

    private function reservationDrillDownQuery(PlanFactReportFilters $filters, PlanFactDrillDownKey $key): QueryBuilder
    {
        $monthExpression = "DATE_TRUNC('month', budget_limit_reservations.period_month)::date";
        $currencyExpression = "UPPER(COALESCE(NULLIF(budget_limit_reservations.currency, ''), 'RUB'))";
        $query = DB::table('budget_limit_reservations')
            ->leftJoin('payment_documents', 'budget_limit_reservations.payment_document_id', '=', 'payment_documents.id')
            ->join('budget_articles', 'budget_limit_reservations.budget_article_id', '=', 'budget_articles.id')
            ->where('budget_limit_reservations.organization_id', $filters->organizationId)
            ->where('budget_limit_reservations.status', BudgetLimitReservation::STATUS_RESERVED)
            ->whereBetween('budget_limit_reservations.period_month', [$filters->periodStartMonth(), $filters->periodEndMonth()])
            ->selectRaw("'budget_limit_reservation' AS source_type")
            ->selectRaw('budget_limit_reservations.id AS source_id')
            ->selectRaw('budget_limit_reservations.payment_document_id AS payment_document_id')
            ->selectRaw('payment_documents.document_number AS number')
            ->selectRaw("COALESCE(payment_documents.document_number, budget_limit_reservations.metadata->>'document_number') AS title")
            ->selectRaw('budget_limit_reservations.period_month AS source_date')
            ->selectRaw('budget_limit_reservations.amount AS amount')
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('budget_limit_reservations.status AS status')
            ->selectRaw('budget_articles.flow_direction AS flow_direction');

        $this->applyOperationalFilters(
            $query,
            $filters,
            'budget_limit_reservations.budget_article_id',
            'budget_limit_reservations.responsibility_center_id',
            'budget_limit_reservations.project_id',
            'budget_limit_reservations.counterparty_id',
            $currencyExpression,
        );
        $this->applyDrillDownDimensions(
            $query,
            $key,
            $monthExpression,
            'budget_limit_reservations.budget_article_id',
            'budget_limit_reservations.responsibility_center_id',
            'budget_limit_reservations.project_id',
            $currencyExpression,
        );

        return $query;
    }

    private function documentDrillDownQuery(PlanFactReportFilters $filters, PlanFactDrillDownKey $key): QueryBuilder
    {
        $dateExpression = 'COALESCE(payment_documents.scheduled_at::date, payment_documents.due_date, payment_documents.document_date)';
        $monthExpression = "DATE_TRUNC('month', {$dateExpression})::date";
        $counterpartyExpression = $this->documentCounterpartyExpression();
        $currencyExpression = "UPPER(COALESCE(NULLIF(payment_documents.currency, ''), 'RUB'))";
        $amountExpression = 'GREATEST(COALESCE(payment_documents.remaining_amount, payment_documents.amount - COALESCE(payment_documents.paid_amount, 0), payment_documents.amount), 0)';
        $query = DB::table('payment_documents')
            ->join('budget_articles', 'payment_documents.budget_article_id', '=', 'budget_articles.id')
            ->where('payment_documents.organization_id', $filters->organizationId)
            ->whereIn('payment_documents.status', $this->activeCommitmentStatusValues())
            ->whereNull('payment_documents.deleted_at')
            ->whereNotNull('payment_documents.budget_article_id')
            ->whereNotNull('payment_documents.responsibility_center_id')
            ->whereRaw("{$amountExpression} > 0")
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->whereNotExists(function (QueryBuilder $subQuery): void {
                $subQuery->select(DB::raw('1'))
                    ->from('budget_limit_reservations')
                    ->whereColumn('budget_limit_reservations.payment_document_id', 'payment_documents.id')
                    ->where('budget_limit_reservations.status', BudgetLimitReservation::STATUS_RESERVED);
            })
            ->selectRaw("'payment_document_commitment' AS source_type")
            ->selectRaw('payment_documents.id AS source_id')
            ->selectRaw('payment_documents.id AS payment_document_id')
            ->selectRaw('payment_documents.document_number AS number')
            ->selectRaw('COALESCE(payment_documents.document_number, payment_documents.description) AS title')
            ->selectRaw("{$dateExpression} AS source_date")
            ->selectRaw("{$amountExpression} AS amount")
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('payment_documents.status AS status')
            ->selectRaw('budget_articles.flow_direction AS flow_direction');

        $this->applyOperationalFilters(
            $query,
            $filters,
            'payment_documents.budget_article_id',
            'payment_documents.responsibility_center_id',
            'payment_documents.project_id',
            $counterpartyExpression,
            $currencyExpression,
        );
        $this->applyDrillDownDimensions(
            $query,
            $key,
            $monthExpression,
            'payment_documents.budget_article_id',
            'payment_documents.responsibility_center_id',
            'payment_documents.project_id',
            $currencyExpression,
        );

        return $query;
    }

    private function applyDrillDownDimensions(
        QueryBuilder $query,
        PlanFactDrillDownKey $key,
        string $monthExpression,
        string $budgetArticleColumn,
        string $responsibilityCenterColumn,
        string $projectExpression,
        string $currencyExpression,
    ): void {
        if ($key->hasDimension(PlanFactReportFilters::GROUP_MONTH)) {
            $month = (string) $key->value(PlanFactReportFilters::GROUP_MONTH);
            $query->whereRaw("{$monthExpression} = ?", [CarbonImmutable::parse($month . '-01')->toDateString()]);
        }

        $this->applyNullableDrillDimension($query, $key, PlanFactReportFilters::GROUP_BUDGET_ARTICLE, $budgetArticleColumn);
        $this->applyNullableDrillDimension($query, $key, PlanFactReportFilters::GROUP_RESPONSIBILITY_CENTER, $responsibilityCenterColumn);
        $this->applyNullableDrillDimension($query, $key, PlanFactReportFilters::GROUP_PROJECT, $projectExpression, true);

        if ($key->hasDimension(PlanFactReportFilters::GROUP_CURRENCY)) {
            $query->whereRaw("{$currencyExpression} = ?", [(string) $key->value(PlanFactReportFilters::GROUP_CURRENCY)]);
        }
    }

    private function applyNullableDrillDimension(
        QueryBuilder $query,
        PlanFactDrillDownKey $key,
        string $dimension,
        string $columnOrExpression,
        bool $raw = false,
    ): void {
        if (!$key->hasDimension($dimension)) {
            return;
        }

        $value = $key->value($dimension);

        if ($value === null || $value === '') {
            if ($raw) {
                $query->whereRaw("{$columnOrExpression} IS NULL");
                return;
            }

            $query->whereNull($columnOrExpression);
            return;
        }

        if ($raw) {
            $query->whereRaw("{$columnOrExpression} = ?", [(int) $value]);
            return;
        }

        $query->where($columnOrExpression, (int) $value);
    }

    private function assertDrillDownKeyMatchesFilters(PlanFactReportFilters $filters, PlanFactDrillDownKey $key): void
    {
        foreach ($key->groupBy as $group) {
            if (!in_array($group, PlanFactReportFilters::ALLOWED_GROUP_BY, true)) {
                throw new InvalidArgumentException(trans_message('budgeting.plan_fact.errors.drill_down_key_invalid'));
            }
        }

        if ($filters->currency !== null && $key->hasDimension(PlanFactReportFilters::GROUP_CURRENCY)) {
            $currency = (string) $key->value(PlanFactReportFilters::GROUP_CURRENCY);

            if ($currency !== $filters->currency) {
                throw new InvalidArgumentException(trans_message('budgeting.plan_fact.errors.drill_down_key_invalid'));
            }
        }
    }

    private function drillDownItem(object $row): PlanFactDrillDownItem
    {
        $sourceType = (string) $row->source_type;
        $sourceId = $row->source_id;
        $paymentDocumentId = $row->payment_document_id ?? null;
        $routeHint = $sourceType === 'payment_transaction'
            ? [
                'name' => 'admin.payments.transactions.show',
                'params' => ['id' => $sourceId],
                'api_path' => "/api/v1/admin/payments/transactions/{$sourceId}",
            ]
            : [
                'name' => 'admin.payments.documents.show',
                'params' => ['id' => $paymentDocumentId ?? $sourceId],
                'api_path' => '/api/v1/admin/payments/documents/' . (string) ($paymentDocumentId ?? $sourceId),
            ];
        $amount = round((float) $row->amount, 2);

        return new PlanFactDrillDownItem(
            sourceType: $sourceType,
            sourceId: is_int($sourceId) || is_string($sourceId) ? $sourceId : null,
            number: is_string($row->number ?? null) ? $row->number : null,
            title: is_string($row->title ?? null) ? $row->title : null,
            date: CarbonImmutable::parse((string) $row->source_date)->toDateString(),
            amount: $amount,
            currency: mb_strtoupper((string) $row->currency),
            status: (string) $row->status,
            routeHint: $routeHint,
            varianceContribution: $this->varianceContribution($amount, is_string($row->flow_direction ?? null) ? $row->flow_direction : null),
        );
    }

    /**
     * @param list<PlanFactDrillDownItem> $items
     */
    private function drillDownSummary(array $items): array
    {
        $actual = 0.0;
        $committed = 0.0;
        $varianceContribution = 0.0;
        $currencies = [];

        foreach ($items as $item) {
            if ($item->sourceType === 'payment_transaction') {
                $actual += $item->amount;
            } else {
                $committed += $item->amount;
            }

            $varianceContribution += $item->varianceContribution;
            $currencies[$item->currency] = true;
        }

        return [
            'items_count' => count($items),
            'actual_amount' => round($actual, 2),
            'committed_amount' => round($committed, 2),
            'variance_contribution' => round($varianceContribution, 2),
            'currencies' => array_keys($currencies),
        ];
    }

    private function drillDownGroup(PlanFactDrillDownKey $key): array
    {
        return [
            'group_by' => $key->groupBy,
            'dimensions' => $key->dimensions,
        ];
    }

    private function varianceContribution(float $amount, ?string $flowDirection): float
    {
        if (in_array($flowDirection, ['income', 'inflow'], true)) {
            return round($amount, 2);
        }

        return round(-1 * $amount, 2);
    }

    /**
     * @param list<PlanFactSourceAggregate> $planAggregates
     * @param list<PlanFactSourceAggregate> $actualAggregates
     * @param list<PlanFactSourceAggregate> $reservationAggregates
     * @param list<PlanFactSourceAggregate> $documentAggregates
     * @return array{0:list<array<string, mixed>>,1:list<string>}
     */
    private function sourcesCoverage(
        PlanFactReportFilters $filters,
        array $planAggregates,
        array $actualAggregates,
        array $reservationAggregates,
        array $documentAggregates,
    ): array {
        $actualMissing = $this->missingActualAnalyticsCount($filters);
        $documentMissing = $this->missingDocumentAnalyticsCount($filters);
        $schedulesCount = $this->paymentSchedulesCount($filters);
        $warnings = [];

        if ($actualMissing > 0 || $documentMissing > 0) {
            $warnings[] = trans_message('budgeting.plan_fact.warnings.missing_budget_analytics');
        }

        if ($schedulesCount > 0) {
            $warnings[] = trans_message('budgeting.plan_fact.warnings.schedules_covered_by_documents');
        }

        return [[
            [
                'source_type' => 'budget_amounts',
                'available' => true,
                'included_aggregate_rows' => count($planAggregates),
                'missing_budget_analytics_count' => 0,
                'coverage_note' => trans_message('budgeting.plan_fact.sources.budget_amounts'),
            ],
            [
                'source_type' => 'payment_transactions',
                'available' => true,
                'included_aggregate_rows' => count($actualAggregates),
                'missing_budget_analytics_count' => $actualMissing,
                'coverage_note' => trans_message('budgeting.plan_fact.sources.payment_transactions'),
            ],
            [
                'source_type' => 'budget_limit_reservations',
                'available' => true,
                'included_aggregate_rows' => count($reservationAggregates),
                'missing_budget_analytics_count' => 0,
                'coverage_note' => trans_message('budgeting.plan_fact.sources.budget_limit_reservations'),
            ],
            [
                'source_type' => 'payment_documents',
                'available' => true,
                'included_aggregate_rows' => count($documentAggregates),
                'missing_budget_analytics_count' => $documentMissing,
                'coverage_note' => trans_message('budgeting.plan_fact.sources.payment_documents'),
            ],
            [
                'source_type' => 'payment_schedules',
                'available' => true,
                'included_aggregate_rows' => 0,
                'missing_budget_analytics_count' => 0,
                'covered_via' => 'payment_documents',
                'coverage_note' => trans_message('budgeting.plan_fact.sources.payment_schedules'),
                'source_rows_count' => $schedulesCount,
            ],
        ], $warnings];
    }

    private function missingActualAnalyticsCount(PlanFactReportFilters $filters): int
    {
        $dateExpression = 'COALESCE(payment_transactions.value_date, payment_transactions.transaction_date)';
        $query = DB::table('payment_transactions')
            ->leftJoin('payment_documents', 'payment_transactions.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_transactions.organization_id', $filters->organizationId)
            ->where('payment_transactions.status', PaymentTransactionStatus::COMPLETED->value)
            ->where('payment_transactions.amount', '>', 0)
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->where(function (QueryBuilder $builder): void {
                $builder
                    ->whereNull('payment_transactions.payment_document_id')
                    ->orWhereNull('payment_documents.id')
                    ->orWhereNull('payment_documents.budget_article_id')
                    ->orWhereNull('payment_documents.responsibility_center_id');
            });

        if ($filters->currency !== null) {
            $query->whereRaw("UPPER(COALESCE(NULLIF(payment_transactions.currency, ''), payment_documents.currency, 'RUB')) = ?", [$filters->currency]);
        }

        return (int) $query->count();
    }

    private function missingDocumentAnalyticsCount(PlanFactReportFilters $filters): int
    {
        $dateExpression = 'COALESCE(payment_documents.scheduled_at::date, payment_documents.due_date, payment_documents.document_date)';
        $amountExpression = 'GREATEST(COALESCE(payment_documents.remaining_amount, payment_documents.amount - COALESCE(payment_documents.paid_amount, 0), payment_documents.amount), 0)';
        $query = DB::table('payment_documents')
            ->where('organization_id', $filters->organizationId)
            ->whereIn('status', $this->activeCommitmentStatusValues())
            ->whereNull('deleted_at')
            ->whereRaw("{$amountExpression} > 0")
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->where(function (QueryBuilder $builder): void {
                $builder
                    ->whereNull('budget_article_id')
                    ->orWhereNull('responsibility_center_id');
            });

        if ($filters->currency !== null) {
            $query->whereRaw("UPPER(COALESCE(NULLIF(currency, ''), 'RUB')) = ?", [$filters->currency]);
        }

        return (int) $query->count();
    }

    private function paymentSchedulesCount(PlanFactReportFilters $filters): int
    {
        return (int) DB::table('payment_schedules')
            ->join('payment_documents', 'payment_schedules.payment_document_id', '=', 'payment_documents.id')
            ->where('payment_documents.organization_id', $filters->organizationId)
            ->where('payment_schedules.status', 'pending')
            ->whereBetween('payment_schedules.due_date', [$filters->periodStart, $filters->periodEnd])
            ->count();
    }

    private function versionToArray(BudgetVersion $version): array
    {
        $version->loadMissing('period');

        return [
            'id' => $version->uuid,
            'name' => $version->name,
            'budget_kind' => $version->budget_kind,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'period' => [
                'id' => $version->period?->uuid,
                'name' => $version->period?->name,
                'starts_at' => $version->period?->starts_at?->toDateString(),
                'ends_at' => $version->period?->ends_at?->toDateString(),
            ],
        ];
    }

    private function scenarioToArray(BudgetScenario $scenario): array
    {
        return [
            'id' => $scenario->uuid,
            'code' => $scenario->code,
            'name' => $scenario->name,
            'scenario_type' => $scenario->scenario_type,
        ];
    }

    private function transactionCounterpartyExpression(): string
    {
        return "CASE WHEN payment_documents.direction = 'incoming' "
            . 'THEN COALESCE(payment_transactions.payer_contractor_id, payment_documents.payer_contractor_id, payment_documents.contractor_id) '
            . 'ELSE COALESCE(payment_transactions.payee_contractor_id, payment_documents.payee_contractor_id, payment_documents.contractor_id) END';
    }

    private function documentCounterpartyExpression(): string
    {
        return "CASE WHEN payment_documents.direction = 'incoming' "
            . 'THEN COALESCE(payment_documents.payer_contractor_id, payment_documents.contractor_id) '
            . 'ELSE COALESCE(payment_documents.payee_contractor_id, payment_documents.contractor_id) END';
    }

    /**
     * @return list<string>
     */
    private function activeCommitmentStatusValues(): array
    {
        return array_map(
            static fn (PaymentDocumentStatus $status): string => $status->value,
            self::ACTIVE_COMMITMENT_STATUSES,
        );
    }
}
