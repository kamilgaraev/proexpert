<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
                $rules[$key] = str_replace('required', 'sometimes', $rule);
            }
        }

        return $rules;
    }
}
