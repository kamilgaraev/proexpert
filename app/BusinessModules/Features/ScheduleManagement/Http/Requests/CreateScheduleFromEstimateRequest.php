<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

use function trans_message;

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
            'options.item_ids' => 'sometimes|nullable|array',
            'options.item_ids.*' => 'integer|exists:estimate_items,id',
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
            'estimate_id.required' => trans_message('schedule_management.validation.estimate_required'),
            'estimate_id.exists' => trans_message('schedule_management.validation.estimate_not_found'),
            'start_date.after_or_equal' => trans_message('schedule_management.validation.start_date_past'),
            'options.workers_count.min' => trans_message('schedule_management.validation.workers_count_min'),
            'options.workers_count.max' => trans_message('schedule_management.validation.workers_count_max'),
            'options.hours_per_day.min' => trans_message('schedule_management.validation.hours_per_day_min'),
            'options.hours_per_day.max' => trans_message('schedule_management.validation.hours_per_day_max'),
        ];
    }
}

