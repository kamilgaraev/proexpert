<?php

namespace App\Http\Requests\Api\V1\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use App\Domain\Authorization\Services\AuthorizationService;

class UpdateScheduleTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        
        if (!$user) {
            return false;
        }
        
        $organizationId = $this->getOrganizationId();
        
        if (!$organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        // Для обновления задачи требуется право на редактирование графика
        return $authorizationService->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }
    
    protected function getOrganizationId(): ?int
    {
        $user = $this->user();
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        return $organizationId ? (int) $organizationId : null;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|nullable',
            'wbs_code' => 'sometimes|string|max:50|nullable',
            
            // Плановые даты
            'planned_start_date' => 'sometimes|date|nullable',
            'planned_end_date' => 'sometimes|date|nullable|after_or_equal:planned_start_date',
            'planned_duration_days' => 'sometimes|integer|min:1|nullable',
            'planned_work_hours' => 'sometimes|numeric|min:0|nullable',
            
            // Фактические даты
            'actual_start_date' => 'sometimes|date|nullable',
            'actual_end_date' => 'sometimes|date|nullable|after_or_equal:actual_start_date',
            'actual_work_hours' => 'sometimes|numeric|min:0|nullable',
            
            // Прогресс и статус
            'progress_percent' => 'sometimes|numeric|min:0|max:100',
            'status' => 'sometimes|string|in:not_started,in_progress,completed,cancelled,on_hold',
            
            // Приоритет и тип
            'priority' => 'sometimes|string|in:low,normal,high,critical',
            'task_type' => 'sometimes|string|in:task,milestone,summary,container',
            
            // Затраты
            'estimated_cost' => 'sometimes|numeric|min:0|nullable',
            'actual_cost' => 'sometimes|numeric|min:0|nullable',
            
            // Связи
            'assigned_user_id' => 'sometimes|integer|exists:users,id|nullable',
            'work_type_id' => 'sometimes|integer|exists:work_types,id|nullable',
            'parent_task_id' => 'sometimes|integer|exists:schedule_tasks,id|nullable',
            
            // Ограничения
            'constraint_type' => 'sometimes|string|in:none,must_start_on,must_finish_on,start_no_earlier_than,start_no_later_than,finish_no_earlier_than,finish_no_later_than|nullable',
            'constraint_date' => 'sometimes|date|nullable|required_unless:constraint_type,none,null',
            
            // Дополнительные поля
            'notes' => 'sometimes|string|nullable',
            'custom_fields' => 'sometimes|array|nullable',
            'tags' => 'sometimes|array|nullable',
            'sort_order' => 'sometimes|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название задачи обязательно',
            'planned_end_date.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'actual_end_date.after_or_equal' => 'Фактическая дата окончания должна быть не раньше даты начала',
            'progress_percent.min' => 'Прогресс не может быть меньше 0%',
            'progress_percent.max' => 'Прогресс не может быть больше 100%',
            'constraint_date.required_unless' => 'Дата ограничения обязательна при указании типа ограничения',
        ];
    }
}

