<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'material_id' => 'nullable|exists:materials,id',
            'material' => 'nullable|array',
            'material.name' => 'required_without:material_id|string|max:255',
            'material.code' => 'nullable|string|max:50',
            'material.measurement_unit_id' => 'required_without:material_id|exists:measurement_units,id',
            'material.category' => 'nullable|string|max:100',
            'material.asset_type' => 'nullable|string|in:material,equipment,tool,consumable',
            'material.default_price' => 'nullable|numeric|min:0',
            'material.description' => 'nullable|string',
            'quantity' => 'required|numeric|min:0.001',
            'price' => 'required|numeric|min:0',
            'project_id' => 'nullable|exists:projects,id',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ];
    }
}
