<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;

class TokenResponse extends ApiResponse
{
    /**
     * Метод для создания ответа при успешном обновлении токена.
     * 
     * @param string $token Обновленный JWT-токен
     * @param string $message Сообщение
     * @return self
     */
    public static function refreshed(string $token, string $message = 'Токен успешно обновлен'): self
    {
        return new self(
            data: ['token' => $token],
            statusCode: 200,
            message: $message
        );
    }

    /**
     * Метод для создания ответа при успешном выходе из системы.
     * 
     * @param string $message Сообщение
     * @return self
     */
    public static function invalidated(string $message = 'Выход выполнен успешно'): self
    {
        return new self(
            data: null,
            statusCode: 200,
            message: $message
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
            data: $data,
            statusCode: $statusCode,
            message: $message
        );
    }

    /**
     * Метод для создания ответа при ошибке обработки токена.
     * 
     * @param string $message Сообщение об ошибке
     * @param int $statusCode HTTP-код ответа
     * @return self
     */
    public static function tokenError(string $message = 'Ошибка при обработке токена', int $statusCode = 401): self
    {
        return new self(
            data: null,
            statusCode: $statusCode,
            message: $message
        );
    }
} 