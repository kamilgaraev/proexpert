<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseStorageCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'zone_id' => 'nullable|integer|exists:warehouse_zones,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:80',
            'cell_type' => 'required|in:storage,picking,buffer,receiving,shipping,quarantine,returns',
            'status' => 'required|in:available,blocked,maintenance,archived',
            'rack_number' => 'nullable|string|max:50',
            'shelf_number' => 'nullable|string|max:50',
            'bin_number' => 'nullable|string|max:50',
            'capacity' => 'nullable|numeric|min:0',
            'max_weight' => 'nullable|numeric|min:0',
            'storage_conditions' => 'nullable|array',
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
            'zone_id' => trans_message('basic_warehouse.cell.validation.zone'),
            'name' => trans_message('basic_warehouse.cell.validation.name'),
            'code' => trans_message('basic_warehouse.cell.validation.code'),
            'cell_type' => trans_message('basic_warehouse.cell.validation.type'),
            'status' => trans_message('basic_warehouse.cell.validation.status'),
            'rack_number' => trans_message('basic_warehouse.cell.validation.rack_number'),
            'shelf_number' => trans_message('basic_warehouse.cell.validation.shelf_number'),
            'bin_number' => trans_message('basic_warehouse.cell.validation.bin_number'),
            'capacity' => trans_message('basic_warehouse.cell.validation.capacity'),
            'max_weight' => trans_message('basic_warehouse.cell.validation.max_weight'),
            'storage_conditions' => trans_message('basic_warehouse.cell.validation.storage_conditions'),
            'metadata' => trans_message('basic_warehouse.cell.validation.metadata'),
            'is_active' => trans_message('basic_warehouse.cell.validation.is_active'),
            'notes' => trans_message('basic_warehouse.cell.validation.notes'),
        ];
    }
}
