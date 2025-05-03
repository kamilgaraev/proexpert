<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\User;

class LoginResponse extends ApiResponse
{
    public static function success(User $user, string $token, string $message = 'Вход выполнен успешно'): self
    {
        return new self(true, $message, 200, [
            'token' => $token,
            'user' => $user,
        ]);
    }

    public static function unauthorized(string $message = 'Неверный email или пароль'): self
    {
        return new self(false, $message, 401);
    }

    public static function forbidden(string $message = 'У вас нет доступа к данному ресурсу'): self
    {
        return new self(false, $message, 403);
    }
} 