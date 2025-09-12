<?php

namespace App\Domain\Authorization\Http\Requests;

use App\Domain\Authorization\Services\CustomRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCustomRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Авторизация происходит в middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $organizationId = $this->getOrganizationId();
        
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('organization_custom_roles')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
            ],
            'description' => 'nullable|string|max:1000',
            'system_permissions' => 'required|array',
            'system_permissions.*' => 'string|max:100',
            'module_permissions' => 'required|array',
            'module_permissions.*' => 'array',
            'module_permissions.*.*' => 'string|max:100',
            'interface_access' => 'required|array|min:1',
            'interface_access.*' => 'string|in:lk,mobile',
            'conditions' => 'nullable|array',
            'conditions.time' => 'nullable|array',
            'conditions.time.working_hours' => 'nullable|string|regex:/^\d{2}:\d{2}-\d{2}:\d{2}$/',
            'conditions.time.working_days' => 'nullable|array',
            'conditions.time.working_days.*' => 'integer|between:0,6',
            'conditions.budget' => 'nullable|array',
            'conditions.budget.max_amount' => 'nullable|numeric|min:0',
            'conditions.budget.daily_limit' => 'nullable|numeric|min:0',
            'conditions.project_count' => 'nullable|array',
            'conditions.project_count.max_projects' => 'nullable|integer|min:1|max:50'
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название роли обязательно',
            'name.unique' => 'Роль с таким названием уже существует в организации',
            'system_permissions.required' => 'Необходимо указать хотя бы одно системное право',
            'module_permissions.required' => 'Необходимо указать модульные права',
            'interface_access.required' => 'Необходимо указать доступ к интерфейсам',
            'interface_access.min' => 'Необходимо выбрать хотя бы один интерфейс',
            'interface_access.*.in' => 'Недопустимый тип интерфейса',
            'conditions.time.working_hours.regex' => 'Рабочие часы должны быть в формате ЧЧ:ММ-ЧЧ:ММ',
            'conditions.time.working_days.*.between' => 'День недели должен быть от 0 (воскресенье) до 6 (суббота)',
            'conditions.budget.max_amount.min' => 'Максимальная сумма не может быть отрицательной',
            'conditions.budget.daily_limit.min' => 'Дневной лимит не может быть отрицательным',
            'conditions.project_count.max_projects.min' => 'Количество проектов должно быть минимум 1',
            'conditions.project_count.max_projects.max' => 'Количество проектов не может превышать 50'
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validatePermissions($validator);
            $this->validateConditions($validator);
        });
    }

    /**
     * Валидировать права роли
     */
    protected function validatePermissions($validator): void
    {
        $organizationId = $this->getOrganizationId();
        
        if (!$organizationId) {
            $validator->errors()->add('organization_id', 'ID организации не найден');
            return;
        }

        $roleService = app(CustomRoleService::class);
        
        // Валидируем системные права
        $systemPermissions = $this->input('system_permissions', []);
        $availableSystemPermissions = array_keys($roleService->getAvailableSystemPermissions($organizationId));
        
        foreach ($systemPermissions as $permission) {
            if ($permission !== '*' && !in_array($permission, $availableSystemPermissions)) {
                $validator->errors()->add('system_permissions', "Недопустимое системное право: $permission");
            }
        }
        
        // Валидируем модульные права
        $modulePermissions = $this->input('module_permissions', []);
        $availableModulePermissions = $roleService->getAvailableModulePermissions($organizationId);
        
        foreach ($modulePermissions as $module => $permissions) {
            if (!isset($availableModulePermissions[$module])) {
                $validator->errors()->add('module_permissions', "Модуль '$module' не активирован для организации");
                continue;
            }
            
            $moduleAvailable = $availableModulePermissions[$module];
            foreach ($permissions as $permission) {
                if ($permission !== '*' && !in_array($permission, $moduleAvailable)) {
                    $validator->errors()->add('module_permissions', "Недопустимое право '$permission' для модуля '$module'");
                }
            }
        }
    }

    /**
     * Валидировать условия роли
     */
    protected function validateConditions($validator): void
    {
        $conditions = $this->input('conditions', []);
        
        // Валидация временных условий
        if (isset($conditions['time']['working_hours'])) {
            $workingHours = $conditions['time']['working_hours'];
            if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $workingHours)) {
                $validator->errors()->add('conditions.time.working_hours', 'Неверный формат рабочих часов');
            } else {
                [$start, $end] = explode('-', $workingHours);
                if (strtotime($start) >= strtotime($end)) {
                    $validator->errors()->add('conditions.time.working_hours', 'Время начала должно быть раньше времени окончания');
                }
            }
        }
        
        // Валидация бюджетных условий
        if (isset($conditions['budget'])) {
            $budget = $conditions['budget'];
            if (isset($budget['daily_limit'], $budget['max_amount']) && 
                $budget['daily_limit'] < $budget['max_amount']) {
                $validator->errors()->add('conditions.budget', 'Дневной лимит не может быть меньше максимальной суммы операции');
            }
        }
    }

    /**
     * Получить ID организации
     */
    protected function getOrganizationId(): ?int
    {
        return $this->route('organization_id') 
            ?? $this->get('organization_id')
            ?? $this->input('organization_id')
            ?? $this->user()?->current_organization_id;
    }
}
