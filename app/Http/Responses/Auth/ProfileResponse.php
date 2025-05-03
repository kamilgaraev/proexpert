<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class ProfileResponse extends ApiResponse
{
    /**
     * Метод для создания ответа с профилем пользователя.
     *
     * @param User $user Пользователь
     * @param string|null $message Сообщение (может быть null)
     * @return static
     */
    public static function userProfile(User $user, ?string $message = null): static
    {
        // Возможно, пользователя нужно обернуть в UserResource?
        // $data = new \App\Http\Resources\UserResource($user);
        $data = ['user' => $user]; // Пока оставляем так
        
        return new static(
            data: $data,
            statusCode: Response::HTTP_OK,
            message: $message
        );
    }

    /**
     * Метод для создания ответа при отсутствии пользователя (404).
     *
     * @param string $message Сообщение об ошибке
     * @return static
     */
    public static function notFound(string $message = 'Пользователь не найден'): static
    {
        return new static(
            data: null,
            statusCode: Response::HTTP_NOT_FOUND,
            message: $message
        );
    }

    // Конструктор родителя используется, свой не нужен.
} 