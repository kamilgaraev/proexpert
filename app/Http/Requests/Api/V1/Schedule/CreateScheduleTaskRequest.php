<?php

namespace App\Http\Requests\Api\V1\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use App\Enums\Schedule\TaskTypeEnum;
use App\Enums\Schedule\TaskStatusEnum;
use App\Enums\Schedule\PriorityEnum;
use App\Domain\Authorization\Services\AuthorizationService;

class CreateScheduleTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        Log::info('[CreateScheduleTaskRequest] authorize() START');
        
        $user = $this->user();
        
        if (!$user) {
            Log::warning('[CreateScheduleTaskRequest] No user - returning false');
            return false;
        }
        
        $organizationId = $this->getOrganizationId();
        
        if (!$organizationId) {
            Log::warning('[CreateScheduleTaskRequest] No organization ID - returning false');
            return false;
        }
        
        try {
            $authorizationService = app(AuthorizationService::class);
            
            // Для создания задачи нужно право редактировать график
            $hasPermission = $authorizationService->can($user, 'schedule-management.edit', [
                'organization_id' => $organizationId,
                'context_type' => 'organization'
            ]);
            
            Log::info('[CreateScheduleTaskRequest] Permission check', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'has_permission' => $hasPermission,
            ]);
            
            if (!$hasPermission) {
                Log::warning('[CreateScheduleTaskRequest] Access denied - returning false');
            }
            
            return $hasPermission;
        } catch (\Exception $e) {
            Log::error('[CreateScheduleTaskRequest] EXCEPTION in authorize()', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
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
            'parent_task_id' => 'nullable|integer|exists:schedule_tasks,id',
            'work_type_id' => 'nullable|integer|exists:work_types,id',
            'assigned_user_id' => 'nullable|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'wbs_code' => 'nullable|string|max:50',
            'task_type' => 'nullable|string|in:task,milestone,summary,container',
            'planned_start_date' => 'required|date',
            'planned_end_date' => 'required|date|after_or_equal:planned_start_date',
            'planned_duration_days' => 'nullable|integer|min:1',
            'planned_work_hours' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'measurement_unit_id' => 'nullable|integer|exists:measurement_units,id',
            'status' => 'nullable|string|in:not_started,in_progress,completed,cancelled,on_hold',
            'priority' => 'nullable|string|in:low,normal,high,critical',
            'estimated_cost' => 'nullable|numeric|min:0',
            'required_resources' => 'nullable|array',
            'constraint_type' => 'nullable|string|in:none,must_start_on,must_finish_on,start_no_earlier_than,start_no_later_than,finish_no_earlier_than,finish_no_later_than',
            'constraint_date' => 'nullable|date|required_unless:constraint_type,none,null',
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
            'planned_duration_days.min' => 'Длительность задачи должна быть не менее 1 дня',
            'parent_task_id.exists' => 'Указанная родительская задача не найдена',
            'work_type_id.exists' => 'Указанный тип работ не найден',
            'assigned_user_id.exists' => 'Указанный пользователь не найден',
            'estimated_cost.min' => 'Стоимость не может быть отрицательной',
            'planned_work_hours.min' => 'Трудозатраты не могут быть отрицательными',
            'measurement_unit_id.exists' => 'Указанная единица измерения не найдена',
            'constraint_date.required_unless' => 'Дата ограничения обязательна при указании типа ограничения',
        ];
    }

    public function prepareForValidation(): void
    {
        // Устанавливаем значения по умолчанию только если они не были переданы
        $defaults = [];
        
        if (!$this->has('task_type')) {
            $defaults['task_type'] = 'task';
        }
        
        if (!$this->has('status')) {
            $defaults['status'] = 'not_started';
        }
        
        if (!$this->has('priority')) {
            $defaults['priority'] = 'normal';
        }
        
        if (!$this->has('level')) {
            $defaults['level'] = 0;
        }
        
        if (!$this->has('sort_order')) {
            $defaults['sort_order'] = 0;
        }
        
        if (!$this->has('constraint_type')) {
            $defaults['constraint_type'] = 'none';
        }
        
        if (!empty($defaults)) {
            $this->merge($defaults);
        }
    }
}