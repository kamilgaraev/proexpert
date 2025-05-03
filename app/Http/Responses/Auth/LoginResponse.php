<?php

namespace App\Http\Responses\Auth;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse extends ApiResponse
{
    /**
     * Метод для создания успешного ответа при входе.
     * 
     * @param User $user Аутентифицированный пользователь
     * @param string $token JWT-токен
     * @param string $message Сообщение
     * @return static
     */
    public static function loginSuccess(User $user, string $token, string $message = 'Вход выполнен успешно'): static
    {
        $data = [
            'token' => $token,
            'user' => $user,
        ];
        return new static(
            data: $data,
            statusCode: Response::HTTP_OK,
            message: $message
        );
    }
    
    /**
     * Метод для создания ответа при неудачной авторизации.
     * 
     * @param string $message Сообщение об ошибке
     * @return static
     */
    public static function unauthorized(string $message = 'Неверный email или пароль'): static
    {
        return new static(
            data: null,
            statusCode: Response::HTTP_UNAUTHORIZED,
            message: $message
        );
    }

    /**
     * Метод для создания ответа при отсутствии прав доступа.
     * 
     * @param string $message Сообщение об ошибке
     * @return static
     */
    public static function forbidden(string $message = 'У вас нет доступа к данному ресурсу'): static
    {
        return new static(
            data: null,
            statusCode: Response::HTTP_FORBIDDEN,
            message: $message
        );
    }
} 