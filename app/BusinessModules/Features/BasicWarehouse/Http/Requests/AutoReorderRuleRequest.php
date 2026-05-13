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

        $payload = [
            'min_stock' => $minStock,
            'default_supplier_id' => $this->input('default_supplier_id', $this->input('supplier_id')),
            'max_stock' => $maxStock,
        ];

        $this->merge(array_filter($payload, static fn ($value) => $value !== null && $value !== ''));
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
}
