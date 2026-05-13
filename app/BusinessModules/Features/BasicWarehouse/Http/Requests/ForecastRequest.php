<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'horizon_days' => 'nullable|integer|min:7|max:365',
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_warehouses', 'id')->where('organization_id', $organizationId),
            ],
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => [
                'integer',
                Rule::exists('materials', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
        ];
    }
}
