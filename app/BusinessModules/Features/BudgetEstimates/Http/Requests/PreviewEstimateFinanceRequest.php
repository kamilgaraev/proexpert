<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

final class PreviewEstimateFinanceRequest extends SaveEstimateFinanceRequest
{
    public function rules(): array
    {
        return $this->input('preview_operation') === 'source_amount' ? self::sourceRules() : parent::rules();
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
