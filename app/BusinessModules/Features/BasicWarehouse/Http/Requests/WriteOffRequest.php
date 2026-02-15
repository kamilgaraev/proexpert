<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WriteOffRequest extends FormRequest
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
            'document_number' => 'nullable|string|max:100',
            'reason' => 'required|string|max:255',
            'metadata' => 'nullable|array',
        ];
    }
}
