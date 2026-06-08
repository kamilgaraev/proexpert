<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Requests;

final class BudgetLineReplaceRequest extends BudgetingFormRequest
{
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array'],
            'lines.*.id' => ['sometimes', 'nullable', 'string', 'max:36'],
            'lines.*.budget_article_id' => ['required', 'string', 'max:36'],
            'lines.*.responsibility_center_id' => ['required', 'string', 'max:36'],
            'lines.*.project_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.contract_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.counterparty_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.currency' => ['sometimes', 'string', 'size:3'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines.*.amounts' => ['required', 'array'],
            'lines.*.amounts.*.month' => ['required', 'date'],
            'lines.*.amounts.*.plan' => ['sometimes', 'nullable', 'numeric'],
            'lines.*.amounts.*.forecast' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
