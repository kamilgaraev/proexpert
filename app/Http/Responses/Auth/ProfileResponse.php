<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\User;

class ProfileResponse extends ApiResponse
{
    public static function success(User $user, string $message = ''): self
    {
        return new self(true, $message, 200, [
            'user' => $user,
        ]);
    }

    public static function notFound(string $message = 'Пользователь не найден'): self
    {
        return new self(false, $message, 404);
    }
} 