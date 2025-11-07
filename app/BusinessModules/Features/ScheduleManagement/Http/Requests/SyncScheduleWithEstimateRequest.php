<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncScheduleWithEstimateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'force' => 'sometimes|boolean',
            'resolve_conflicts' => 'sometimes|in:prefer_schedule,prefer_estimate,manual',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resolve_conflicts.in' => 'Некорректный способ разрешения конфликтов. Допустимые значения: prefer_schedule, prefer_estimate, manual',
        ];
    }
}

