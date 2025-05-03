<?php

namespace App\Http\Requests\Api\V1\Admin\WorkType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: Auth::user()->can('update', $this->route('work_type'))
        return true;
    }

    public function rules(): array
    {
        $workTypeId = $this->route('work_type'); // ID из маршрута
        return [
             'name' => 'sometimes|required|string|max:255', // TODO: Unique для организации, ignore $workTypeId
            'measurement_unit_id' => 'sometimes|required|integer|exists:measurement_units,id',
            'category' => 'nullable|string|max:100',
            'is_active' => 'sometimes|boolean',
        ];
    }
} 