<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\User;

class RegisterResponse extends ApiResponse
{
    /**
     * Метод для создания успешного ответа при регистрации.
     * 
     * @param User $user Зарегистрированный пользователь
     * @param Organization $organization Созданная организация
     * @param string $token JWT-токен
     * @param string $message Сообщение
     * @return self
     */
    public static function registerSuccess(User $user, Organization $organization, string $token, string $message = 'Регистрация успешна'): self
    {
        $data = [
            'token' => $token,
            'user' => $user,
            'organization' => $organization,
        ];
        return new self(
            data: $data,        // 1. data
            statusCode: 201,    // 2. statusCode
            message: $message   // 3. message
        );
    }
    
    /**
     * Переопределяем метод для совместимости с родительским классом.
     * Этот метод требуется для совместимости с ApiResponse.
     *
     * @param string $message Сообщение об успешной операции
     * @param array $data Дополнительные данные
     * @param int $statusCode HTTP-код ответа
     * @return self
     */
    public static function success(string $message = 'Операция выполнена успешно', array $data = [], int $statusCode = 200): self
    {
        return new self(
            data: $data,        // 1. data
            statusCode: $statusCode, // 2. statusCode
            message: $message   // 3. message
        );
    }

    /**
     * Метод для создания ответа с ошибкой.
     * 
     * @param string $message Сообщение об ошибке
     * @param int $statusCode HTTP-код ответа
     * @param array $errors Массив ошибок
     * @return self
     */
    public static function error(string $message = 'Ошибка при регистрации', int $statusCode = 400, array $errors = []): self
    {
        $data = !empty($errors) ? ['errors' => $errors] : null; // Данные - это ошибки, или null
        return new self(
            data: $data,        // 1. data (ошибки или null)
            statusCode: $statusCode, // 2. statusCode
            message: $message   // 3. message
        );
    }
} 