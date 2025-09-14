<?php

namespace App\Domain\Authorization\Http\Requests;

use App\Domain\Authorization\Services\CustomRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomRoleRequest extends FormRequest
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
        $role = $this->route('role');
        
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('organization_custom_roles')
                    ->where('organization_id', $role->organization_id)
                    ->where('is_active', true)
                    ->ignore($role->id)
            ],
            'description' => 'sometimes|nullable|string|max:1000',
            'system_permissions' => 'sometimes|array',
            'system_permissions.*' => 'string|max:100',
            'module_permissions' => 'sometimes|array',
            'module_permissions.*' => 'array',
            'module_permissions.*.*' => 'string|max:100',
            'interface_access' => 'sometimes|required|array|min:1',
            'interface_access.*' => 'string|in:lk,mobile',
            'conditions' => 'sometimes|nullable|array',
            'conditions.time' => 'nullable|array',
            'conditions.time.working_hours' => 'nullable|string|regex:/^\d{2}:\d{2}-\d{2}:\d{2}$/',
            'conditions.time.working_days' => 'nullable|array',
            'conditions.time.working_days.*' => 'integer|between:0,6',
            'conditions.budget' => 'nullable|array',
            'conditions.budget.max_amount' => 'nullable|numeric|min:0',
            'conditions.budget.daily_limit' => 'nullable|numeric|min:0',
            'conditions.project_count' => 'nullable|array',
            'conditions.project_count.max_projects' => 'nullable|integer|min:1|max:50',
            'is_active' => 'sometimes|boolean'
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Роль с таким названием уже существует в организации',
            // Права не обязательны, убираем сообщения о required
            'interface_access.required' => 'Необходимо указать доступ к интерфейсам',
            'interface_access.min' => 'Необходимо выбрать хотя бы один интерфейс',
            'interface_access.*.in' => 'Недопустимый тип интерфейса',
            'conditions.time.working_hours.regex' => 'Рабочие часы должны быть в формате ЧЧ:ММ-ЧЧ:ММ',
            'conditions.time.working_days.*.between' => 'День недели должен быть от 0 (воскресенье) до 6 (суббота)',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validatePermissions($validator);
            $this->validateRoleUsage($validator);
        });
    }

    /**
     * Валидировать права роли
     */
    protected function validatePermissions($validator): void
    {
        $role = $this->route('role');
        $roleService = app(CustomRoleService::class);
        
        // Валидируем системные права, если они переданы
        if ($this->has('system_permissions')) {
            $systemPermissions = $this->input('system_permissions', []);
            $availableSystemPermissions = array_keys($roleService->getAvailableSystemPermissions($role->organization_id));
            
            foreach ($systemPermissions as $permission) {
                if ($permission !== '*' && !in_array($permission, $availableSystemPermissions)) {
                    $validator->errors()->add('system_permissions', "Недопустимое системное право: $permission");
                }
            }
        }
        
        // Валидируем модульные права, если они переданы
        if ($this->has('module_permissions')) {
            $modulePermissions = $this->input('module_permissions', []);
            $availableModulePermissions = $roleService->getAvailableModulePermissions($role->organization_id);
            
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
    }

    /**
     * Валидировать использование роли
     */
    protected function validateRoleUsage($validator): void
    {
        $role = $this->route('role');
        
        // Если деактивируем роль, проверяем, есть ли активные назначения
        if ($this->has('is_active') && !$this->input('is_active')) {
            $activeAssignments = $role->assignments()->active()->count();
            
            if ($activeAssignments > 0) {
                $validator->errors()->add('is_active', "Нельзя деактивировать роль, назначенную $activeAssignments пользователям");
            }
        }
        
        // Если меняем права, предупреждаем об активных пользователях
        if (($this->has('system_permissions') || $this->has('module_permissions')) && 
            $role->assignments()->active()->exists()) {
            
            // Это предупреждение, не ошибка валидации
            // Можно добавить в мета-информацию ответа
        }
    }

    /**
     * Подготовить данные для валидации
     */
    protected function prepareForValidation(): void
    {
        // Убираем пустые массивы из условий
        if ($this->has('conditions')) {
            $conditions = $this->input('conditions');
            $conditions = array_filter($conditions, function ($value) {
                return !empty($value) || $value === null;
            });
            $this->merge(['conditions' => $conditions ?: null]);
        }
    }
}
