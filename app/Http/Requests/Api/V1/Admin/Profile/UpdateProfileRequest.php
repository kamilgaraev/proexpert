<?php

namespace App\Http\Requests\Api\V1\Admin\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $userId = Auth::id();

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId)
            ],
            'phone' => 'sometimes|nullable|string|max:20',
            'avatar' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'remove_avatar' => 'sometimes|boolean',
            'password' => 'sometimes|nullable|string|min:8|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Необходимо указать имя',
            'name.max' => 'Имя не должно превышать 255 символов',
            'email.required' => 'Необходимо указать email',
            'email.email' => 'Некорректный формат email',
            'email.unique' => 'Данный email уже используется',
            'phone.max' => 'Номер телефона не должен превышать 20 символов',
            'avatar.image' => 'Файл должен быть изображением',
            'avatar.mimes' => 'Допустимые форматы изображений: jpeg, png, jpg, gif',
            'avatar.max' => 'Размер изображения не должен превышать 2MB',
            'password.min' => 'Пароль должен содержать минимум 8 символов',
            'password.confirmed' => 'Подтверждение пароля не совпадает',
        ];
    }
} 