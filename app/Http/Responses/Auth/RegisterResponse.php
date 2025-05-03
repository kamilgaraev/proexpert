<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\User;

class RegisterResponse extends ApiResponse
{
    public static function registerSuccess(User $user, Organization $organization, string $token, string $message = 'Регистрация успешна'): self
    {
        return new self(true, $message, 201, [
            'token' => $token,
            'user' => $user,
            'organization' => $organization,
        ]);
    }

    public static function error(string $message = 'Ошибка при регистрации', int $statusCode = 400, array $errors = []): self
    {
        $data = !empty($errors) ? ['errors' => $errors] : [];
        return new self(false, $message, $statusCode, $data);
    }
} 