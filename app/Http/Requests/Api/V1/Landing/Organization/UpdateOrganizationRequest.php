<?php

namespace App\Http\Requests\Api\V1\Landing\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Проверяем, что пользователь авторизован и имеет роль 'organization_owner'
        // Реальная проверка роли должна быть в middleware или сервисе
        return Auth::check();
        // return Auth::check() && Auth::user()->hasRole('organization_owner'); // Пример
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
            'address' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            // Добавьте другие поля организации, которые можно обновлять
        ];
    }
} 