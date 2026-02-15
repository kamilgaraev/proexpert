<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReserveAssetRequest extends FormRequest
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
            'quantity' => 'required|numeric|min:0.001',
            'project_id' => 'nullable|exists:projects,id',
            'expires_hours' => 'nullable|integer|min:1|max:168',
            'reason' => 'nullable|string',
        ];
    }
}
