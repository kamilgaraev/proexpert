<?php

namespace App\Http\Requests\Api\V1\Mobile\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для выполнения запроса.
     *
     * @return bool
     */
    public function authorize()
    {
        // Обычно для логина возвращаем true, авторизация проверяется позже
        return true; 
    }

    /**
     * Правила валидации для запроса.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ];
    }

    /**
     * Кастомные сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'email.required' => 'Email обязателен для заполнения',
            'email.email' => 'Введите корректный email адрес',
            'password.required' => 'Пароль обязателен для заполнения',
        ];
    }
} 