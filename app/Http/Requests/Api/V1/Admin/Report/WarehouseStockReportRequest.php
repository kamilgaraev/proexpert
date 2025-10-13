<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseStockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'nullable|integer|exists:organization_warehouses,id',
            'material_id' => 'nullable|integer|exists:materials,id',
            'category' => 'nullable|string|max:255',
            'show_critical_only' => 'nullable|in:0,1,true,false',
            'show_reserved' => 'nullable|in:0,1,true,false',
            'show_expired' => 'nullable|in:0,1,true,false',
            'expiring_days' => 'nullable|integer|min:1|max:365',
            'min_quantity' => 'nullable|numeric|min:0',
            'format' => 'nullable|string|in:json,excel,pdf',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'Указанный склад не найден',
            'material_id.exists' => 'Указанный материал не найден',
            'format.in' => 'Недопустимый формат экспорта',
        ];
    }
}

