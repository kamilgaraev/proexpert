<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class BudgetStarterCatalogService
{
    private const PERMISSIONS = [
        'scenarios' => 'budgeting.scenarios.manage',
        'responsibility_centers' => 'budgeting.cfo.manage',
        'articles' => 'budgeting.articles.manage',
    ];

    private const MODELS = [
        'scenarios' => BudgetScenario::class,
        'responsibility_centers' => ResponsibilityCenter::class,
        'articles' => BudgetArticle::class,
    ];

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function templates(): array
    {
        $templates = ['scenarios' => [], 'responsibility_centers' => [], 'articles' => []];
        foreach (['scenarios', 'responsibility_centers'] as $group) {
            foreach (config("budgeting_starter_catalogs.$group", []) as $definition) {
                $nameKey = $definition['name_key'];
                unset($definition['name_key']);
                $templates[$group][] = [
                    ...$definition,
                    'name' => trans_message("budgeting.catalogs.starter_names.$group.$nameKey"),
                    'is_active' => true,
                ];
            }
        }
        foreach (['bdr' => 'expense', 'bdds' => 'outflow'] as $kind => $direction) {
            foreach (config('budgeting_starter_catalogs.expenses', []) as $definition) {
                $templates['articles'][] = [
                    'code' => strtoupper($kind).'-'.$definition['code'],
                    'name' => trans_message('budgeting.catalogs.starter_names.articles.'.$definition['name_key']),
                    'budget_kind' => $kind,
                    'flow_direction' => $direction,
                    'management_cost_class' => $definition['management_cost_class'],
                    'is_leaf' => true,
                    'is_active' => true,
                ];
            }
        }

        return $templates;
    }

    public function apply(User $actor, array $groups): array
    {
        if ($groups === [] || count($groups) !== count(array_unique($groups))
            || array_diff($groups, array_keys(self::PERMISSIONS)) !== []) {
            throw new UnprocessableEntityHttpException(trans_message('budgeting.catalogs.starter_groups_invalid'));
        }

        $organizationId = (int) $actor->current_organization_id;
        if (!$actor->is_active || $organizationId <= 0) {
            throw new AccessDeniedHttpException;
        }
        foreach ($groups as $group) {
            if (!$this->authorization->canCurrent($actor, self::PERMISSIONS[$group], ['organization_id' => $organizationId])) {
                throw new AccessDeniedHttpException;
            }
        }

        return DB::transaction(function () use ($organizationId, $groups): array {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $templates = $this->templates();
            $created = array_fill_keys(array_keys(self::PERMISSIONS), 0);
            $hasDefault = BudgetScenario::query()->where('organization_id', $organizationId)
                ->where('is_default', true)->exists();

            foreach ($groups as $group) {
                $modelClass = self::MODELS[$group];
                $existing = $modelClass::withTrashed()->where('organization_id', $organizationId)
                    ->get($group === 'articles' ? ['code', 'name', 'budget_kind'] : ['code', 'name']);
                foreach ($templates[$group] as $definition) {
                    $duplicate = $existing->contains(fn ($record): bool => $record->code === $definition['code']
                        || (mb_strtolower(trim($record->name)) === mb_strtolower(trim($definition['name']))
                            && ($group !== 'articles' || in_array($record->budget_kind, [$definition['budget_kind'], 'both'], true))));
                    if ($duplicate) {
                        continue;
                    }
                    if ($group === 'scenarios') {
                        $definition['is_default'] = !$hasDefault && $definition['scenario_type'] === 'base';
                        $hasDefault = $hasDefault || $definition['is_default'];
                    }
                    $modelClass::create([
                        ...$definition,
                        'organization_id' => $organizationId,
                    ]);
                    $created[$group]++;
                }
            }

            return $created;
        });
    }
}
