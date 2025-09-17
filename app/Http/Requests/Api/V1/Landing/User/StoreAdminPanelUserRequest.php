<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
     * Авторизация уже проверена middleware, здесь только базовая проверка.
     */
    public function authorize(): bool
    {
        // Middleware уже проверил права, здесь только базовая проверка на аутентификацию
        return Auth::check();
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