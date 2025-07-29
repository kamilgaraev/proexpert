<?php

namespace App\Http\Requests\Api\V1\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Schedule\ScheduleStatusEnum;

class UpdateProjectScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Авторизация уже проверена в middleware
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'planned_start_date' => 'sometimes|required|date',
            'planned_end_date' => 'sometimes|required|date|after:planned_start_date',
            'status' => 'sometimes|required|string|in:draft,active,paused,completed,cancelled',
            'is_template' => 'sometimes|boolean',
            'template_name' => 'sometimes|nullable|string|max:255|required_if:is_template,true',
            'template_description' => 'sometimes|nullable|string|max:1000',
            'calculation_settings' => 'sometimes|nullable|array',
            'calculation_settings.auto_schedule' => 'sometimes|boolean',
            'calculation_settings.level_resources' => 'sometimes|boolean',
            'calculation_settings.working_days_per_week' => 'sometimes|integer|min:1|max:7',
            'calculation_settings.working_hours_per_day' => 'sometimes|numeric|min:1|max:24',
            'display_settings' => 'sometimes|nullable|array',
            'display_settings.show_critical_path' => 'sometimes|boolean',
            'display_settings.show_float' => 'sometimes|boolean',
            'display_settings.show_baseline' => 'sometimes|boolean',
            'total_estimated_cost' => 'sometimes|nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название графика обязательно',
            'name.max' => 'Название графика не должно превышать 255 символов',
            'planned_start_date.required' => 'Дата начала обязательна',
            'planned_end_date.required' => 'Дата окончания обязательна',
            'planned_end_date.after' => 'Дата окончания должна быть позже даты начала',
            'status.in' => 'Недопустимый статус графика',
            'template_name.required_if' => 'Для шаблона необходимо указать название',
            'total_estimated_cost.min' => 'Стоимость не может быть отрицательной',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Проверяем, что при изменении статуса на "завершен" прогресс должен быть 100%
            if ($this->has('status') && $this->status === 'completed') {
                $schedule = $this->route('schedule'); // Предполагается, что график передается в route
                if ($schedule && $schedule->overall_progress_percent < 100) {
                    $validator->errors()->add('status', 'Нельзя завершить график с неполным прогрессом');
                }
            }
        });
    }
} 