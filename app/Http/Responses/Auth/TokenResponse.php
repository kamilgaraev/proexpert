<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;

class TokenResponse extends ApiResponse
{
    public static function refreshed(string $token, string $message = 'Токен успешно обновлен'): self
    {
        return new self(true, $message, 200, [
            'token' => $token,
        ]);
    }

    public static function invalidated(string $message = 'Выход выполнен успешно'): self
    {
        return new self(true, $message, 200);
    }

    public static function tokenError(string $message = 'Ошибка при обработке токена', int $statusCode = 401): self
    {
        return new self(false, $message, $statusCode);
    }
} 