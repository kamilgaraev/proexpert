<?php

namespace App\Http\Requests\Api\V1\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Schedule\TaskTypeEnum;
use App\Enums\Schedule\TaskStatusEnum;
use App\Enums\Schedule\PriorityEnum;

class CreateScheduleTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Авторизация уже проверена в middleware
    }

    public function rules(): array
    {
        return [
            'parent_task_id' => 'nullable|integer|exists:schedule_tasks,id',
            'work_type_id' => 'nullable|integer|exists:work_types,id',
            'assigned_user_id' => 'nullable|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'wbs_code' => 'nullable|string|max:50',
            'task_type' => 'nullable|string|in:task,milestone,summary,container',
            'planned_start_date' => 'required|date',
            'planned_end_date' => 'required|date|after_or_equal:planned_start_date',
            'planned_duration_days' => 'required|integer|min:1',
            'planned_work_hours' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:not_started,in_progress,completed,cancelled,on_hold',
            'priority' => 'nullable|string|in:low,normal,high,critical',
            'estimated_cost' => 'nullable|numeric|min:0',
            'required_resources' => 'nullable|array',
            'constraint_type' => 'nullable|string|max:50',
            'constraint_date' => 'nullable|date',
            'custom_fields' => 'nullable|array',
            'notes' => 'nullable|string|max:2000',
            'tags' => 'nullable|array',
            'level' => 'nullable|integer|min:0',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название задачи обязательно для заполнения',
            'name.max' => 'Название задачи не должно превышать 255 символов',
            'planned_start_date.required' => 'Дата начала обязательна',
            'planned_start_date.date' => 'Неверный формат даты начала',
            'planned_end_date.required' => 'Дата окончания обязательна',
            'planned_end_date.date' => 'Неверный формат даты окончания',
            'planned_end_date.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'planned_duration_days.required' => 'Длительность задачи обязательна',
            'planned_duration_days.min' => 'Длительность задачи должна быть не менее 1 дня',
            'parent_task_id.exists' => 'Указанная родительская задача не найдена',
            'work_type_id.exists' => 'Указанный тип работ не найден',
            'assigned_user_id.exists' => 'Указанный пользователь не найден',
            'estimated_cost.min' => 'Стоимость не может быть отрицательной',
            'planned_work_hours.min' => 'Трудозатраты не могут быть отрицательными',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'task_type' => $this->task_type ?? 'task',
            'status' => $this->status ?? 'not_started',
            'priority' => $this->priority ?? 'normal',
            'level' => $this->level ?? 0,
            'sort_order' => $this->sort_order ?? 0,
        ]);
    }
}