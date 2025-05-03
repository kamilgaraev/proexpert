<?php

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: Auth::user()->can('create', WorkType::class)
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255', // TODO: Unique для организации
            'measurement_unit_id' => 'required|integer|exists:measurement_units,id',
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
        ];
    }
} 