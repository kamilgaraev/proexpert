<?php

namespace App\Http\Requests\Api\V1\Landing\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Только аутентифицированный пользователь может обновить свой профиль
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $userId = Auth::id(); // Получаем ID текущего пользователя

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($userId), // Email должен быть уникальным, игнорируя текущего пользователя
            ],
            'phone' => [
                'nullable', // Позволяем передать null для очистки
                'string',
                'max:20',
                'regex:/^(\+7|8)[- ]?\(?[0-9]{3}\)?[- ]?\(?[0-9]{3}[- ]?[0-9]{2}[- ]?[0-9]{2}$/' 
            ],
            'position' => 'nullable|string|max:100',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Валидация для нового аватара
            'remove_avatar' => 'nullable|boolean', // Флаг для удаления текущего аватара
            // Добавьте другие поля профиля, которые пользователь может обновлять
        ];
    }

     /**
     * Кастомные сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Имя обязательно для заполнения',
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email адрес',
            'email.unique' => 'Этот email уже используется другим пользователем',
            'phone.regex' => 'Некорректный формат телефона. Используйте формат +7XXXXXXXXXX или 8XXXXXXXXXX',
            'avatar.image' => 'Файл должен быть изображением.',
            'avatar.mimes' => 'Поддерживаются только форматы: jpeg, png, jpg, gif.',
            'avatar.max' => 'Максимальный размер файла: 2MB.',
        ];
    }
} 