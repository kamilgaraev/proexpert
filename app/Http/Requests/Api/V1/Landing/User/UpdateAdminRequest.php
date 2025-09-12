<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
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
        // Получаем ID пользователя из маршрута (предполагаем, что параметр называется 'user')
        $userId = $this->route('user');

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($userId),
            ],
            'password' => 'sometimes|nullable|string|min:8|confirmed',
            // Дополнительно: можно добавить валидацию для других полей, если они есть
        ];
    }
} 