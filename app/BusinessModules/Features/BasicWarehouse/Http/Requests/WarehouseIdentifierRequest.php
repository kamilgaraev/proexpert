<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'warehouse_id' => 'nullable|integer|exists:organization_warehouses,id',
            'identifier_type' => 'required|in:qr,barcode,datamatrix,rfid,nfc,internal',
            'code' => 'required|string|max:190',
            'entity_type' => 'required|in:warehouse,zone,cell,asset,inventory_act,movement,logistic_unit',
            'entity_id' => 'required|integer|min:1',
            'label' => 'nullable|string|max:255',
            'status' => 'required|in:active,archived,lost,damaged',
            'is_primary' => 'sometimes|boolean',
            'assigned_at' => 'nullable|date',
            'last_scanned_at' => 'nullable|date',
            'metadata' => 'nullable|array',
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
