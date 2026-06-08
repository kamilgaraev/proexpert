<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticleMapping;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\OneCBase;
use App\Models\OneCIntegrationProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BudgetCatalogService
{
    public function __construct(
        private readonly BudgetPeriodClosureService $periodClosureService,
        private readonly BudgetPeriodReopenService $periodReopenService
    ) {
    }

    public function organizationId(User $user, array $input = []): int
    {
        $organizationId = (int) ($user->current_organization_id ?: ($input['organization_id'] ?? 0));
        if ($organizationId <= 0) {
            throw new \DomainException(trans_message('budgeting.organization_context_missing'));
        }

        return $organizationId;
    }

    public function catalogs(User $user, array $filters): array
    {
        $organizationId = $this->organizationId($user, $filters);

        return [
            'periods' => $this->periods($organizationId, ['status' => $filters['period_status'] ?? null]),
            'scenarios' => $this->scenarios($organizationId, ['is_active' => true]),
            'responsibility_centers' => $this->responsibilityCenters($organizationId, ['is_active' => true]),
            'articles' => $this->articles($organizationId, [
                'budget_kind' => $filters['budget_kind'] ?? null,
                'is_active' => true,
            ]),
        ];
    }

    public function periods(int $organizationId, array $filters = []): array
    {
        return BudgetPeriod::query()
            ->where('organization_id', $organizationId)
            ->with('latestClosure.closedBy')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['year'] ?? null, fn (Builder $query, int|string $year) => $query->whereYear('starts_at', '<=', (int) $year)->whereYear('ends_at', '>=', (int) $year))
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (BudgetPeriod $period): array => $this->periodToArray($period))
            ->all();
    }

    public function scenarios(int $organizationId, array $filters = []): array
    {
        return BudgetScenario::query()
            ->where('organization_id', $organizationId)
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (BudgetScenario $scenario): array => $this->scenarioToArray($scenario))
            ->all();
    }

    public function responsibilityCenters(int $organizationId, array $filters = []): array
    {
        return ResponsibilityCenter::query()
            ->where('organization_id', $organizationId)
            ->with(['owner:id,name,email', 'approver:id,name,email'])
            ->when($filters['center_type'] ?? null, fn (Builder $query, string $type) => $query->where('center_type', $type))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('code')
            ->get()
            ->map(fn (ResponsibilityCenter $center): array => $this->centerToArray($center))
            ->all();
    }

    public function articles(int $organizationId, array $filters = []): array
    {
        return BudgetArticle::query()
            ->where('organization_id', $organizationId)
            ->with('mappings')
            ->when($filters['budget_kind'] ?? null, function (Builder $query, string $kind): void {
                $query->whereIn('budget_kind', [$kind, 'both']);
            })
            ->when($filters['flow_direction'] ?? null, fn (Builder $query, string $flow) => $query->where('flow_direction', $flow))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $scope) use ($search): void {
                    $scope->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('code')
            ->get()
            ->map(fn (BudgetArticle $article): array => $this->articleToArray($article))
            ->all();
    }

    public function storePeriod(User $user, array $input): BudgetPeriod
    {
        $organizationId = $this->organizationId($user, $input);
        $this->assertUniqueCode(BudgetPeriod::query(), $organizationId, (string) $input['code']);

        return BudgetPeriod::create([
            ...$input,
            'organization_id' => $organizationId,
            'status' => $input['status'] ?? 'open',
        ]);
    }

    public function updatePeriod(User $user, string $uuid, array $input): BudgetPeriod
    {
        $period = $this->findPeriod($user, $uuid);
        $this->periodClosureService->assertPeriodMutable($period, BudgetPeriodClosureService::OPERATION_PERIOD_SETTINGS);
        $this->assertUniqueCode(BudgetPeriod::query(), (int) $period->organization_id, (string) $input['code'], (int) $period->id);
        $period->fill($input)->save();

        return $period->refresh();
    }

    public function storeScenario(User $user, array $input): BudgetScenario
    {
        $organizationId = $this->organizationId($user, $input);
        $this->assertUniqueCode(BudgetScenario::query(), $organizationId, (string) $input['code']);

        return DB::transaction(function () use ($input, $organizationId): BudgetScenario {
            if (($input['is_default'] ?? false) === true) {
                BudgetScenario::query()->where('organization_id', $organizationId)->update(['is_default' => false]);
            }

            return BudgetScenario::create([
                ...$input,
                'organization_id' => $organizationId,
                'is_active' => $input['is_active'] ?? true,
            ]);
        });
    }

    public function updateScenario(User $user, string $uuid, array $input): BudgetScenario
    {
        $scenario = $this->findScenario($user, $uuid);
        $this->assertUniqueCode(BudgetScenario::query(), (int) $scenario->organization_id, (string) $input['code'], (int) $scenario->id);

        return DB::transaction(function () use ($scenario, $input): BudgetScenario {
            if (($input['is_default'] ?? false) === true) {
                BudgetScenario::query()->where('organization_id', $scenario->organization_id)->where('id', '!=', $scenario->id)->update(['is_default' => false]);
            }

            $scenario->fill($input)->save();

            return $scenario->refresh();
        });
    }

    public function storeResponsibilityCenter(User $user, array $input): ResponsibilityCenter
    {
        $organizationId = $this->organizationId($user, $input);
        $this->assertUniqueCode(ResponsibilityCenter::query(), $organizationId, (string) $input['code']);
        $input['parent_id'] = $this->nullableCenterId($organizationId, $input['parent_id'] ?? null);

        return ResponsibilityCenter::create([
            ...$input,
            'organization_id' => $organizationId,
            'is_active' => $input['is_active'] ?? true,
        ]);
    }

    public function updateResponsibilityCenter(User $user, string $uuid, array $input): ResponsibilityCenter
    {
        $center = $this->findResponsibilityCenter($user, $uuid);
        $this->assertUniqueCode(ResponsibilityCenter::query(), (int) $center->organization_id, (string) $input['code'], (int) $center->id);
        $input['parent_id'] = $this->nullableCenterId((int) $center->organization_id, $input['parent_id'] ?? null, (int) $center->id);
        $center->fill($input)->save();

        return $center->refresh();
    }

    public function storeArticle(User $user, array $input): BudgetArticle
    {
        $organizationId = $this->organizationId($user, $input);
        $this->assertUniqueCode(BudgetArticle::query(), $organizationId, (string) $input['code']);
        $input['parent_id'] = $this->nullableArticleId($organizationId, $input['parent_id'] ?? null);

        return BudgetArticle::create([
            ...$input,
            'organization_id' => $organizationId,
            'is_leaf' => $input['is_leaf'] ?? true,
            'is_active' => $input['is_active'] ?? true,
        ]);
    }

    public function updateArticle(User $user, string $uuid, array $input): BudgetArticle
    {
        $article = $this->findArticle($user, $uuid);
        $this->assertUniqueCode(BudgetArticle::query(), (int) $article->organization_id, (string) $input['code'], (int) $article->id);
        $input['parent_id'] = $this->nullableArticleId((int) $article->organization_id, $input['parent_id'] ?? null, (int) $article->id);
        $article->fill($input)->save();

        return $article->refresh();
    }

    public function destroySoft(BudgetPeriod|BudgetScenario|ResponsibilityCenter|BudgetArticle $model): void
    {
        if ($model instanceof BudgetPeriod) {
            $this->periodClosureService->assertMutableStatus((string) $model->status);
        }

        if ($model instanceof BudgetArticle && $model->children()->exists()) {
            $model->is_active = false;
            $model->save();
            return;
        }

        if ($model instanceof ResponsibilityCenter && $model->children()->exists()) {
            $model->is_active = false;
            $model->save();
            return;
        }

        $model->delete();
    }

    public function closePeriod(User $user, string $uuid, array $input): BudgetPeriod
    {
        $period = $this->findPeriod($user, $uuid);

        return $this->periodClosureService->close(
            $period,
            $user,
            (string) ($input['reason'] ?? ''),
            isset($input['closure_mode']) ? (string) $input['closure_mode'] : null
        );
    }

    public function periodClosureStatus(User $user, string $uuid): array
    {
        return $this->periodClosureService->statusPayload($this->findPeriod($user, $uuid));
    }

    public function reopenPeriod(User $user, string $uuid, array $input): BudgetPeriod
    {
        $period = $this->findPeriod($user, $uuid);

        return $this->periodReopenService->reopen($period, $user, $input);
    }

    public function storeArticleMapping(User $user, array $input): BudgetArticleMapping
    {
        $organizationId = $this->organizationId($user, $input);
        $article = BudgetArticle::query()
            ->where('organization_id', $organizationId)
            ->where('uuid', $input['budget_article_id'])
            ->first();

        if (!$article instanceof BudgetArticle) {
            throw new \DomainException(trans_message('budgeting.articles.not_found'));
        }

        $oneCBaseId = $this->scopedOneCBaseId($organizationId, $input['one_c_base_id'] ?? null);
        $integrationProfileId = $this->scopedIntegrationProfileId($organizationId, $oneCBaseId, $input['integration_profile_id'] ?? null);

        return BudgetArticleMapping::updateOrCreate([
            'organization_id' => $organizationId,
            'budget_article_id' => $article->id,
            'system' => $input['system'] ?? '1c',
            'one_c_base_id' => $oneCBaseId,
            'integration_profile_id' => $integrationProfileId,
            'external_code' => $input['external_code'],
        ], [
            'external_name' => $input['external_name'] ?? null,
            'mapping_status' => 'active',
            'mapping_payload' => $input['mapping_payload'] ?? null,
        ]);
    }

    public function articleMappings(User $user, array $filters): array
    {
        $organizationId = $this->organizationId($user, $filters);

        return BudgetArticleMapping::query()
            ->where('organization_id', $organizationId)
            ->whereHas('article', fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->with('article')
            ->orderBy('one_c_base_id')
            ->orderBy('external_code')
            ->get()
            ->map(fn (BudgetArticleMapping $mapping): array => $this->mappingToArray($mapping))
            ->all();
    }

    public function findPeriod(User $user, string $uuid): BudgetPeriod
    {
        return $this->findByUuid(BudgetPeriod::query(), $this->organizationId($user), $uuid, trans_message('budgeting.periods.not_found'));
    }

    public function findScenario(User $user, string $uuid): BudgetScenario
    {
        return $this->findByUuid(BudgetScenario::query(), $this->organizationId($user), $uuid, trans_message('budgeting.scenarios.not_found'));
    }

    public function findResponsibilityCenter(User $user, string $uuid): ResponsibilityCenter
    {
        return $this->findByUuid(ResponsibilityCenter::query(), $this->organizationId($user), $uuid, trans_message('budgeting.cfo.not_found'));
    }

    public function findArticle(User $user, string $uuid): BudgetArticle
    {
        return $this->findByUuid(BudgetArticle::query(), $this->organizationId($user), $uuid, trans_message('budgeting.articles.not_found'));
    }

    public function periodToArray(BudgetPeriod $period): array
    {
        $closureSummary = $this->periodClosureService->periodClosureSummary($period);

        return [
            'id' => $period->uuid,
            'organization_id' => $period->organization_id,
            'code' => $period->code,
            'name' => $period->name,
            'period_type' => $period->period_type,
            'starts_at' => $period->starts_at?->toDateString(),
            'ends_at' => $period->ends_at?->toDateString(),
            'status' => $period->status,
            'status_label' => trans_message("budgeting.statuses.periods.{$period->status}"),
            'is_closed' => $closureSummary['is_closed'],
            'closed_at' => $closureSummary['closed_at'],
            'closed_by' => $closureSummary['closed_by'],
            'closed_reason' => $closureSummary['closed_reason'],
            'closure_status' => $closureSummary['closure_status'],
            'closure_mode' => $closureSummary['closure_mode'],
            'reopen_active' => $closureSummary['reopen_active'],
            'reopen_expired' => $closureSummary['reopen_expired'],
            'reopened_until' => $closureSummary['reopened_until'],
            'reopen_reason' => $closureSummary['reopen_reason'],
            'reopened_by' => $closureSummary['reopened_by'],
            'adjustment_mode' => $closureSummary['adjustment_mode'],
            'change_scope' => $closureSummary['change_scope'],
            'change_objects' => $closureSummary['change_objects'],
            'allowed_operations' => $closureSummary['allowed_operations'],
            'plan_fact_actualized_at' => $closureSummary['plan_fact_actualized_at'],
        ];
    }

    public function scenarioToArray(BudgetScenario $scenario): array
    {
        return [
            'id' => $scenario->uuid,
            'organization_id' => $scenario->organization_id,
            'code' => $scenario->code,
            'name' => $scenario->name,
            'scenario_type' => $scenario->scenario_type,
            'is_default' => $scenario->is_default,
            'is_active' => $scenario->is_active,
        ];
    }

    public function centerToArray(ResponsibilityCenter $center): array
    {
        return [
            'id' => $center->uuid,
            'organization_id' => $center->organization_id,
            'parent_id' => $center->parent?->uuid,
            'center_type' => $center->center_type,
            'code' => $center->code,
            'name' => $center->name,
            'owner_user_id' => $center->owner_user_id,
            'approver_user_id' => $center->approver_user_id,
            'linked_entity_type' => $center->linked_entity_type,
            'linked_entity_id' => $center->linked_entity_id,
            'active_from' => $center->active_from?->toDateString(),
            'active_to' => $center->active_to?->toDateString(),
            'is_active' => $center->is_active,
        ];
    }

    public function articleToArray(BudgetArticle $article): array
    {
        return [
            'id' => $article->uuid,
            'organization_id' => $article->organization_id,
            'parent_id' => $article->parent?->uuid,
            'code' => $article->code,
            'name' => $article->name,
            'budget_kind' => $article->budget_kind,
            'flow_direction' => $article->flow_direction,
            'is_leaf' => $article->is_leaf,
            'is_active' => $article->is_active,
            'cost_category_id' => $article->cost_category_id,
            'mappings' => $article->mappings->map(fn (BudgetArticleMapping $mapping): array => $this->mappingToArray($mapping))->all(),
        ];
    }

    public function mappingToArray(BudgetArticleMapping $mapping): array
    {
        return [
            'id' => $mapping->uuid,
            'budget_article_id' => $mapping->article?->uuid,
            'budget_article_code' => $mapping->article?->code,
            'budget_article_name' => $mapping->article?->name,
            'system' => $mapping->system,
            'one_c_base_id' => $mapping->one_c_base_id,
            'integration_profile_id' => $mapping->integration_profile_id,
            'external_code' => $mapping->external_code,
            'external_name' => $mapping->external_name,
            'mapping_status' => $mapping->mapping_status,
            'mapping_payload' => $mapping->mapping_payload,
        ];
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     * @param Builder<T> $query
     * @return T
     */
    private function findByUuid(Builder $query, int $organizationId, string $uuid, string $message): mixed
    {
        $model = $query->where('organization_id', $organizationId)->where('uuid', $uuid)->first();
        if (!$model) {
            throw new \DomainException($message);
        }

        return $model;
    }

    private function assertUniqueCode(Builder $query, int $organizationId, string $code, ?int $ignoreId = null): void
    {
        $exists = $query
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn (Builder $scope) => $scope->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new \DomainException(trans_message('budgeting.validation.code_exists'));
        }
    }

    private function nullableCenterId(int $organizationId, mixed $uuid, ?int $selfId = null): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $center = ResponsibilityCenter::query()->where('organization_id', $organizationId)->where('uuid', $uuid)->first();
        if (!$center) {
            throw new \DomainException(trans_message('budgeting.cfo.parent_not_found'));
        }

        $this->assertNoCenterCycle($center, $selfId);

        return (int) $center->id;
    }

    private function nullableArticleId(int $organizationId, mixed $uuid, ?int $selfId = null): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $article = BudgetArticle::query()->where('organization_id', $organizationId)->where('uuid', $uuid)->first();
        if (!$article) {
            throw new \DomainException(trans_message('budgeting.articles.parent_not_found'));
        }

        $this->assertNoArticleCycle($article, $selfId);

        return (int) $article->id;
    }

    private function assertNoCenterCycle(ResponsibilityCenter $parent, ?int $selfId): void
    {
        if ($selfId === null) {
            return;
        }

        $current = $parent;
        while ($current instanceof ResponsibilityCenter) {
            if ((int) $current->id === $selfId) {
                throw new \DomainException(trans_message('budgeting.validation.parent_cycle'));
            }

            $current = $current->parent;
        }
    }

    private function assertNoArticleCycle(BudgetArticle $parent, ?int $selfId): void
    {
        if ($selfId === null) {
            return;
        }

        $current = $parent;
        while ($current instanceof BudgetArticle) {
            if ((int) $current->id === $selfId) {
                throw new \DomainException(trans_message('budgeting.validation.parent_cycle'));
            }

            $current = $current->parent;
        }
    }

    private function scopedOneCBaseId(int $organizationId, mixed $oneCBaseId): int
    {
        $baseId = (int) $oneCBaseId;
        $exists = OneCBase::query()
            ->where('organization_id', $organizationId)
            ->where('id', $baseId)
            ->exists();

        if (!$exists) {
            throw new \DomainException(trans_message('budgeting.mappings.one_c_base_not_found'));
        }

        return $baseId;
    }

    private function scopedIntegrationProfileId(int $organizationId, int $oneCBaseId, mixed $integrationProfileId): ?int
    {
        if ($integrationProfileId === null || $integrationProfileId === '') {
            return null;
        }

        $profileId = (int) $integrationProfileId;
        $exists = OneCIntegrationProfile::query()
            ->where('organization_id', $organizationId)
            ->where('one_c_base_id', $oneCBaseId)
            ->where('id', $profileId)
            ->exists();

        if (!$exists) {
            throw new \DomainException(trans_message('budgeting.mappings.integration_profile_not_found'));
        }

        return $profileId;
    }
}
