<?php

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Landing\Auth\RegisterRequest;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\ProfileResponse;
use App\Http\Responses\Auth\RegisterResponse;
use App\Http\Responses\Auth\TokenResponse;
use App\Services\Auth\JwtAuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected $authService;
    protected $guard = 'api_landing';

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
     * Регистрация нового пользователя и организации.
     *
     * @param RegisterRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(RegisterRequest $request)
    {
        // Создаем DTO из запроса
        $registerDTO = RegisterDTO::fromRequest($request->all());
        
        // Выполняем регистрацию через сервис
        $result = $this->authService->register($registerDTO);

        if (!$result['success']) {
            return RegisterResponse::error($result['message'], $result['status_code']);
        }

        return RegisterResponse::registerSuccess(
            $result['user'],
            $result['organization'],
            $result['token']
        );
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

        if (!$result['success']) {
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
