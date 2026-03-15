<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseScanEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'nullable|integer|exists:organization_warehouses,id',
            'code' => 'required|string|max:190',
            'source' => 'sometimes|string|in:admin,mobile,tsd,rfid_gate,api',
            'scan_context' => 'nullable|string|max:120',
            'metadata' => 'nullable|array',
            'notes' => 'nullable|string',
        ];
    }
}
