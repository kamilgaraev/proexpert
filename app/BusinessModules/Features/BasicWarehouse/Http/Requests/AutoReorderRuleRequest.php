<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutoReorderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
    }
}
