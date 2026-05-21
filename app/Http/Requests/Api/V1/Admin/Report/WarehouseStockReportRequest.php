<?php

namespace App\Http\Requests\Api\V1\Admin\Report;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseStockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) ($this->attributes->get('current_organization_id') ?? $this->user()?->current_organization_id);

        return [
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'material_id' => [
                'nullable',
                'integer',
                Rule::exists('materials', 'id')->where('organization_id', $organizationId),
            ],
            'category' => 'nullable|string|max:255',
            'asset_type' => ['nullable', 'string', Rule::in(array_keys(Asset::getAssetTypes()))],
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

