<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:255'],
            'schedule_type' => ['nullable', 'string', 'max:40'],
            'hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'week_pattern' => ['nullable', 'array'],
            'week_pattern.work_days' => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
            'week_pattern.work_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'is_active' => ['nullable', 'boolean'],
            'initial_day' => ['nullable', 'array:work_date,day_type,planned_hours,comment'],
            'initial_day.work_date' => [Rule::requiredIf(fn (): bool => $this->input('initial_day') !== null), 'date_format:Y-m-d'],
            'initial_day.day_type' => ['sometimes', 'required', Rule::in(['work', 'weekend', 'holiday'])],
            'initial_day.planned_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:24'],
            'initial_day.comment' => ['nullable', 'string', 'max:255'],
        ];
    }
}
