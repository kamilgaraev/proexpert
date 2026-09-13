<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

final class PreviewEstimateFinanceRequest extends SaveEstimateFinanceRequest
{
    public function rules(): array
    {
        if ($this->input('operation') === 'execution_distribution') {
            return self::executionRules(true);
        }
        if ($this->input('preview_operation') === 'migration_plan') {
            return self::migrationPlanRules();
        }
        if ($this->input('preview_operation') === 'own_cost_options') {
            return self::ownCostOptionsRules();
        }
        if ($this->input('operation') === 'own_cost_distribution') {
            return self::ownCostDistributionRules(true);
        }
        if ($this->input('operation') === 'own_cost') {
            return self::ownCostRules(true);
        }
        if ($this->input('operation') === 'cash_distribution') {
            return self::cashRules(true);
        }
        return $this->input('preview_operation') === 'source_amount' ? self::sourceRules() : parent::rules();
    }

    public static function ownCostOptionsRules(): array
    {
        return [
            'preview_operation' => ['required', 'in:own_cost_options'],
            'kind' => ['required', 'in:categories,documents'],
            'query' => ['sometimes', 'string', 'max:200'],
            'after' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public static function migrationPlanRules(): array
    {
        return [
            'preview_operation' => ['required', 'in:migration_plan'],
            'include_managed' => ['sometimes', 'boolean'],
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    public static function sourceRules(): array
    {
        return [
            'preview_operation' => ['required', 'in:source_amount'],
            'item_ids' => ['required', 'array', 'min:1', 'max:20000'],
            'item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
