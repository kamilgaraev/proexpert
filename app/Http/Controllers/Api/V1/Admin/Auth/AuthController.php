<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Auth\LoginRequest;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\ProfileResponse;
use App\Http\Responses\Auth\TokenResponse;
use App\Services\Auth\JwtAuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected $authService;
    protected $guard = 'api_admin';

    /**
     * Создание нового экземпляра контроллера.
     *
     * @param JwtAuthService $authService
     */
    public function __construct(JwtAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Вход пользователя.
     *
     * @param LoginRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        // Создаем DTO из запроса
        $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
        
        // Аутентифицируем пользователя через сервис
        $result = $this->authService->authenticate($loginDTO, $this->guard);

        // Проверяем, имеет ли пользователь необходимые права для веб-админки
        if ($result['success']) {
            $user = $result['user'];
            $organizationId = $user->current_organization_id;
            
            // Проверяем, является ли пользователь системным администратором или администратором организации
            if (!$user->isSystemAdmin() && !$user->isOrganizationAdmin($organizationId)) {
                return LoginResponse::forbidden('У вас нет доступа к панели администратора');
            }
        } else {
            return LoginResponse::unauthorized($result['message']);
        }

        return LoginResponse::loginSuccess($result['user'], $result['token']);
    }

    /**
     * Получение информации о текущем пользователе.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        $result = $this->authService->me($this->guard);

        if (!$result['success']) {
            return ProfileResponse::notFound($result['message']);
        }

        return ProfileResponse::userProfile($result['user']);
    }

    /**
     * Обновление токена.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        $result = $this->authService->refresh($this->guard);

        if (!$result['success']) {
            return TokenResponse::tokenError($result['message'], $result['status_code']);
        }

        return TokenResponse::refreshed($result['token']);
    }

    /**
     * Выход пользователя.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        $result = $this->authService->logout($this->guard);

        if (!$result['success']) {
            return TokenResponse::tokenError($result['message'], $result['status_code']);
        }

        return TokenResponse::invalidated();
    }
}
