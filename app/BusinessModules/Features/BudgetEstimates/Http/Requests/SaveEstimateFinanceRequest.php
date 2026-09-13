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
        if ($this->input('operation') === 'execution_distribution') {
            return self::executionRules();
        }
        if ($this->input('operation') === 'migration_apply') {
            return self::migrationRules();
        }
        if ($this->input('operation') === 'own_cost_distribution') {
            return self::ownCostDistributionRules();
        }
        if ($this->input('operation') === 'own_cost') {
            return self::ownCostRules();
        }
        return $this->input('operation') === 'cash_distribution' ? self::cashRules() : self::inputRules();
    }

    public function validator(): \Illuminate\Validation\Validator
    {
        return FinanceInputValidation::make($this->validationData(), $this->rules());
    }

    public function validated($key = null, $default = null)
    {
        return data_get(FinanceInputValidation::sanitize(parent::validated(), $this->rules()), $key, $default);
    }

    public function safe(?array $keys = null)
    {
        $input = new \Illuminate\Support\ValidatedInput($this->validated());

        return $keys === null ? $input : $input->only($keys);
    }

    public static function ownCostDistributionRules(bool $preview = false): array
    {
        return [
            'operation' => ['required', 'in:own_cost_distribution'],
            'revision' => ['required', 'integer', 'min:0'],
            'mutation_id' => ['required', 'uuid'],
            'cost_key' => ['required', 'uuid'],
            'source_version' => ['required', 'integer', 'min:1'],
            'source_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'lines' => ['present', 'array', $preview ? 'min:0' : 'min:1', 'max:20000'],
            'lines.*.allocation_key' => ['required', 'uuid', 'distinct'],
            'lines.*.condition_version' => ['required', 'integer', 'min:1'],
            'lines.*.version' => ['required', 'integer', 'min:0'],
            'lines.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }

    public static function migrationRules(): array
    {
        return [
            'operation' => ['required', 'in:migration_apply'],
            'revision' => ['required', 'integer', 'min:0'],
            'mutation_id' => ['required', 'uuid'],
            'links' => ['required', 'array', 'min:1', 'max:500'],
            'links.*.legacy_link_id' => ['required', 'integer', 'min:1', 'distinct'],
            'links.*.source_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    public static function ownCostRules(bool $preview = false): array
    {
        return [
            'operation' => ['required', 'in:own_cost'],
            'revision' => ['required', 'integer', 'min:0'],
            'mutation_id' => ['required', 'uuid'],
            'cost_key' => ['required', 'uuid'],
            'confirmed' => ['required', 'accepted'],
            'status' => ['sometimes', 'in:confirmed,voided'],
            'source_version' => ['sometimes', 'integer', 'min:1'],
            'source_type' => ['required', 'in:manual,advance_expense'],
            'advance_transaction_id' => ['required_if:source_type,advance_expense', 'prohibited_unless:source_type,advance_expense', 'integer', 'min:1'],
            'source_hash' => [$preview ? 'sometimes' : 'required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'cost_category_id' => ['required', 'integer', 'min:1'],
            'expense_date' => ['required', 'date_format:Y-m-d'],
            'basis' => ['required', 'string', 'max:10000'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'vat_mode' => ['required', 'in:none,exclusive,included,unknown'],
            'price_basis' => ['required', 'in:without_vat,with_vat,unknown'],
            'vat_rate' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public static function executionRules(bool $preview = false): array
    {
        $rules = self::cashRules($preview);
        unset($rules['transaction_id']);
        $rules['operation'] = ['required', 'in:execution_distribution'];
        $rules['act_id'] = ['required', 'integer', 'min:1'];
        $rules['lines.*.quantity'] = ['sometimes', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,8})?$/'];
        $rules['lines.*.amount'] = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];

        return $rules;
    }

    public static function cashRules(bool $preview = false): array
    {
        return [
            'operation' => ['required', 'in:cash_distribution'],
            'revision' => ['required', 'integer', 'min:0'],
            'mutation_id' => ['required', 'uuid'],
            'transaction_id' => ['required', 'integer', 'min:1'],
            'source_hash' => [$preview ? 'sometimes' : 'required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'lines' => ['present', 'array', $preview ? 'min:0' : 'min:1', 'max:20000'],
            'lines.*.allocation_key' => ['required', 'uuid', 'distinct'],
            'lines.*.condition_version' => ['required', 'integer', 'min:1'],
            'lines.*.version' => ['required', 'integer', 'min:0'],
            'lines.*.amount' => ['required', 'string', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/'],
        ];
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
            'target_keys' => ['required', 'array', 'min:1', 'max:20000'],
            'target_keys.*' => ['required', 'string', 'distinct', 'regex:/^[ir]:[1-9]\d*$/'],
            'lines' => ['present', 'array', 'max:40000'],
            'lines.*.key' => ['required', 'uuid', 'distinct'],
            'lines.*.condition_version' => ['sometimes', 'integer', 'min:0'],
            'lines.*.target_key' => ['required', 'string'],
            'lines.*.source' => ['required', 'in:contract,own,included'],
            'lines.*.contract_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'lines.*.quantity' => array_merge(['required'], $decimal),
            'lines.*.unit_price' => array_merge(['nullable'], $decimal),
            'lines.*.amount' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.vat_rate' => ['nullable', 'string', 'regex:/^\d{1,2}(\.\d{1,4})?$/'],
            'lines.*.vat_mode' => ['sometimes', 'in:none,exclusive,included,unknown'],
            'lines.*.price_basis' => ['required', 'in:with_vat,without_vat,unknown'],
            'lines.*.legacy_link_id' => ['sometimes', 'integer', 'min:1'],
            'lines.*.method' => ['required', 'in:unit,total'],
            'lines.*.composition_confirmed' => ['required', 'boolean'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
            'lines.*.adopt_estimate_price' => ['sometimes', 'boolean'],
            'total_line_keys' => ['required_with:expected_total', 'array', 'min:1', 'max:40000'],
            'total_line_keys.*' => ['required', 'uuid', 'distinct'],
            'expected_total' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }
}
