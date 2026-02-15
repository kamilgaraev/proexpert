<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_warehouse_id' => 'required|exists:organization_warehouses,id',
            'to_warehouse_id' => 'required|exists:organization_warehouses,id|different:from_warehouse_id',
            'material_id' => 'required|exists:materials,id',
            'quantity' => 'required|numeric|min:0.001',
            'document_number' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ];
    }
}
