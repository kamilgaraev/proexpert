<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreAdminPanelUserRequest extends FormRequest
{
    // Определяем разрешенные роли для создания через этот запрос
    protected array $allowedRoles = [
        'super_admin',
        'admin',
        'content_admin',
        'support_admin',
        'web_admin',
        'accountant',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $organizationId = $user->current_organization_id;
        if (!$organizationId) {
            return false;
        }

        // Проверяем права через новую систему авторизации
        $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
        return $authService->can($user, 'organization.manage', ['context_type' => 'organization', 'context_id' => $organizationId]) ||
               $authService->hasRole($user, 'organization_owner', $organizationId) ||
               $authService->hasRole($user, 'organization_admin', $organizationId);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            'password' => 'required|string|min:8|confirmed',
            'role_slug' => [
                'required',
                'string',
                Rule::in($this->allowedRoles), // Роль должна быть из списка разрешенных
                // Проверяем, что такая роль существует в новой системе авторизации
                function ($attribute, $value, $fail) {
                    $roleScanner = app(\App\Domain\Authorization\Services\RoleScanner::class);
                    $allRoles = $roleScanner->getAllRoles();
                    
                    if (!isset($allRoles[$value])) {
                        $fail("Роль '{$value}' не найдена в системе авторизации.");
                    }
                },
            ],
            // Можно добавить другие поля при необходимости
        ];
    }

     /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'role_slug.required' => 'Необходимо указать роль пользователя.',
            'role_slug.in' => 'Выбрана недопустимая роль. Разрешенные роли: ' . implode(', ', $this->allowedRoles) . '.',
            'role_slug.exists' => 'Указанная роль не найдена в системе.',
        ];
    }
} 