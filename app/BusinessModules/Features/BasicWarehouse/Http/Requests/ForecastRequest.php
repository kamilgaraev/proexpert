<?php

namespace App\BusinessModules\Features\BasicWarehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'horizon_days' => 'nullable|integer|min:7|max:365',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'exists:materials,id',
        ];
    }
}
