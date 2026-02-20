<?php

namespace App\Http\Requests\Api\V1\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Авторизация уже проверена в middleware
    }

    public function rules(): array
    {
        return [
            'predecessor_task_id' => 'nullable|integer|exists:schedule_tasks,id',
            'successor_task_id' => [
                'nullable',
                'integer',
                'exists:schedule_tasks,id',
                'different:predecessor_task_id'
            ],
            'dependency_type' => [
                'nullable',
                'string',
                Rule::in(['FS', 'SS', 'FF', 'SF'])
            ],
            'lag_days' => 'nullable|integer',
            'lag_hours' => 'nullable|numeric|min:-999|max:999',
            'lag_type' => [
                'nullable',
                'string',
                Rule::in(['days', 'hours', 'percent'])
            ],
            'description' => 'nullable|string|max:1000',
            'is_hard_constraint' => 'nullable|boolean',
            'priority' => 'nullable',
            'constraint_reason' => 'nullable|string|max:500',
            'advanced_settings' => 'nullable|array',
            'advanced_settings.*' => 'string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'predecessor_task_id.exists' => 'Указанная предшествующая задача не найдена',
            'successor_task_id.exists' => 'Указанная последующая задача не найдена',
            'successor_task_id.different' => 'Последующая задача должна отличаться от предшествующей',
            'dependency_type.in' => 'Недопустимый тип зависимости. Допустимые: FS, SS, FF, SF',
            'lag_type.in' => 'Недопустимый тип единицы измерения лага. Допустимые: days, hours, percent',
            'lag_hours.min' => 'Задержка не может быть меньше -999 часов',
            'lag_hours.max' => 'Задержка не может быть больше 999 часов',
            'description.max' => 'Описание не должно превышать 1000 символов',
            'constraint_reason.max' => 'Причина ограничения не должна превышать 500 символов',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $scheduleId = (int) $this->route('schedule');
            $dependencyId = (int) $this->route('dependency');
            
            $predecessorTaskId = $this->has('predecessor_task_id') ? $this->predecessor_task_id : null;
            $successorTaskId = $this->has('successor_task_id') ? $this->successor_task_id : null;
            
            if ($predecessorTaskId) {
                $predecessorTask = \App\Models\ScheduleTask::find($predecessorTaskId);
                if ($predecessorTask && $predecessorTask->schedule_id !== $scheduleId) {
                    $validator->errors()->add('predecessor_task_id', 'Предшествующая задача не принадлежит данному расписанию');
                }
            }
            
            if ($successorTaskId) {
                $successorTask = \App\Models\ScheduleTask::find($successorTaskId);
                if ($successorTask && $successorTask->schedule_id !== $scheduleId) {
                    $validator->errors()->add('successor_task_id', 'Последующая задача не принадлежит данному расписанию');
                }
            }
            
            // Если меняем задачи - нужно проверить на дубли и циклы
            // Мы это сделаем в контроллере/сервисе для надежности, чтобы иметь доступ к полному объекту модели.
        });
    }

    public function prepareForValidation(): void
    {
        if ($this->has('priority') && !is_numeric($this->priority)) {
            $this->merge([
                'priority' => \App\Enums\Schedule\PriorityEnum::from($this->priority ?? 'normal')->weight(),
            ]);
        }
    }
}
