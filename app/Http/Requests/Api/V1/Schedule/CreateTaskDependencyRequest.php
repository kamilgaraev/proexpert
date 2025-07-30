<?php

namespace App\Http\Requests\Api\V1\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Schedule\DependencyTypeEnum;
use Illuminate\Validation\Rule;

class CreateTaskDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Авторизация уже проверена в middleware
    }

    public function rules(): array
    {
        return [
            'predecessor_task_id' => 'required|integer|exists:schedule_tasks,id',
            'successor_task_id' => [
                'required',
                'integer',
                'exists:schedule_tasks,id',
                'different:predecessor_task_id'
            ],
            'dependency_type' => [
                'required',
                'string',
                Rule::in(['FS', 'SS', 'FF', 'SF'])
            ],
            'lag_days' => 'nullable|integer',
            'lag_hours' => 'nullable|numeric|min:-999|max:999',
            'lag_type' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'is_hard_constraint' => 'nullable|boolean',
            'priority' => 'nullable|string|in:low,normal,high,critical',
            'constraint_reason' => 'nullable|string|max:500',
            'advanced_settings' => 'nullable|array',
            'advanced_settings.*' => 'string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'predecessor_task_id.required' => 'Предшествующая задача обязательна',
            'predecessor_task_id.exists' => 'Указанная предшествующая задача не найдена',
            'successor_task_id.required' => 'Последующая задача обязательна',
            'successor_task_id.exists' => 'Указанная последующая задача не найдена',
            'successor_task_id.different' => 'Последующая задача должна отличаться от предшествующей',
            'dependency_type.required' => 'Тип зависимости обязателен',
            'dependency_type.in' => 'Недопустимый тип зависимости. Допустимые: FS, SS, FF, SF',
            'lag_hours.min' => 'Задержка не может быть меньше -999 часов',
            'lag_hours.max' => 'Задержка не может быть больше 999 часов',
            'description.max' => 'Описание не должно превышать 1000 символов',
            'constraint_reason.max' => 'Причина ограничения не должна превышать 500 символов',
            'priority.in' => 'Приоритет должен быть: low, normal, high или critical',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $scheduleId = (int) $this->route('id'); // Получаем schedule_id из URL и приводим к int
            
            // Проверяем что обе задачи принадлежат одному расписанию
            $predecessorTask = \App\Models\ScheduleTask::find($this->predecessor_task_id);
            $successorTask = \App\Models\ScheduleTask::find($this->successor_task_id);
            
            // Добавляем детальную отладочную информацию
            if ($predecessorTask && $predecessorTask->schedule_id !== $scheduleId) {
                $validator->errors()->add('predecessor_task_id', 
                    "Предшествующая задача (ID: {$predecessorTask->id}) принадлежит расписанию {$predecessorTask->schedule_id}, но ожидается {$scheduleId}"
                );
            }
            
            if ($successorTask && $successorTask->schedule_id !== $scheduleId) {
                $validator->errors()->add('successor_task_id', 
                    "Последующая задача (ID: {$successorTask->id}) принадлежит расписанию {$successorTask->schedule_id}, но ожидается {$scheduleId}"
                );
            }
            
            // Проверяем что задачи найдены
            if (!$predecessorTask) {
                $validator->errors()->add('predecessor_task_id', 'Предшествующая задача не найдена в базе данных');
            }
            
            if (!$successorTask) {
                $validator->errors()->add('successor_task_id', 'Последующая задача не найдена в базе данных');
            }
            
            // Проверяем на циклические зависимости
            if ($predecessorTask && $successorTask && $this->wouldCreateCycle($predecessorTask->id, $successorTask->id)) {
                $validator->errors()->add('successor_task_id', 'Создание данной зависимости приведет к циклической зависимости');
            }
            
            // Проверяем на дублирование зависимостей
            if ($predecessorTask && $successorTask) {
                $existingDependency = \App\Models\TaskDependency::where('predecessor_task_id', $predecessorTask->id)
                    ->where('successor_task_id', $successorTask->id)
                    ->where('is_active', true)
                    ->exists();
                    
                if ($existingDependency) {
                    $validator->errors()->add('successor_task_id', 'Зависимость между этими задачами уже существует');
                }
            }
        });
    }

    protected function wouldCreateCycle(int $predecessorId, int $successorId): bool
    {
        // Простая проверка на прямую обратную зависимость
        $reverseDependency = \App\Models\TaskDependency::where('predecessor_task_id', $successorId)
            ->where('successor_task_id', $predecessorId)
            ->where('is_active', true)
            ->exists();
            
        return $reverseDependency;
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'lag_days' => $this->lag_days ?? 0,
            'lag_hours' => $this->lag_hours ?? 0.0,
            'lag_type' => $this->lag_type ?? 'working_days',
            'is_hard_constraint' => $this->is_hard_constraint ?? false,
            'priority' => $this->priority ?? 'normal',
        ]);
    }
}