<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $zoneType = $this->input('zone_type', $this->input('type'));

        if ($zoneType !== null) {
            $this->merge([
                'zone_type' => $zoneType,
            ]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'zone_type' => 'required|in:storage,receiving,shipping,quarantine,returns',
            'rack_number' => 'nullable|string|max:50',
            'shelf_number' => 'nullable|string|max:50',
            'cell_number' => 'nullable|string|max:50',
            'capacity' => 'nullable|numeric|min:0',
            'max_weight' => 'nullable|numeric|min:0',
            'storage_conditions' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ];

        if ($this->isMethod('patch') || $this->isMethod('put')) {
            foreach ($rules as $key => $rule) {
                $rules[$key] = str_replace('required', 'sometimes|required', $rule);
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'name' => trans_message('basic_warehouse.zone.validation.name'),
            'code' => trans_message('basic_warehouse.zone.validation.code'),
            'zone_type' => trans_message('basic_warehouse.zone.validation.type'),
            'rack_number' => trans_message('basic_warehouse.zone.validation.rack_number'),
            'shelf_number' => trans_message('basic_warehouse.zone.validation.shelf_number'),
            'cell_number' => trans_message('basic_warehouse.zone.validation.cell_number'),
            'capacity' => trans_message('basic_warehouse.zone.validation.capacity'),
            'max_weight' => trans_message('basic_warehouse.zone.validation.max_weight'),
            'storage_conditions' => trans_message('basic_warehouse.zone.validation.storage_conditions'),
            'is_active' => trans_message('basic_warehouse.zone.validation.is_active'),
            'notes' => trans_message('basic_warehouse.zone.validation.notes'),
        ];
    }
}
