<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;
        $presenceRule = $this->isMethod('put') || $this->isMethod('patch') ? 'sometimes' : 'required';

        $rules = [
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_warehouses', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'identifier_type' => [$presenceRule, 'in:qr,barcode,datamatrix,rfid,nfc,internal'],
            'code' => [$presenceRule, 'string', 'max:190'],
            'entity_type' => [$presenceRule, 'in:warehouse,zone,cell,asset,inventory_act,movement,logistic_unit'],
            'entity_id' => [$presenceRule, 'integer', 'min:1'],
            'label' => 'nullable|string|max:255',
            'status' => [$presenceRule, 'in:active,archived,lost,damaged'],
            'is_primary' => 'sometimes|boolean',
            'assigned_at' => 'nullable|date',
            'last_scanned_at' => 'nullable|date',
            'metadata' => 'nullable|array',
            'notes' => 'nullable|string',
        ];

        return $rules;
    }
}
