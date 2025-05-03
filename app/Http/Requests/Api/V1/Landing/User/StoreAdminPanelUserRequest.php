<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Models\Role; // Используем для проверки существования роли

class StoreAdminPanelUserRequest extends FormRequest
{
    // Определяем разрешенные роли для создания через этот запрос
    protected array $allowedRoles = ['web_admin', 'accountant'];

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

        // Разрешаем, если пользователь - владелец ИЛИ администратор организации
        return $user->isOrganizationAdmin($organizationId);
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
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_slug' => [
                'required',
                'string',
                Rule::in($this->allowedRoles), // Роль должна быть из списка разрешенных
                // Дополнительно проверяем, что такая роль вообще существует в БД
                Rule::exists('roles', 'slug')->where(function ($query) {
                    $query->where('type', Role::TYPE_SYSTEM); // Ищем только системные роли
                }),
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