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
                $rules[$key] = str_replace('required', 'sometimes|required', $rule);
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'zone_id' => trans_message('basic_warehouse.logistic_unit.validation.zone'),
            'cell_id' => trans_message('basic_warehouse.logistic_unit.validation.cell'),
            'parent_unit_id' => trans_message('basic_warehouse.logistic_unit.validation.parent'),
            'name' => trans_message('basic_warehouse.logistic_unit.validation.name'),
            'code' => trans_message('basic_warehouse.logistic_unit.validation.code'),
            'unit_type' => trans_message('basic_warehouse.logistic_unit.validation.type'),
            'status' => trans_message('basic_warehouse.logistic_unit.validation.status'),
            'capacity' => trans_message('basic_warehouse.logistic_unit.validation.capacity'),
            'current_load' => trans_message('basic_warehouse.logistic_unit.validation.current_load'),
            'gross_weight' => trans_message('basic_warehouse.logistic_unit.validation.gross_weight'),
            'volume' => trans_message('basic_warehouse.logistic_unit.validation.volume'),
            'metadata' => trans_message('basic_warehouse.logistic_unit.validation.metadata'),
            'is_active' => trans_message('basic_warehouse.logistic_unit.validation.is_active'),
            'notes' => trans_message('basic_warehouse.logistic_unit.validation.notes'),
        ];
    }
}
