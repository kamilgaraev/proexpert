<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Requests;

use App\BusinessModules\Features\MachineryOperations\Enums\MachineryAssetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateMachineryAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_type' => ['required', Rule::enum(MachineryAssetType::class)],
            'name' => ['required', 'string', 'max:255'],
            'inventory_number' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'ownership_type' => ['sometimes', Rule::in(['owned', 'leased', 'subcontractor'])],
            'tracks_meter' => ['sometimes', 'boolean'],
            'tracks_fuel' => ['sometimes', 'boolean'],
            'tracks_production' => ['sometimes', 'boolean'],
            'maintenance_enabled' => ['sometimes', 'boolean'],
            'meter_unit' => ['nullable', 'string', 'max:40'],
            'operating_cost_per_hour' => ['sometimes', 'numeric', 'min:0'],
            'fuel_type' => ['nullable', 'string', 'max:80'],
            'fuel_consumption_rate' => ['nullable', 'numeric', 'min:0'],
            'meter_value' => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'asset_type.required' => trans_message('machinery_operations.validation.asset_type_required'),
            'asset_type.*' => trans_message('machinery_operations.validation.asset_type_invalid'),
            'name.required' => trans_message('machinery_operations.validation.name_required'),
            'inventory_number.required' => trans_message('machinery_operations.validation.inventory_number_required'),
        ];
    }
}
