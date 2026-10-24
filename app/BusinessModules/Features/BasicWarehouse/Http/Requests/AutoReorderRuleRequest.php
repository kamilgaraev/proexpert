<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutoReorderRuleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $minStock = $this->input('min_stock', $this->input('min_stock_level'));
        $reorderPoint = $this->input('reorder_point');
        $reorderQuantity = $this->input('reorder_quantity');
        $maxStock = $this->input('max_stock');

        if (($maxStock === null || $maxStock === '') && is_numeric((string) $reorderPoint) && is_numeric((string) $reorderQuantity)) {
            $maxStock = (float) $reorderPoint + (float) $reorderQuantity;
        }

        $payload = [];

        if ($this->hasAny(['min_stock', 'min_stock_level'])) {
            $payload['min_stock'] = $minStock;
        }
        if ($this->hasAny(['default_supplier_id', 'supplier_id'])) {
            $payload['default_supplier_id'] = $this->input('default_supplier_id', $this->input('supplier_id'));
        }
        if ($maxStock !== null && $maxStock !== '') {
            $payload['max_stock'] = $maxStock;
        }

        $this->merge($payload);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;
        $presenceRule = $this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required';

        $rules = [
            'warehouse_id' => [
                $presenceRule,
                Rule::exists('organization_warehouses', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'material_id' => [
                $presenceRule,
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'min_stock' => [$presenceRule, 'numeric', 'min:0'],
            'max_stock' => [$presenceRule, 'numeric', 'gt:min_stock'],
            'reorder_point' => [$presenceRule, 'numeric', 'gte:min_stock', 'lte:max_stock'],
            'reorder_quantity' => [$presenceRule, 'numeric', 'min:0.001'],
            'default_supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'min_stock.min' => trans_message('basic_warehouse.auto_reorder.validation.min_stock_non_negative'),
            'max_stock.gt' => trans_message('basic_warehouse.auto_reorder.validation.max_stock_above_min'),
            'reorder_point.gte' => trans_message('basic_warehouse.auto_reorder.validation.reorder_point_below_min'),
            'reorder_point.lte' => trans_message('basic_warehouse.auto_reorder.validation.reorder_point_above_max'),
            'reorder_quantity.min' => trans_message('basic_warehouse.auto_reorder.validation.reorder_quantity_min'),
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => trans_message('basic_warehouse.auto_reorder.validation.attributes.warehouse'),
            'material_id' => trans_message('basic_warehouse.auto_reorder.validation.attributes.material'),
            'min_stock' => trans_message('basic_warehouse.auto_reorder.validation.attributes.min_stock'),
            'max_stock' => trans_message('basic_warehouse.auto_reorder.validation.attributes.max_stock'),
            'reorder_point' => trans_message('basic_warehouse.auto_reorder.validation.attributes.reorder_point'),
            'reorder_quantity' => trans_message('basic_warehouse.auto_reorder.validation.attributes.reorder_quantity'),
            'default_supplier_id' => trans_message('basic_warehouse.auto_reorder.validation.attributes.supplier'),
        ];
    }
}
