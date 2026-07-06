<?php

declare(strict_types=1);

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\User;

class RegisterResponse extends ApiResponse
{
    public static function verificationRequired(User $user, Organization $organization): self
    {
        return new self(
            data: [
                'status' => 'verification_required',
                'email_verified' => false,
                'can_enter_portal' => false,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ],
                'email' => $user->email,
            ],
            statusCode: 201,
            message: trans_message('auth.registration_verification_required')
        );
    }

    public static function success(string $message = '', array $data = [], int $statusCode = 200): self
    {
        return new self(
            data: $data,
            statusCode: $statusCode,
            message: $message
        );
    }

    public static function error(string $message = '', int $statusCode = 400, array $errors = []): self
    {
        return new self(
            data: $errors === [] ? null : ['errors' => $errors],
            statusCode: $statusCode,
            message: $message === '' ? trans_message('auth.registration_incomplete_data') : $message
        );
    }
}
