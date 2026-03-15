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
