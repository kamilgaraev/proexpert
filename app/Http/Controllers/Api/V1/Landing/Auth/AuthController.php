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
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Services\LogService;
use Illuminate\Support\Facades\Auth;

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
        return PerformanceMonitor::measure('landing.register', function() use ($request) {
            // Создаем DTO из запроса
            $registerDTO = RegisterDTO::fromRequest($request->all());
            
            // Выполняем регистрацию через сервис
            $result = $this->authService->register($registerDTO);

            if (!$result['success']) {
                return RegisterResponse::error($result['message'], $result['status_code']);
            }

            // Проверяем, что все необходимые данные присутствуют
            if (!isset($result['user']) || !isset($result['organization']) || !isset($result['token'])) {
                Log::error('[LandingAuthController] Missing data in registration result', [
                    'has_user' => isset($result['user']),
                    'has_organization' => isset($result['organization']),
                    'has_token' => isset($result['token'])
                ]);
                return RegisterResponse::error('Ошибка регистрации: неполные данные', 500);
            }

            return RegisterResponse::registerSuccess(
                $result['user'],
                $result['organization'],
                $result['token']
            );
        });
    }

    /**
     * Вход пользователя.
     *
     * @param LoginRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        Log::info('[LandingAuthController] Login attempt', [/*...*/]);
        try {
            $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
            $result = $this->authService->authenticate($loginDTO, $this->guard);
            Log::info('[LandingAuthController] Authentication result', ['success' => $result['success'] ?? 'N/A']);

            if ($result['success']) {
                /** @var \App\Models\User $user */
                $user = $result['user'];
                $organizationId = $user->current_organization_id;

                Log::info('[LandingAuthController] Auth successful, checking Landing access via Gate...');
                // Используем Gate для проверки прав доступа к ЛК
                if (Gate::denies('access-landing', [$organizationId])) { // Исправлено: Передаем $organizationId в массиве
                    LogService::authLog('landing_login_forbidden', [/*...*/]);
                    Log::warning('[LandingAuthController] Gate \'access-landing\' denied access.', [/*...*/]);
                    // Используем наш сервис для инвалидации JWT токена без логирования стандартного logout
                    $this->authService->logout($this->guard, false); 
                    return LoginResponse::forbidden('У вас нет доступа к личному кабинету этой организации');
                }

                Log::info('[LandingAuthController] Gate \'access-landing\' allowed access.');
                LogService::authLog('landing_login_success', [/*...*/]);
                return LoginResponse::loginSuccess($result['user'], $result['token']);
            } else {
                Log::warning('[LandingAuthController] Authentication failed.');
                LogService::authLog('landing_login_failed', [/*...*/]);
                return LoginResponse::unauthorized($result['message']);
            }
        } catch (\Throwable $e) {
            Log::error('[LandingAuthController] Unexpected exception', [/*...*/]);
            return response()->json([/*...*/], 500);
        }
    }

    /**
     * Получение информации о текущем пользователе.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return PerformanceMonitor::measure('landing.me', function() {
            $result = $this->authService->me($this->guard);

            if (!$result['success']) {
                return ProfileResponse::notFound($result['message']);
            }

            return ProfileResponse::userProfile($result['user']);
        });
    }

    /**
     * Обновление токена.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return PerformanceMonitor::measure('landing.refresh_token', function() {
            $result = $this->authService->refresh($this->guard);

            if (!$result['success']) {
                return TokenResponse::tokenError($result['message'], $result['status_code']);
            }

            return TokenResponse::refreshed($result['token']);
        });
    }

    /**
     * Выход пользователя.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        return PerformanceMonitor::measure('landing.logout', function() {
            $result = $this->authService->logout($this->guard);

            if (!$result['success']) {
                return TokenResponse::tokenError($result['message'], $result['status_code']);
            }

            return TokenResponse::invalidated();
        });
    }
}
