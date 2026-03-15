<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseLogisticUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'zone_id' => 'nullable|integer|exists:warehouse_zones,id',
            'cell_id' => 'nullable|integer|exists:warehouse_storage_cells,id',
            'parent_unit_id' => 'nullable|integer|exists:warehouse_logistic_units,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:80',
            'unit_type' => 'required|in:box,pallet,container,bundle,cart,kit,custom',
            'status' => 'required|in:draft,available,sealed,in_transit,archived',
            'capacity' => 'nullable|numeric|min:0',
            'current_load' => 'nullable|numeric|min:0',
            'gross_weight' => 'nullable|numeric|min:0',
            'volume' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ];

        if ($this->isMethod('put') || $this->isMethod('patch')) {
            foreach ($rules as $key => $rule) {
                $rules[$key] = str_replace('required', 'sometimes', $rule);
            }
        }

        return $rules;
    }
}
