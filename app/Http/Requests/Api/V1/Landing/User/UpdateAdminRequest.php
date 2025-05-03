<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Models\Role; // Импортируем Role для использования констант

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

        // Разрешаем, если пользователь - владелец ИЛИ администратор
        // Используем isOrganizationAdmin, который мы исправили ранее
        return $user->isOrganizationAdmin($organizationId);
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