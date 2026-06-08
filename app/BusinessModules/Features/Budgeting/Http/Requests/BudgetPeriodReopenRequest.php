<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

use App\BusinessModules\Features\Budgeting\Services\BudgetPeriodClosureService;
use Illuminate\Validation\Rule;

final class BudgetPeriodReopenRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'expires_at' => ['required', 'date', 'after:now'],
            'adjustment_mode' => ['required', 'string', Rule::in([
                'budget_lines',
                'budget_import',
                'version_replacement',
                'period_settings',
                'mixed',
            ])],
            'allowed_operations' => ['sometimes', 'array', 'min:1', 'max:5'],
            'allowed_operations.*' => ['required', 'string', Rule::in([
                BudgetPeriodClosureService::OPERATION_BUDGET_LINES,
                BudgetPeriodClosureService::OPERATION_BUDGET_AMOUNTS,
                BudgetPeriodClosureService::OPERATION_BUDGET_IMPORT,
                BudgetPeriodClosureService::OPERATION_BUDGET_VERSIONS,
                BudgetPeriodClosureService::OPERATION_PERIOD_SETTINGS,
            ])],
            'change_scope' => ['required_without:change_objects', 'nullable', 'string', 'max:2000'],
            'change_objects' => ['sometimes', 'array', 'max:50'],
            'change_objects.*.type' => ['required_with:change_objects', 'string', 'max:64'],
            'change_objects.*.id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'change_objects.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
