<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        $rules = [
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'material_id' => 'required|exists:materials,id',
            'min_stock' => 'required|numeric|min:0',
            'max_stock' => 'required|numeric|gt:min_stock',
            'reorder_point' => 'required|numeric|gte:min_stock|lte:max_stock',
            'reorder_quantity' => 'required|numeric|min:0.001',
            'default_supplier_id' => 'nullable|exists:suppliers,id',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ];

        if ($this->isMethod('put') || $this->isMethod('patch')) {
            foreach ($rules as $field => $rule) {
                $rules[$field] = str_replace('required', 'sometimes', $rule);
            }
        }

        return $rules;
    }
}
