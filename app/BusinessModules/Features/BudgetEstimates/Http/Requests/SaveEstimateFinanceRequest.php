<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveEstimateFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return self::inputRules();
    }

    public static function inputRules(): array
    {
        $decimal = ['string', 'regex:/^\d{1,12}(\.\d{1,8})?$/'];

        return [
            'revision' => ['required', 'integer', 'min:0'],
            'confirm_resource_changes' => ['sometimes', 'boolean'],
            'prepare_resource_items' => ['sometimes', 'array', 'max:1000'],
            'prepare_resource_items.*' => ['required', 'integer', 'min:1', 'distinct'],
            'resource_mappings' => ['sometimes', 'array', 'max:1000'],
            'resource_mappings.*.resource_id' => ['required', 'integer', 'distinct', 'min:1'],
            'resource_mappings.*.item_id' => ['present', 'nullable', 'integer', 'min:1'],
            'preview_operation' => ['sometimes', 'in:total,estimate_prices'],
            'mutation_id' => ['required', 'uuid'],
            'target_keys' => ['required', 'array', 'min:1', 'max:1000'],
            'target_keys.*' => ['required', 'string', 'distinct', 'regex:/^[ir]:[1-9]\d*$/'],
            'lines' => ['present', 'array', 'max:5000'],
            'lines.*.key' => ['required', 'uuid', 'distinct'],
            'lines.*.target_key' => ['required', 'string'],
            'lines.*.source' => ['required', 'in:contract,own,included'],
            'lines.*.contract_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'lines.*.quantity' => array_merge(['required'], $decimal),
            'lines.*.unit_price' => array_merge(['nullable'], $decimal),
            'lines.*.amount' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.vat_rate' => ['nullable', 'string', 'regex:/^\d{1,2}(\.\d{1,4})?$/'],
            'lines.*.price_basis' => ['required', 'in:with_vat,without_vat,unknown'],
            'lines.*.legacy_link_id' => ['sometimes', 'integer', 'min:1'],
            'lines.*.method' => ['required', 'in:unit,total'],
            'lines.*.composition_confirmed' => ['required', 'boolean'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
            'lines.*.adopt_estimate_price' => ['sometimes', 'boolean'],
            'total_line_keys' => ['required_with:expected_total', 'array', 'min:1'],
            'total_line_keys.*' => ['required', 'uuid', 'distinct'],
            'expected_total' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }
}
