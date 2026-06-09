<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginDimensions;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginDrillDownKey;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginSourceAggregate;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Epm\ProjectMarginAttributionLine;
use App\Enums\Epm\ProjectMarginProblemFlag;
use App\Enums\Epm\ProjectMarginRiskFlag;
use App\Models\Contract;
use App\Models\Contractor;
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

final class ProjectMarginReportService
{
    private const QUALITY_PARTIAL = 'partial';

    public function __construct(
        private readonly ProjectMarginCalculator $calculator,
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function report(array $input, ?User $user = null): array
    {
        $context = $this->resolveContext($input);
        /** @var ProjectMarginReportFilters $filters */
        $filters = $context['filters'];
        $aggregateRows = $this->aggregateRows($filters);
        $aggregates = $aggregateRows
            ->map(static fn (object $row): ProjectMarginSourceAggregate => ProjectMarginSourceAggregate::fromDatabaseRow($row))
            ->all();
        $dimensions = $this->dimensionsForAggregates($filters, $aggregates);
        [$coverage, $warnings] = $this->sourcesCoverage($filters);

        return $this->calculator->calculate(
            filters: $filters,
            aggregates: $aggregates,
            dimensions: $dimensions,
            scenario: $this->scenarioToArray($context['scenario']),
            budgetVersion: $this->versionToArray($context['version']),
            sourcesCoverage: $coverage,
            warnings: $warnings,
            meta: [
                'generated_at' => now()->toIso8601String(),
                'drill_down_endpoint' => '/api/v1/admin/budgeting/project-margin/drill-down',
                'permissions' => $this->detailPermissions($user, $filters->organizationId),
            ],
        );
    }

    public function drillDown(array $input, ?User $user = null): array
    {
        $context = $this->resolveContext($input);
        /** @var ProjectMarginReportFilters $filters */
        $filters = $context['filters'];
        try {
            $key = ProjectMarginDrillDownKey::decode((string) $input['drill_down_key']);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === ProjectMarginDrillDownKey::INVALID_KEY_MESSAGE) {
                throw new InvalidArgumentException(trans_message(ProjectMarginDrillDownKey::INVALID_KEY_MESSAGE));
            }

            throw $exception;
        }

        $this->assertDrillDownKeyMatchesFilters($filters, $key);

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(500, max(1, (int) ($input['per_page'] ?? 100)));
        $offset = ($page - 1) * $perPage;
        $permissions = $this->detailPermissions($user, $filters->organizationId);
        $query = $this->sourceRowsQuery($filters, $key);
        $total = (int) DB::query()->fromSub($query, 'project_margin_drill_total')->count();
        $rows = DB::query()
            ->fromSub($this->sourceRowsQuery($filters, $key), 'project_margin_drill')
            ->orderByDesc('recognition_date')
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->orderBy('source_line_id')
            ->offset($offset)
            ->limit($perPage)
            ->get();
        $items = $rows
            ->map(fn (object $row): ProjectMarginAttributionLine => $this->attributionLine($filters, $row, $permissions))
            ->all();

        return [
            'filters' => $filters->toArray(),
            'period' => $filters->period(),
            'group' => [
                'group_by' => $key->groupBy,
                'dimensions' => $key->dimensions,
            ],
            'summary' => $this->drillDownSummary($items),
            'items' => array_map(
                static fn (ProjectMarginAttributionLine $item): array => $item->toArray(),
                $items,
            ),
            'warnings' => $total === 0 ? [trans_message('budgeting.project_margin.warnings.drill_down_empty')] : [],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'budget_version' => $this->versionToArray($context['version']),
                'scenario' => $this->scenarioToArray($context['scenario']),
                'permissions' => $permissions,
            ],
        ];
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
            throw new DomainException(trans_message('budgeting.project_margin.errors.period_invalid'));
        }

        $scenario = $this->resolveScenario($organizationId, $input['scenario_uuid'] ?? null);
        $version = $this->resolveVersion($organizationId, $periodStart, $periodEnd, $scenario, $input['budget_version_uuid'] ?? null);
        $scenario = $version?->scenario ?? $scenario;

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
        $contractId = $this->resolveContractFilter($organizationId, $projectId, $input['contract_id'] ?? null);
        $counterpartyId = $this->resolveCounterpartyFilter($organizationId, $input['counterparty_id'] ?? null);
        $currency = $this->nullableCurrency($input['currency'] ?? null);

        return [
            'filters' => new ProjectMarginReportFilters(
                organizationId: $organizationId,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                budgetVersionId: $version instanceof BudgetVersion ? (int) $version->id : null,
                budgetVersionUuid: $version instanceof BudgetVersion ? (string) $version->uuid : null,
                scenarioId: $scenario instanceof BudgetScenario ? (int) $scenario->id : null,
                scenarioUuid: $scenario instanceof BudgetScenario ? (string) $scenario->uuid : null,
                projectId: $projectId,
                contractId: $contractId,
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

            if ($scenario instanceof BudgetScenario && (int) $version->scenario_id !== (int) $scenario->id) {
                throw new DomainException(trans_message('budgeting.project_margin.errors.scenario_mismatch'));
            }

            $this->assertVersionPeriodOverlaps($version, $periodStart, $periodEnd);

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
            throw new DomainException(trans_message('budgeting.project_margin.errors.version_period_mismatch'));
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

    private function resolveContractFilter(int $organizationId, ?int $projectId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $contractId = (int) $value;
        $exists = Contract::query()
            ->whereKey($contractId)
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, function (Builder $query) use ($projectId): void {
                $query->where(function (Builder $scope) use ($projectId): void {
                    $scope->where('project_id', $projectId)
                        ->orWhereExists(function (QueryBuilder $subQuery) use ($projectId): void {
                            $subQuery->select(DB::raw('1'))
                                ->from('contract_project')
                                ->whereColumn('contract_project.contract_id', 'contracts.id')
                                ->where('contract_project.project_id', $projectId);
                        });
                });
            })
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('budgeting.project_margin.errors.contract_not_found'));
        }

        return $contractId;
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
            $groups = ProjectMarginReportFilters::DEFAULT_GROUP_BY;
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (!is_string($group) || trim($group) === '') {
                continue;
            }

            $group = trim($group);
            if (!in_array($group, ProjectMarginReportFilters::ALLOWED_GROUP_BY, true)) {
                throw new DomainException(trans_message('budgeting.project_margin.errors.group_by_invalid'));
            }

            $normalized[] = $group;
        }

        if ($normalized === []) {
            $normalized = ProjectMarginReportFilters::DEFAULT_GROUP_BY;
        }

        if (!in_array(ProjectMarginReportFilters::GROUP_CURRENCY, $normalized, true)) {
            $normalized[] = ProjectMarginReportFilters::GROUP_CURRENCY;
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

    private function aggregateRows(ProjectMarginReportFilters $filters): Collection
    {
        return DB::query()
            ->fromSub($this->sourceRowsQuery($filters), 'project_margin_sources')
            ->selectRaw('period_month AS period_month')
            ->selectRaw('budget_article_id AS budget_article_id')
            ->selectRaw('responsibility_center_id AS responsibility_center_id')
            ->selectRaw('project_id AS project_id')
            ->selectRaw('contract_id AS contract_id')
            ->selectRaw('counterparty_id AS counterparty_id')
            ->selectRaw('currency AS currency')
            ->selectRaw("SUM(CASE WHEN component = 'plan' AND direction = 'revenue' THEN amount_without_vat ELSE 0 END) AS plan_revenue")
            ->selectRaw("SUM(CASE WHEN component = 'plan' AND direction = 'cost' THEN amount_without_vat ELSE 0 END) AS plan_cost")
            ->selectRaw("SUM(CASE WHEN component = 'plan' AND direction = 'revenue' THEN management_amount ELSE 0 END) AS forecast_revenue")
            ->selectRaw("SUM(CASE WHEN component = 'plan' AND direction = 'cost' THEN management_amount ELSE 0 END) AS forecast_cost")
            ->selectRaw("SUM(CASE WHEN component = 'actual' AND direction = 'revenue' THEN amount_without_vat ELSE 0 END) AS actual_revenue")
            ->selectRaw("SUM(CASE WHEN component = 'actual' AND direction = 'cost' THEN amount_without_vat ELSE 0 END) AS actual_cost")
            ->selectRaw("STRING_AGG(DISTINCT NULLIF(source_type, ''), ',') AS source_types")
            ->selectRaw("STRING_AGG(DISTINCT NULLIF(problem_flags, ''), ',') AS problem_flags")
            ->selectRaw("STRING_AGG(DISTINCT NULLIF(risk_flags, ''), ',') AS risk_flags")
            ->selectRaw('COUNT(*) AS source_rows_count')
            ->groupBy([
                'period_month',
                'budget_article_id',
                'responsibility_center_id',
                'project_id',
                'contract_id',
                'counterparty_id',
                'currency',
            ])
            ->get();
    }

    private function sourceRowsQuery(ProjectMarginReportFilters $filters, ?ProjectMarginDrillDownKey $key = null): QueryBuilder
    {
        $union = $this->planSourceQuery($filters);
        $union->unionAll($this->performanceActSourceQuery($filters));
        $union->unionAll($this->completedWorkSourceQuery($filters));
        $union->unionAll($this->paymentDocumentCostSourceQuery($filters));
        $union->unionAll($this->warehouseMovementSourceQuery($filters));
        $union->unionAll($this->timeEntrySourceQuery($filters));

        $query = DB::query()->fromSub($union, 'margin_sources');
        $this->applyNormalizedFilters($query, $filters);

        if ($key instanceof ProjectMarginDrillDownKey) {
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
            ->selectRaw("'plan' AS component")
            ->selectRaw("'revenue' AS direction")
            ->selectRaw('NULL::date AS period_month')
            ->selectRaw('NULL::date AS recognition_date')
            ->selectRaw('NULL::bigint AS budget_article_id')
            ->selectRaw('NULL::bigint AS responsibility_center_id')
            ->selectRaw('NULL::bigint AS project_id')
            ->selectRaw('NULL::bigint AS contract_id')
            ->selectRaw('NULL::bigint AS counterparty_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw('0::numeric AS amount_without_vat')
            ->selectRaw('0::numeric AS vat_amount')
            ->selectRaw('0::numeric AS management_amount')
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('NULL::date AS document_date')
            ->selectRaw('NULL::text AS source_title')
            ->selectRaw('NULL::text AS source_status')
            ->selectRaw("'confirmed' AS confirmation_status")
            ->selectRaw("'actual' AS freshness_status")
            ->selectRaw("'actual' AS reconciliation_status")
            ->selectRaw("'actual' AS quality_status")
            ->selectRaw("'' AS problem_flags")
            ->selectRaw("'' AS risk_flags")
            ->selectRaw('NULL::text AS href')
            ->selectRaw('NULL::text AS route_name')
            ->selectRaw("'prohelper_management' AS source_of_truth");
    }

    private function planSourceQuery(ProjectMarginReportFilters $filters): QueryBuilder
    {
        if ($filters->budgetVersionId === null) {
            return $this->emptySourceQuery();
        }

        $currencyExpression = "UPPER(COALESCE(NULLIF(budget_amounts.currency, ''), budget_lines.currency, 'RUB'))";
        $directionExpression = "CASE WHEN budget_articles.flow_direction IN ('income', 'inflow') THEN 'revenue' ELSE 'cost' END";
        $flags = $this->flagExpression([
            ['budget_lines.project_id IS NULL', ProjectMarginProblemFlag::MissingProject->value],
            ['budget_lines.contract_id IS NULL', ProjectMarginProblemFlag::MissingContract->value],
            ['budget_lines.counterparty_id IS NULL', ProjectMarginProblemFlag::MissingCounterparty->value],
        ]);

        return DB::table('budget_amounts')
            ->join('budget_lines', 'budget_amounts.budget_line_id', '=', 'budget_lines.id')
            ->join('budget_versions', 'budget_lines.budget_version_id', '=', 'budget_versions.id')
            ->join('budget_articles', 'budget_lines.budget_article_id', '=', 'budget_articles.id')
            ->where('budget_lines.budget_version_id', $filters->budgetVersionId)
            ->whereNull('budget_lines.deleted_at')
            ->whereIn('budget_articles.flow_direction', ['income', 'inflow', 'expense', 'outflow'])
            ->whereBetween('budget_amounts.month', [$filters->periodStartMonth(), $filters->periodEndMonth()])
            ->selectRaw("'budget_amount' AS source_type")
            ->selectRaw('budget_amounts.id AS source_id')
            ->selectRaw('budget_lines.id AS source_line_id')
            ->selectRaw("'plan' AS component")
            ->selectRaw("{$directionExpression} AS direction")
            ->selectRaw("DATE_TRUNC('month', budget_amounts.month)::date AS period_month")
            ->selectRaw('budget_amounts.month::date AS recognition_date')
            ->selectRaw('budget_lines.budget_article_id AS budget_article_id')
            ->selectRaw('budget_lines.responsibility_center_id AS responsibility_center_id')
            ->selectRaw('budget_lines.project_id AS project_id')
            ->selectRaw('budget_lines.contract_id AS contract_id')
            ->selectRaw('budget_lines.counterparty_id AS counterparty_id')
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw('COALESCE(budget_amounts.plan_amount, 0) AS amount_without_vat')
            ->selectRaw('0::numeric AS vat_amount')
            ->selectRaw('COALESCE(budget_amounts.forecast_amount, 0) AS management_amount')
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('budget_amounts.month::date AS document_date')
            ->selectRaw('budget_lines.description AS source_title')
            ->selectRaw('budget_versions.status AS source_status')
            ->selectRaw("'confirmed' AS confirmation_status")
            ->selectRaw("'actual' AS freshness_status")
            ->selectRaw("'actual' AS reconciliation_status")
            ->selectRaw("CASE WHEN {$flags} <> '' THEN 'attention' ELSE 'actual' END AS quality_status")
            ->selectRaw("{$flags} AS problem_flags")
            ->selectRaw("'' AS risk_flags")
            ->selectRaw("'/budgeting?tab=versions' AS href")
            ->selectRaw("'admin.budgeting.budgets.show' AS route_name")
            ->selectRaw("'prohelper_management_budget' AS source_of_truth");
    }

    private function performanceActSourceQuery(ProjectMarginReportFilters $filters): QueryBuilder
    {
        $dateExpression = 'COALESCE(contract_performance_acts.approval_date, contract_performance_acts.act_date)';
        $projectExpression = 'COALESCE(contract_performance_acts.project_id, contracts.project_id)';
        $amountExpression = 'COALESCE(performance_act_lines.amount, contract_performance_acts.amount, 0)';
        $flags = $this->flagExpression([
            ['budget_articles.id IS NULL', ProjectMarginProblemFlag::MissingBudgetArticle->value],
            ['responsibility_centers.id IS NULL', ProjectMarginProblemFlag::MissingResponsibilityCenter->value],
            ["{$projectExpression} IS NULL", ProjectMarginProblemFlag::MissingProject->value],
            ['contracts.id IS NULL', ProjectMarginProblemFlag::MissingContract->value],
            ['contracts.contractor_id IS NULL', ProjectMarginProblemFlag::MissingCounterparty->value],
            ['contract_performance_acts.act_document_number IS NULL', ProjectMarginProblemFlag::MissingSourceDocument->value],
        ]);
        $riskFlags = $this->flagExpression([
            ["NOT EXISTS (
                SELECT 1
                FROM payment_documents pd
                WHERE pd.source_id = contract_performance_acts.id
                    AND pd.organization_id = contracts.organization_id
                    AND pd.direction = 'incoming'
                    AND COALESCE(pd.paid_amount, 0) > 0
                    AND pd.deleted_at IS NULL
            )", ProjectMarginRiskFlag::AccrualWithoutPayment->value],
        ]);

        return DB::table('contract_performance_acts')
            ->join('contracts', 'contract_performance_acts.contract_id', '=', 'contracts.id')
            ->leftJoin('performance_act_lines', 'performance_act_lines.performance_act_id', '=', 'contract_performance_acts.id')
            ->leftJoin('budget_articles', DB::raw('1'), '=', DB::raw('0'))
            ->leftJoin('responsibility_centers', DB::raw('1'), '=', DB::raw('0'))
            ->where('contracts.organization_id', $filters->organizationId)
            ->whereNull('contracts.deleted_at')
            ->where('contract_performance_acts.is_approved', true)
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("'contract_performance_act' AS source_type")
            ->selectRaw('contract_performance_acts.id AS source_id')
            ->selectRaw('performance_act_lines.id AS source_line_id')
            ->selectRaw("'actual' AS component")
            ->selectRaw("'revenue' AS direction")
            ->selectRaw("DATE_TRUNC('month', {$dateExpression})::date AS period_month")
            ->selectRaw("{$dateExpression}::date AS recognition_date")
            ->selectRaw('NULL::bigint AS budget_article_id')
            ->selectRaw('NULL::bigint AS responsibility_center_id')
            ->selectRaw("{$projectExpression} AS project_id")
            ->selectRaw('contract_performance_acts.contract_id AS contract_id')
            ->selectRaw('contracts.contractor_id AS counterparty_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw("{$amountExpression} AS amount_without_vat")
            ->selectRaw('0::numeric AS vat_amount')
            ->selectRaw("{$amountExpression} AS management_amount")
            ->selectRaw('contract_performance_acts.act_document_number AS source_document_number')
            ->selectRaw('contract_performance_acts.act_date AS document_date')
            ->selectRaw('COALESCE(performance_act_lines.title, contract_performance_acts.description) AS source_title')
            ->selectRaw("'approved' AS source_status")
            ->selectRaw("'confirmed' AS confirmation_status")
            ->selectRaw("'actual' AS freshness_status")
            ->selectRaw("CASE WHEN {$riskFlags} <> '' THEN 'attention' ELSE 'actual' END AS reconciliation_status")
            ->selectRaw("CASE WHEN {$flags} <> '' OR {$riskFlags} <> '' THEN 'attention' ELSE 'actual' END AS quality_status")
            ->selectRaw("{$flags} AS problem_flags")
            ->selectRaw("{$riskFlags} AS risk_flags")
            ->selectRaw("CONCAT('/reports/act-reports?act_id=', contract_performance_acts.id) AS href")
            ->selectRaw("'admin.act_reports.show' AS route_name")
            ->selectRaw("'prohelper_management_act' AS source_of_truth");
    }

    private function completedWorkSourceQuery(ProjectMarginReportFilters $filters): QueryBuilder
    {
        $amountExpression = 'COALESCE(completed_works.total_amount, completed_works.quantity * COALESCE(completed_works.price, 0), 0)';
        $flags = $this->flagExpression([
            ['budget_articles.id IS NULL', ProjectMarginProblemFlag::MissingBudgetArticle->value],
            ['responsibility_centers.id IS NULL', ProjectMarginProblemFlag::MissingResponsibilityCenter->value],
            ['completed_works.project_id IS NULL', ProjectMarginProblemFlag::MissingProject->value],
            ['completed_works.contract_id IS NULL', ProjectMarginProblemFlag::MissingContract->value],
            ['completed_works.contractor_id IS NULL', ProjectMarginProblemFlag::MissingCounterparty->value],
            ['completed_works.id IS NOT NULL', ProjectMarginProblemFlag::MissingSourceDocument->value],
        ]);
        $riskFlags = $this->flagExpression([
            ['completed_works.id IS NOT NULL', ProjectMarginRiskFlag::EdoPending->value],
        ]);

        return DB::table('completed_works')
            ->leftJoin('budget_articles', DB::raw('1'), '=', DB::raw('0'))
            ->leftJoin('responsibility_centers', DB::raw('1'), '=', DB::raw('0'))
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
            ->selectRaw("'actual' AS component")
            ->selectRaw("'revenue' AS direction")
            ->selectRaw("DATE_TRUNC('month', completed_works.completion_date)::date AS period_month")
            ->selectRaw('completed_works.completion_date::date AS recognition_date')
            ->selectRaw('NULL::bigint AS budget_article_id')
            ->selectRaw('NULL::bigint AS responsibility_center_id')
            ->selectRaw('completed_works.project_id AS project_id')
            ->selectRaw('completed_works.contract_id AS contract_id')
            ->selectRaw('completed_works.contractor_id AS counterparty_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw("{$amountExpression} AS amount_without_vat")
            ->selectRaw('0::numeric AS vat_amount')
            ->selectRaw("{$amountExpression} AS management_amount")
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('completed_works.completion_date::date AS document_date')
            ->selectRaw('completed_works.notes AS source_title')
            ->selectRaw('completed_works.status AS source_status')
            ->selectRaw("'pending' AS confirmation_status")
            ->selectRaw("'actual' AS freshness_status")
            ->selectRaw("'attention' AS reconciliation_status")
            ->selectRaw("'attention' AS quality_status")
            ->selectRaw("{$flags} AS problem_flags")
            ->selectRaw("{$riskFlags} AS risk_flags")
            ->selectRaw("CONCAT('/completed-works?work_id=', completed_works.id) AS href")
            ->selectRaw("'admin.completed_works.show' AS route_name")
            ->selectRaw("'prohelper_management_work' AS source_of_truth");
    }

    private function paymentDocumentCostSourceQuery(ProjectMarginReportFilters $filters): QueryBuilder
    {
        $dateExpression = 'COALESCE(payment_documents.approved_at::date, payment_documents.document_date, payment_documents.due_date)';
        $contractExpression = $this->paymentDocumentContractExpression();
        $counterpartyExpression = 'COALESCE(payment_documents.contractor_id, payment_documents.payee_contractor_id, payment_documents.payer_contractor_id)';
        $amountExpression = 'GREATEST(COALESCE(payment_documents.amount_without_vat, payment_documents.amount - COALESCE(payment_documents.vat_amount, 0), payment_documents.amount), 0)';
        $currencyExpression = "UPPER(COALESCE(NULLIF(payment_documents.currency, ''), 'RUB'))";
        $flags = $this->flagExpression([
            ['payment_documents.budget_article_id IS NULL', ProjectMarginProblemFlag::MissingBudgetArticle->value],
            ['payment_documents.responsibility_center_id IS NULL', ProjectMarginProblemFlag::MissingResponsibilityCenter->value],
            ['payment_documents.project_id IS NULL', ProjectMarginProblemFlag::MissingProject->value],
            ["{$contractExpression} IS NULL", ProjectMarginProblemFlag::MissingContract->value],
            ["{$counterpartyExpression} IS NULL", ProjectMarginProblemFlag::MissingCounterparty->value],
            ['payment_documents.document_number IS NULL', ProjectMarginProblemFlag::MissingSourceDocument->value],
        ]);
        $riskFlags = $this->flagExpression([
            ['COALESCE(payment_documents.paid_amount, 0) <= 0', ProjectMarginRiskFlag::AccrualWithoutPayment->value],
        ]);

        return DB::table('payment_documents')
            ->leftJoin('budget_articles', 'payment_documents.budget_article_id', '=', 'budget_articles.id')
            ->where('payment_documents.organization_id', $filters->organizationId)
            ->whereNull('payment_documents.deleted_at')
            ->whereIn('payment_documents.status', $this->actualCostStatusValues())
            ->where(function (QueryBuilder $query): void {
                $query->where('payment_documents.direction', InvoiceDirection::OUTGOING->value)
                    ->orWhereIn('budget_articles.flow_direction', ['expense', 'outflow']);
            })
            ->whereBetween(DB::raw($dateExpression), [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("'payment_document' AS source_type")
            ->selectRaw('payment_documents.id AS source_id')
            ->selectRaw('payment_documents.id AS source_line_id')
            ->selectRaw("'actual' AS component")
            ->selectRaw("'cost' AS direction")
            ->selectRaw("DATE_TRUNC('month', {$dateExpression})::date AS period_month")
            ->selectRaw("{$dateExpression}::date AS recognition_date")
            ->selectRaw('payment_documents.budget_article_id AS budget_article_id')
            ->selectRaw('payment_documents.responsibility_center_id AS responsibility_center_id')
            ->selectRaw('payment_documents.project_id AS project_id')
            ->selectRaw("{$contractExpression} AS contract_id")
            ->selectRaw("{$counterpartyExpression} AS counterparty_id")
            ->selectRaw("{$currencyExpression} AS currency")
            ->selectRaw("{$amountExpression} AS amount_without_vat")
            ->selectRaw('COALESCE(payment_documents.vat_amount, 0) AS vat_amount')
            ->selectRaw("{$amountExpression} AS management_amount")
            ->selectRaw('payment_documents.document_number AS source_document_number')
            ->selectRaw('payment_documents.document_date AS document_date')
            ->selectRaw('COALESCE(payment_documents.description, payment_documents.payment_purpose) AS source_title')
            ->selectRaw('payment_documents.status AS source_status')
            ->selectRaw("'confirmed' AS confirmation_status")
            ->selectRaw("'actual' AS freshness_status")
            ->selectRaw("CASE WHEN {$riskFlags} <> '' THEN 'attention' ELSE 'actual' END AS reconciliation_status")
            ->selectRaw("CASE WHEN {$flags} <> '' OR {$riskFlags} <> '' THEN 'attention' ELSE 'actual' END AS quality_status")
            ->selectRaw("{$flags} AS problem_flags")
            ->selectRaw("{$riskFlags} AS risk_flags")
            ->selectRaw("CONCAT('/payments?document_id=', payment_documents.id) AS href")
            ->selectRaw("'admin.payments.documents.show' AS route_name")
            ->selectRaw("'prohelper_management_payment_document' AS source_of_truth");
    }

    private function warehouseMovementSourceQuery(ProjectMarginReportFilters $filters): QueryBuilder
    {
        $amountExpression = 'GREATEST(COALESCE(warehouse_movements.quantity, 0) * COALESCE(warehouse_movements.price, 0), 0)';
        $flags = $this->flagExpression([
            ['warehouse_movements.project_id IS NULL', ProjectMarginProblemFlag::MissingProject->value],
            ['warehouse_movements.id IS NOT NULL', ProjectMarginProblemFlag::MissingBudgetArticle->value],
            ['warehouse_movements.id IS NOT NULL', ProjectMarginProblemFlag::MissingResponsibilityCenter->value],
            ['warehouse_movements.id IS NOT NULL', ProjectMarginProblemFlag::MissingContract->value],
            ['warehouse_movements.id IS NOT NULL', ProjectMarginProblemFlag::MissingCounterparty->value],
        ]);
        $riskFlags = $this->flagExpression([
            ['warehouse_movements.id IS NOT NULL', ProjectMarginRiskFlag::IndirectCostPolicySensitive->value],
        ]);

        return DB::table('warehouse_movements')
            ->where('warehouse_movements.organization_id', $filters->organizationId)
            ->whereIn('warehouse_movements.movement_type', ['write_off', 'transfer_out'])
            ->whereBetween(DB::raw('warehouse_movements.movement_date::date'), [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("'warehouse_movement' AS source_type")
            ->selectRaw('warehouse_movements.id AS source_id')
            ->selectRaw('warehouse_movements.id AS source_line_id')
            ->selectRaw("'actual' AS component")
            ->selectRaw("'cost' AS direction")
            ->selectRaw("DATE_TRUNC('month', warehouse_movements.movement_date)::date AS period_month")
            ->selectRaw('warehouse_movements.movement_date::date AS recognition_date')
            ->selectRaw('NULL::bigint AS budget_article_id')
            ->selectRaw('NULL::bigint AS responsibility_center_id')
            ->selectRaw('warehouse_movements.project_id AS project_id')
            ->selectRaw('NULL::bigint AS contract_id')
            ->selectRaw('NULL::bigint AS counterparty_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw("{$amountExpression} AS amount_without_vat")
            ->selectRaw('0::numeric AS vat_amount')
            ->selectRaw("{$amountExpression} AS management_amount")
            ->selectRaw('warehouse_movements.document_number AS source_document_number')
            ->selectRaw('warehouse_movements.movement_date::date AS document_date')
            ->selectRaw('warehouse_movements.reason AS source_title')
            ->selectRaw('warehouse_movements.movement_type AS source_status')
            ->selectRaw("'confirmed' AS confirmation_status")
            ->selectRaw("'actual' AS freshness_status")
            ->selectRaw("'attention' AS reconciliation_status")
            ->selectRaw("'attention' AS quality_status")
            ->selectRaw("{$flags} AS problem_flags")
            ->selectRaw("{$riskFlags} AS risk_flags")
            ->selectRaw("CONCAT('/warehouse?movement_id=', warehouse_movements.id) AS href")
            ->selectRaw("'admin.warehouse.movements.show' AS route_name")
            ->selectRaw("'prohelper_management_warehouse' AS source_of_truth");
    }

    private function timeEntrySourceQuery(ProjectMarginReportFilters $filters): QueryBuilder
    {
        $amountExpression = 'GREATEST(COALESCE(time_entries.hours_worked, 0) * COALESCE(time_entries.hourly_rate, 0), 0)';
        $flags = $this->flagExpression([
            ['time_entries.project_id IS NULL', ProjectMarginProblemFlag::MissingProject->value],
            ['time_entries.id IS NOT NULL', ProjectMarginProblemFlag::MissingBudgetArticle->value],
            ['time_entries.id IS NOT NULL', ProjectMarginProblemFlag::MissingResponsibilityCenter->value],
            ['time_entries.id IS NOT NULL', ProjectMarginProblemFlag::MissingContract->value],
            ['time_entries.id IS NOT NULL', ProjectMarginProblemFlag::MissingCounterparty->value],
            ['time_entries.id IS NOT NULL', ProjectMarginProblemFlag::MissingSourceDocument->value],
        ]);
        $riskFlags = $this->flagExpression([
            ['time_entries.id IS NOT NULL', ProjectMarginRiskFlag::IndirectCostPolicySensitive->value],
        ]);

        return DB::table('time_entries')
            ->where('time_entries.organization_id', $filters->organizationId)
            ->whereNull('time_entries.deleted_at')
            ->where('time_entries.status', 'approved')
            ->whereBetween('time_entries.work_date', [$filters->periodStart, $filters->periodEnd])
            ->whereRaw("{$amountExpression} > 0")
            ->selectRaw("'time_entry' AS source_type")
            ->selectRaw('time_entries.id AS source_id')
            ->selectRaw('time_entries.id AS source_line_id')
            ->selectRaw("'actual' AS component")
            ->selectRaw("'cost' AS direction")
            ->selectRaw("DATE_TRUNC('month', time_entries.work_date)::date AS period_month")
            ->selectRaw('time_entries.work_date::date AS recognition_date')
            ->selectRaw('NULL::bigint AS budget_article_id')
            ->selectRaw('NULL::bigint AS responsibility_center_id')
            ->selectRaw('time_entries.project_id AS project_id')
            ->selectRaw('NULL::bigint AS contract_id')
            ->selectRaw('NULL::bigint AS counterparty_id')
            ->selectRaw("'RUB' AS currency")
            ->selectRaw("{$amountExpression} AS amount_without_vat")
            ->selectRaw('0::numeric AS vat_amount')
            ->selectRaw("{$amountExpression} AS management_amount")
            ->selectRaw('NULL::text AS source_document_number')
            ->selectRaw('time_entries.work_date::date AS document_date')
            ->selectRaw('time_entries.title AS source_title')
            ->selectRaw('time_entries.status AS source_status')
            ->selectRaw("'confirmed' AS confirmation_status")
            ->selectRaw("'actual' AS freshness_status")
            ->selectRaw("'attention' AS reconciliation_status")
            ->selectRaw("'attention' AS quality_status")
            ->selectRaw("{$flags} AS problem_flags")
            ->selectRaw("{$riskFlags} AS risk_flags")
            ->selectRaw("CONCAT('/time-tracking?entry_id=', time_entries.id) AS href")
            ->selectRaw("'admin.time_tracking.entries.show' AS route_name")
            ->selectRaw("'prohelper_management_labor' AS source_of_truth");
    }

    private function applyNormalizedFilters(QueryBuilder $query, ProjectMarginReportFilters $filters): void
    {
        $query
            ->when($filters->projectId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('project_id', $filters->projectId))
            ->when($filters->contractId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('contract_id', $filters->contractId))
            ->when($filters->counterpartyId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('counterparty_id', $filters->counterpartyId))
            ->when($filters->budgetArticleId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('budget_article_id', $filters->budgetArticleId))
            ->when($filters->responsibilityCenterId !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('responsibility_center_id', $filters->responsibilityCenterId))
            ->when($filters->currency !== null, fn (QueryBuilder $builder): QueryBuilder => $builder->where('currency', $filters->currency));
    }

    private function applyDrillDownDimensions(QueryBuilder $query, ProjectMarginDrillDownKey $key): void
    {
        if ($key->hasDimension(ProjectMarginReportFilters::GROUP_MONTH)) {
            $month = (string) $key->value(ProjectMarginReportFilters::GROUP_MONTH);
            $query->where('period_month', CarbonImmutable::parse($month . '-01')->toDateString());
        }

        $this->applyNullableDrillDimension($query, $key, ProjectMarginReportFilters::GROUP_BUDGET_ARTICLE, 'budget_article_id');
        $this->applyNullableDrillDimension($query, $key, ProjectMarginReportFilters::GROUP_RESPONSIBILITY_CENTER, 'responsibility_center_id');
        $this->applyNullableDrillDimension($query, $key, ProjectMarginReportFilters::GROUP_PROJECT, 'project_id');
        $this->applyNullableDrillDimension($query, $key, ProjectMarginReportFilters::GROUP_CONTRACT, 'contract_id');
        $this->applyNullableDrillDimension($query, $key, ProjectMarginReportFilters::GROUP_COUNTERPARTY, 'counterparty_id');

        if ($key->hasDimension(ProjectMarginReportFilters::GROUP_CURRENCY)) {
            $query->where('currency', (string) $key->value(ProjectMarginReportFilters::GROUP_CURRENCY));
        }
    }

    private function applyNullableDrillDimension(
        QueryBuilder $query,
        ProjectMarginDrillDownKey $key,
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

    private function assertDrillDownKeyMatchesFilters(ProjectMarginReportFilters $filters, ProjectMarginDrillDownKey $key): void
    {
        foreach ($key->groupBy as $group) {
            if (!in_array($group, ProjectMarginReportFilters::ALLOWED_GROUP_BY, true)) {
                throw new InvalidArgumentException(trans_message('budgeting.project_margin.errors.drill_down_key_invalid'));
            }
        }

        if ($filters->currency !== null && $key->hasDimension(ProjectMarginReportFilters::GROUP_CURRENCY)) {
            $currency = (string) $key->value(ProjectMarginReportFilters::GROUP_CURRENCY);

            if ($currency !== $filters->currency) {
                throw new InvalidArgumentException(trans_message('budgeting.project_margin.errors.drill_down_key_invalid'));
            }
        }
    }

    /**
     * @param list<ProjectMarginSourceAggregate> $aggregates
     */
    private function dimensionsForAggregates(ProjectMarginReportFilters $filters, array $aggregates): ProjectMarginDimensions
    {
        $articleIds = [];
        $centerIds = [];
        $projectIds = [];
        $contractIds = [];
        $counterpartyIds = [];

        foreach ($aggregates as $aggregate) {
            $this->collectId($articleIds, $aggregate->budgetArticleId);
            $this->collectId($centerIds, $aggregate->responsibilityCenterId);
            $this->collectId($projectIds, $aggregate->projectId);
            $this->collectId($contractIds, $aggregate->contractId);
            $this->collectId($counterpartyIds, $aggregate->counterpartyId);
        }

        return new ProjectMarginDimensions(
            articles: BudgetArticle::query()
                ->where('organization_id', $filters->organizationId)
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
                ->all(),
            responsibilityCenters: ResponsibilityCenter::query()
                ->where('organization_id', $filters->organizationId)
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
                ->all(),
            projects: Project::query()
                ->whereIn('id', $projectIds)
                ->accessibleByOrganization($filters->organizationId)
                ->get(['id', 'name', 'status'])
                ->mapWithKeys(static fn (Project $project): array => [
                    (int) $project->id => [
                        'id' => $project->id,
                        'name' => $project->name,
                        'status' => $project->status,
                    ],
                ])
                ->all(),
            contracts: Contract::query()
                ->where('organization_id', $filters->organizationId)
                ->whereIn('id', $contractIds)
                ->get(['id', 'number', 'date', 'status', 'subject'])
                ->mapWithKeys(static fn (Contract $contract): array => [
                    (int) $contract->id => [
                        'id' => $contract->id,
                        'number' => $contract->number,
                        'date' => $contract->date,
                        'status' => $contract->status,
                        'subject' => $contract->subject,
                    ],
                ])
                ->all(),
            counterparties: Contractor::query()
                ->where('organization_id', $filters->organizationId)
                ->whereIn('id', $counterpartyIds)
                ->get(['id', 'name', 'inn'])
                ->mapWithKeys(static fn (Contractor $contractor): array => [
                    (int) $contractor->id => [
                        'id' => $contractor->id,
                        'name' => $contractor->name,
                        'inn' => $contractor->inn,
                    ],
                ])
                ->all(),
        );
    }

    private function collectId(array &$ids, ?int $id): void
    {
        if ($id !== null) {
            $ids[$id] = $id;
        }
    }

    /**
     * @return array{0:list<array<string, mixed>>,1:list<string>}
     */
    private function sourcesCoverage(ProjectMarginReportFilters $filters): array
    {
        $counts = DB::query()
            ->fromSub($this->sourceRowsQuery($filters), 'project_margin_coverage')
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

        $warnings = [];
        if ($filters->budgetVersionId === null) {
            $warnings[] = trans_message('budgeting.project_margin.warnings.plan_unavailable');
        }

        $hasProblemRows = array_reduce($counts, static fn (bool $carry, array $row): bool => (
            $carry || (int) ($row['problem_rows_count'] ?? 0) > 0
        ), false);
        if ($hasProblemRows) {
            $warnings[] = trans_message('budgeting.project_margin.warnings.partial_analytics');
        }

        return [[
            $this->coverageItem('budget_amount', $filters->budgetVersionId !== null, $counts, 'budget_amounts'),
            $this->coverageItem('contract_performance_act', true, $counts, 'contract_performance_acts'),
            $this->coverageItem('completed_work', true, $counts, 'completed_works'),
            $this->coverageItem('payment_document', true, $counts, 'payment_documents'),
            $this->coverageItem('warehouse_movement', true, $counts, 'warehouse_movements'),
            $this->coverageItem('time_entry', true, $counts, 'time_entries'),
        ], $warnings];
    }

    private function coverageItem(string $sourceType, bool $available, array $counts, string $translationKey): array
    {
        return [
            'source_type' => $sourceType,
            'available' => $available,
            'included_source_rows' => (int) ($counts[$sourceType]['rows_count'] ?? 0),
            'problem_rows_count' => (int) ($counts[$sourceType]['problem_rows_count'] ?? 0),
            'coverage_note' => trans_message("budgeting.project_margin.sources.{$translationKey}"),
        ];
    }

    private function attributionLine(ProjectMarginReportFilters $filters, object $row, array $permissions): ProjectMarginAttributionLine
    {
        $sourceType = (string) $row->source_type;
        $canViewSource = $this->canViewSource($sourceType, $permissions);
        $problemFlags = $this->csv((string) ($row->problem_flags ?? ''));
        if (!$canViewSource) {
            $problemFlags[] = ProjectMarginProblemFlag::HiddenByPermissions->value;
        }
        $problemFlags = array_values(array_unique($problemFlags));
        $riskFlags = $this->csv((string) ($row->risk_flags ?? ''));
        $sourceId = $this->nullableScalar($row->source_id ?? null);
        $sourceLineId = $this->nullableScalar($row->source_line_id ?? null);
        $qualityStatus = $canViewSource ? (string) $row->quality_status : self::QUALITY_PARTIAL;

        return new ProjectMarginAttributionLine(
            lineId: $sourceType . ':' . (string) ($sourceId ?? 'hidden') . ':' . (string) ($sourceLineId ?? 'line'),
            component: (string) $row->component,
            direction: (string) $row->direction,
            organizationId: $filters->organizationId,
            projectId: $this->nullableInt($row->project_id ?? null),
            stageId: null,
            contractId: $this->nullableInt($row->contract_id ?? null),
            actId: $canViewSource && $sourceType === 'contract_performance_act' ? $this->nullableInt($row->source_id ?? null) : null,
            budgetArticleId: $this->nullableString($row->budget_article_id ?? null),
            responsibilityCenterId: $this->nullableString($row->responsibility_center_id ?? null),
            counterpartyId: $this->nullableInt($row->counterparty_id ?? null),
            period: CarbonImmutable::parse((string) $row->period_month)->format('Y-m'),
            recognitionDate: CarbonImmutable::parse((string) $row->recognition_date)->toDateString(),
            recognitionEvent: $this->recognitionEvent($sourceType, (string) $row->component, (string) $row->direction),
            attributionRule: $this->attributionRule($sourceType),
            currency: mb_strtoupper((string) $row->currency),
            amountWithoutVat: round((float) $row->amount_without_vat, 2),
            vatAmount: round((float) $row->vat_amount, 2),
            managementAmount: round((float) $row->management_amount, 2),
            managementCurrency: mb_strtoupper((string) $row->currency),
            sourceType: $sourceType,
            sourceId: $canViewSource ? $sourceId : null,
            sourceLineId: $canViewSource ? $sourceLineId : null,
            sourceDocumentNumber: $canViewSource ? $this->nullableString($row->source_document_number ?? null) : null,
            documentDate: $canViewSource ? $this->nullableString($row->document_date ?? null) : null,
            source: [
                'type' => $sourceType,
                'label' => $canViewSource
                    ? $this->sourceLabel($sourceType)
                    : trans_message('budgeting.project_margin.sources.hidden_by_permission'),
                'title' => $canViewSource ? $this->nullableString($row->source_title ?? null) : null,
                'source_of_truth' => (string) $row->source_of_truth,
            ],
            confirmation: [
                'status' => (string) $row->confirmation_status,
                'label' => $this->statusLabel((string) $row->confirmation_status),
            ],
            freshness: [
                'status' => (string) $row->freshness_status,
                'label' => $this->statusLabel((string) $row->freshness_status),
            ],
            reconciliation: [
                'status' => (string) $row->reconciliation_status,
                'label' => $this->statusLabel((string) $row->reconciliation_status),
            ],
            qualityStatus: $qualityStatus,
            confirmationStatus: (string) $row->confirmation_status,
            freshnessStatus: (string) $row->freshness_status,
            reconciliationStatus: (string) $row->reconciliation_status,
            problemFlags: $problemFlags,
            riskFlags: $riskFlags,
            drillDown: $this->drillDownRoute($row, $canViewSource),
            permissions: [
                'can_view_source' => $canViewSource,
            ],
        );
    }

    /**
     * @param list<ProjectMarginAttributionLine> $items
     */
    private function drillDownSummary(array $items): array
    {
        $revenue = 0.0;
        $cost = 0.0;
        $plan = 0.0;
        $forecast = 0.0;
        $currencies = [];
        $problemFlags = [];
        $riskFlags = [];
        $hidden = 0;

        foreach ($items as $item) {
            if ($item->component === 'plan') {
                $plan += $item->amountWithoutVat;
                $forecast += (float) ($item->managementAmount ?? 0.0);
            } elseif ($item->direction === 'revenue') {
                $revenue += $item->amountWithoutVat;
            } else {
                $cost += $item->amountWithoutVat;
            }

            if (($item->permissions['can_view_source'] ?? false) !== true) {
                $hidden++;
            }

            $currencies[$item->currency] = true;
            $this->rememberValues($problemFlags, $item->problemFlags);
            $this->rememberValues($riskFlags, $item->riskFlags);
        }

        return [
            'items_count' => count($items),
            'actual_revenue' => round($revenue, 2),
            'actual_cost' => round($cost, 2),
            'actual_gross_margin' => round($revenue - $cost, 2),
            'plan_amount' => round($plan, 2),
            'forecast_amount' => round($forecast, 2),
            'hidden_items_count' => $hidden,
            'problem_flags' => array_keys($problemFlags),
            'risk_flags' => array_keys($riskFlags),
            'currencies' => array_keys($currencies),
        ];
    }

    private function detailPermissions(?User $user, int $organizationId): array
    {
        return [
            'budget_amount' => $this->canAny($user, ['budgeting.budgets.view'], $organizationId),
            'contract_performance_act' => $this->canAny($user, ['act_reports.view', 'contracts.view'], $organizationId),
            'completed_work' => $this->canAny($user, ['completed_works.view'], $organizationId),
            'payment_document' => $this->canAny($user, ['payments.invoice.view', 'payments.invoice.view_all'], $organizationId),
            'warehouse_movement' => $this->canAny($user, ['warehouse.view'], $organizationId),
            'time_entry' => $this->canAny($user, ['time_tracking.view'], $organizationId),
        ];
    }

    /**
     * @param list<string> $permissions
     */
    private function canAny(?User $user, array $permissions, int $organizationId): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($this->authorization->can($user, $permission, ['organization_id' => $organizationId])) {
                return true;
            }
        }

        return false;
    }

    private function canViewSource(string $sourceType, array $permissions): bool
    {
        return ($permissions[$sourceType] ?? false) === true;
    }

    private function drillDownRoute(object $row, bool $canViewSource): array
    {
        if (!$canViewSource) {
            return [
                'available' => false,
                'message' => trans_message('budgeting.project_margin.sources.hidden_by_permission'),
            ];
        }

        return [
            'available' => true,
            'href' => $this->safeHref($row->href ?? null),
            'route_hint' => [
                'name' => $this->nullableString($row->route_name ?? null),
                'params' => [
                    'id' => $this->nullableScalar($row->source_id ?? null),
                    'line_id' => $this->nullableScalar($row->source_line_id ?? null),
                    'project_id' => $this->nullableInt($row->project_id ?? null),
                    'contract_id' => $this->nullableInt($row->contract_id ?? null),
                ],
            ],
        ];
    }

    private function safeHref(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $href = trim($value);

        return str_starts_with($href, '/') && !str_starts_with($href, '//') ? $href : null;
    }

    private function flagExpression(array $conditions): string
    {
        $parts = array_map(
            static fn (array $condition): string => "CASE WHEN {$condition[0]} THEN '{$condition[1]}' END",
            $conditions,
        );

        return "CONCAT_WS(',', " . implode(', ', $parts) . ')';
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

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
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

    private function sourceLabel(string $sourceType): string
    {
        return trans_message("budgeting.project_margin.source_labels.{$sourceType}");
    }

    private function statusLabel(string $status): string
    {
        return trans_message("budgeting.project_margin.statuses.{$status}");
    }

    private function recognitionEvent(string $sourceType, string $component, string $direction): string
    {
        return match ($sourceType) {
            'budget_amount' => $direction === 'revenue' ? 'planned_revenue' : 'planned_cost',
            'contract_performance_act', 'completed_work' => 'management_revenue_recognition',
            default => $component === 'actual' ? 'management_cost_recognition' : 'management_plan_recognition',
        };
    }

    private function attributionRule(string $sourceType): string
    {
        return match ($sourceType) {
            'budget_amount' => 'budget_version_month_article_project_contract',
            'contract_performance_act' => 'approved_act_line_or_act_total',
            'completed_work' => 'confirmed_work_without_act_attention',
            'payment_document' => 'approved_outgoing_document_without_vat',
            'warehouse_movement' => 'project_write_off_material_cost',
            'time_entry' => 'approved_hours_by_hourly_rate',
            default => 'management_source_line',
        };
    }

    private function rememberValues(array &$target, array $values): void
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $target[$value] = true;
            }
        }
    }

    private function scenarioToArray(mixed $scenario): ?array
    {
        if (!$scenario instanceof BudgetScenario) {
            return null;
        }

        return [
            'id' => $scenario->uuid,
            'code' => $scenario->code,
            'name' => $scenario->name,
            'scenario_type' => $scenario->scenario_type,
        ];
    }

    private function versionToArray(mixed $version): ?array
    {
        if (!$version instanceof BudgetVersion) {
            return null;
        }

        return [
            'id' => $version->uuid,
            'name' => $version->name,
            'budget_kind' => $version->budget_kind,
            'version_number' => $version->version_number,
            'status' => $version->status,
        ];
    }
}
