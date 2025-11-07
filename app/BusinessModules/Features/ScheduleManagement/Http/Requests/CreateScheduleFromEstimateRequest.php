<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateScheduleFromEstimateRequest extends FormRequest
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
            'estimate_id' => 'required|integer|exists:estimates,id',
            'name' => 'sometimes|nullable|string|max:255',
            'start_date' => 'sometimes|nullable|date|after_or_equal:today',
            'options' => 'sometimes|nullable|array',
            'options.workers_count' => 'sometimes|integer|min:1|max:100',
            'options.hours_per_day' => 'sometimes|integer|min:1|max:24',
            'options.include_weekends' => 'sometimes|boolean',
            'options.auto_calculate_dates' => 'sometimes|boolean',
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
            'estimate_id.required' => 'ID сметы обязателен',
            'estimate_id.exists' => 'Смета не найдена',
            'start_date.after_or_equal' => 'Дата начала не может быть в прошлом',
            'options.workers_count.min' => 'Количество работников должно быть не менее 1',
            'options.workers_count.max' => 'Количество работников не должно превышать 100',
            'options.hours_per_day.min' => 'Рабочих часов в день должно быть не менее 1',
            'options.hours_per_day.max' => 'Рабочих часов в день не должно превышать 24',
        ];
    }
}

