<?php

namespace App\Http\Requests\Api\V1\Landing\AdminPanelUser;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateAdminPanelUserRequest extends FormRequest
{
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

        // Получаем ID контекста авторизации для организации
        $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        $contextId = $authContext ? $authContext->id : null;

        // Проверяем права через новую систему авторизации
        $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
        return $authService->can($user, 'organization.manage', ['context_type' => 'organization', 'context_id' => $organizationId]) ||
               $authService->hasRole($user, 'organization_owner', $contextId) ||
               $authService->hasRole($user, 'organization_admin', $contextId);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'password' => 'sometimes|required|string|min:8|confirmed',
            // Email не включаем, так как его изменение может быть рискованным
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
            'name.required' => 'Необходимо указать имя пользователя',
            'password.min' => 'Пароль должен содержать минимум 8 символов',
            'password.confirmed' => 'Подтверждение пароля не совпадает с паролем',
        ];
    }
} 