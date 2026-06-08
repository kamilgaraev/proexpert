<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BudgetVersionService
{
    public function __construct(
        private readonly BudgetCatalogService $catalogService,
        private readonly BudgetWorkflowService $workflowService
    ) {
    }

    /**
     * @return array{paginator:LengthAwarePaginator, summary:array<string, mixed>}
     */
    public function index(User $user, array $filters): array
    {
        $organizationId = $this->catalogService->organizationId($user, $filters);
        $query = BudgetVersion::query()
            ->where('organization_id', $organizationId)
            ->with(['period', 'scenario'])
            ->when($filters['budget_kind'] ?? null, fn (Builder $scope, string $value) => $scope->where('budget_kind', $value))
            ->when($filters['period_id'] ?? null, function (Builder $scope, string $uuid): void {
                $scope->whereHas('period', fn (Builder $period) => $period->where('uuid', $uuid));
            })
            ->when($filters['scenario_id'] ?? null, function (Builder $scope, string $uuid): void {
                $scope->whereHas('scenario', fn (Builder $scenario) => $scenario->where('uuid', $uuid));
            })
            ->when($filters['status'] ?? null, fn (Builder $scope, string $value) => $scope->where('status', $value))
            ->orderByDesc('updated_at');

        $summaryQuery = clone $query;
        $summary = $this->summary($summaryQuery);
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 25));
        $paginator->setCollection($paginator->getCollection()->map(fn (BudgetVersion $version): array => $this->versionToArray($version, false)));

        return ['paginator' => $paginator, 'summary' => $summary];
    }

    public function store(User $user, array $input): BudgetVersion
    {
        $organizationId = $this->catalogService->organizationId($user, $input);
        $period = $this->periodByUuid($organizationId, (string) $input['budget_period_id']);
        $scenario = $this->scenarioByUuid($organizationId, (string) $input['scenario_id']);

        if ($period->status === 'closed') {
            throw new \DomainException(trans_message('budgeting.periods.closed'));
        }

        $versionNumber = $this->nextVersionNumber($organizationId, (int) $period->id, (int) $scenario->id, (string) $input['budget_kind']);

        return BudgetVersion::create([
            'organization_id' => $organizationId,
            'budget_period_id' => $period->id,
            'scenario_id' => $scenario->id,
            'budget_kind' => $input['budget_kind'],
            'version_number' => $versionNumber,
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'status' => 'draft',
            'created_by' => $user->id,
            'workflow_history' => [],
        ])->load(['period', 'scenario']);
    }

    public function update(User $user, string $uuid, array $input): BudgetVersion
    {
        $version = $this->findVersion($user, $uuid);
        if ($version->status !== 'draft') {
            throw new \DomainException(trans_message('budgeting.versions.edit_forbidden'));
        }

        $version->fill($input)->save();

        return $version->refresh()->load(['period', 'scenario']);
    }

    public function cloneVersion(User $user, string $uuid, array $input): BudgetVersion
    {
        $baseVersion = $this->findVersion($user, $uuid);
        $sourceVersion = isset($input['source_version_id'])
            ? $this->findVersion($user, (string) $input['source_version_id'])
            : $baseVersion;

        return DB::transaction(function () use ($user, $baseVersion, $sourceVersion, $input): BudgetVersion {
            $newVersion = BudgetVersion::create([
                'organization_id' => $baseVersion->organization_id,
                'budget_period_id' => $baseVersion->budget_period_id,
                'scenario_id' => $baseVersion->scenario_id,
                'budget_kind' => $baseVersion->budget_kind,
                'version_number' => $this->nextVersionNumber(
                    (int) $baseVersion->organization_id,
                    (int) $baseVersion->budget_period_id,
                    (int) $baseVersion->scenario_id,
                    (string) $baseVersion->budget_kind
                ),
                'name' => $input['version_name'],
                'description' => $baseVersion->description,
                'status' => 'draft',
                'created_by' => $user->id,
                'workflow_history' => [],
            ]);

            if (($input['copy_lines'] ?? false) === true) {
                $this->copyLines($sourceVersion, $newVersion, (bool) ($input['copy_forecast'] ?? false));
            }

            return $newVersion->load(['period', 'scenario']);
        });
    }

    public function transition(User $user, string $uuid, string $action, ?string $comment = null): BudgetVersion
    {
        $version = $this->findVersion($user, $uuid);

        return DB::transaction(function () use ($version, $action, $user, $comment): BudgetVersion {
            $fromStatus = (string) $version->status;
            if (in_array($action, ['submit', 'approve', 'activate'], true) && in_array((string) $version->period?->status, ['closed', 'archived'], true)) {
                throw new \DomainException(trans_message('budgeting.errors.period_closed'));
            }

            $toStatus = $this->workflowService->transition($fromStatus, $action, $version->lines()->exists());

            if ($action === 'activate') {
                BudgetVersion::query()
                    ->where('organization_id', $version->organization_id)
                    ->where('budget_period_id', $version->budget_period_id)
                    ->where('scenario_id', $version->scenario_id)
                    ->where('budget_kind', $version->budget_kind)
                    ->where('status', BudgetWorkflowService::STATUS_ACTIVE)
                    ->where('id', '!=', $version->id)
                    ->update(['status' => BudgetWorkflowService::STATUS_REPLACED]);
            }

            $history = $version->workflow_history ?? [];
            $history[] = [
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'comment' => $comment,
                'user_id' => $user->id,
                'created_at' => now()->toIso8601String(),
            ];

            $version->status = $toStatus;
            $version->workflow_history = $history;

            if ($action === 'submit') {
                $version->submitted_at = now();
                $version->submitted_by = $user->id;
            }

            if ($action === 'approve') {
                $version->approved_at = now();
                $version->approved_by = $user->id;
            }

            if ($action === 'reject') {
                $version->approved_at = null;
                $version->approved_by = null;
            }

            if ($action === 'activate') {
                $version->activated_at = now();
                $version->activated_by = $user->id;
            }

            $version->save();

            return $version->refresh()->load(['period', 'scenario']);
        });
    }

    public function destroy(User $user, string $uuid): BudgetVersion
    {
        return $this->transition($user, $uuid, 'archive');
    }

    public function findVersion(User $user, string $uuid): BudgetVersion
    {
        $version = BudgetVersion::query()
            ->where('organization_id', $this->catalogService->organizationId($user))
            ->where('uuid', $uuid)
            ->with(['period', 'scenario'])
            ->first();

        if (!$version instanceof BudgetVersion) {
            throw new \DomainException(trans_message('budgeting.versions.not_found'));
        }

        return $version;
    }

    public function versionToArray(BudgetVersion $version, bool $withLines = true): array
    {
        $version->loadMissing(['period', 'scenario']);
        $planTotal = (float) BudgetAmount::query()
            ->whereHas('line', fn (Builder $query) => $query->where('budget_version_id', $version->id))
            ->sum('plan_amount');
        $forecastTotal = (float) BudgetAmount::query()
            ->whereHas('line', fn (Builder $query) => $query->where('budget_version_id', $version->id))
            ->sum('forecast_amount');

        $data = [
            'id' => $version->uuid,
            'organization_id' => $version->organization_id,
            'budget_kind' => $version->budget_kind,
            'version_number' => $version->version_number,
            'name' => $version->name,
            'description' => $version->description,
            'status' => $version->status,
            'status_label' => trans_message("budgeting.statuses.versions.{$version->status}"),
            'budget_period' => $this->catalogService->periodToArray($version->period),
            'scenario' => $this->catalogService->scenarioToArray($version->scenario),
            'summary' => [
                'lines_count' => $version->lines()->count(),
                'plan_total' => round($planTotal, 2),
                'forecast_total' => round($forecastTotal, 2),
                'currency' => 'RUB',
            ],
            'workflow_summary' => [
                'available_actions' => $this->workflowService->allowedActions((string) $version->status),
                'submitted_at' => $version->submitted_at?->toIso8601String(),
                'approved_at' => $version->approved_at?->toIso8601String(),
                'activated_at' => $version->activated_at?->toIso8601String(),
                'history' => $version->workflow_history ?? [],
            ],
        ];

        if ($withLines) {
            $data['lines'] = $version->lines()->with(['article', 'responsibilityCenter', 'amounts'])->get()
                ->map(fn (BudgetLine $line): array => $this->lineToArray($line))
                ->all();
        }

        return $data;
    }

    public function lineToArray(BudgetLine $line): array
    {
        return [
            'id' => $line->uuid,
            'budget_article_id' => $line->article?->uuid,
            'budget_article_code' => $line->article?->code,
            'budget_article_name' => $line->article?->name,
            'responsibility_center_id' => $line->responsibilityCenter?->uuid,
            'responsibility_center_code' => $line->responsibilityCenter?->code,
            'responsibility_center_name' => $line->responsibilityCenter?->name,
            'project_id' => $line->project_id,
            'contract_id' => $line->contract_id,
            'counterparty_id' => $line->counterparty_id,
            'currency' => $line->currency,
            'description' => $line->description,
            'amounts' => $line->amounts->sortBy('month')->map(fn (BudgetAmount $amount): array => [
                'month' => $amount->month?->format('Y-m'),
                'plan' => (float) $amount->plan_amount,
                'forecast' => (float) $amount->forecast_amount,
            ])->values()->all(),
        ];
    }

    private function periodByUuid(int $organizationId, string $uuid): BudgetPeriod
    {
        $period = BudgetPeriod::query()->where('organization_id', $organizationId)->where('uuid', $uuid)->first();
        if (!$period instanceof BudgetPeriod) {
            throw new \DomainException(trans_message('budgeting.periods.not_found'));
        }

        return $period;
    }

    private function scenarioByUuid(int $organizationId, string $uuid): BudgetScenario
    {
        $scenario = BudgetScenario::query()->where('organization_id', $organizationId)->where('uuid', $uuid)->first();
        if (!$scenario instanceof BudgetScenario) {
            throw new \DomainException(trans_message('budgeting.scenarios.not_found'));
        }

        return $scenario;
    }

    private function nextVersionNumber(int $organizationId, int $periodId, int $scenarioId, string $budgetKind): int
    {
        return ((int) BudgetVersion::query()
            ->where('organization_id', $organizationId)
            ->where('budget_period_id', $periodId)
            ->where('scenario_id', $scenarioId)
            ->where('budget_kind', $budgetKind)
            ->max('version_number')) + 1;
    }

    private function summary(Builder $query): array
    {
        $versions = $query->get(['id', 'status']);
        $ids = $versions->pluck('id')->all();

        return [
            'active_versions' => $versions->where('status', 'active')->count(),
            'draft_versions' => $versions->where('status', 'draft')->count(),
            'on_approval_versions' => $versions->where('status', 'on_approval')->count(),
            'approved_versions' => $versions->where('status', 'approved')->count(),
            'plan_total' => $ids === [] ? 0.0 : round((float) BudgetAmount::query()->whereHas('line', fn (Builder $scope) => $scope->whereIn('budget_version_id', $ids))->sum('plan_amount'), 2),
            'forecast_total' => $ids === [] ? 0.0 : round((float) BudgetAmount::query()->whereHas('line', fn (Builder $scope) => $scope->whereIn('budget_version_id', $ids))->sum('forecast_amount'), 2),
            'currency' => 'RUB',
        ];
    }

    private function copyLines(BudgetVersion $source, BudgetVersion $target, bool $copyForecast): void
    {
        $source->load(['lines.amounts']);
        foreach ($source->lines as $line) {
            $newLine = BudgetLine::create([
                'budget_version_id' => $target->id,
                'budget_article_id' => $line->budget_article_id,
                'responsibility_center_id' => $line->responsibility_center_id,
                'project_id' => $line->project_id,
                'contract_id' => $line->contract_id,
                'counterparty_id' => $line->counterparty_id,
                'currency' => $line->currency,
                'description' => $line->description,
                'metadata' => $line->metadata,
            ]);

            foreach ($line->amounts as $amount) {
                BudgetAmount::create([
                    'budget_line_id' => $newLine->id,
                    'month' => $amount->month,
                    'plan_amount' => $amount->plan_amount,
                    'forecast_amount' => $copyForecast ? $amount->forecast_amount : $amount->plan_amount,
                    'currency' => $amount->currency,
                ]);
            }
        }
    }
}
