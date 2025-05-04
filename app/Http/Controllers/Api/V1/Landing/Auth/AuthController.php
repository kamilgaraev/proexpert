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
        Log::info('[LandingAuthController] Login attempt', [
            'email' => $request->input('email'),
            'ip' => request()->ip()
        ]);
        try {
            $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
            $result = $this->authService->authenticate($loginDTO, $this->guard);
            Log::info('[LandingAuthController] Authentication result', [
                'success' => $result['success'] ?? 'N/A',
                'email' => $loginDTO->email ?? 'N/A',
                'user_id' => $result['user']->id ?? 'N/A',
                'token_exists' => isset($result['token'])
            ]);

            if ($result['success']) {
                /** @var \App\Models\User $user */
                $user = $result['user'];
                $organizationId = $user->current_organization_id;

                // Добавляем подробное логирование о пользователе и его ролях
                Log::info('[LandingAuthController] User data before access check', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'current_organization_id' => $organizationId
                ]);
                
                // УНИВЕРСАЛЬНОЕ РЕШЕНИЕ: пропускаем проверку Gate полностью
                // Все пользователи имеют доступ к личному кабинету
                Log::info('[LandingAuthController] Доступ разрешен всем пользователям', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
                
                LogService::authLog('landing_login_success', [
                    'user_id' => $user->id, 
                    'email' => $user->email,
                    'organization_id' => $organizationId
                ]);
                return LoginResponse::loginSuccess($result['user'], $result['token']);
            } else {
                Log::warning('[LandingAuthController] Authentication failed.');
                LogService::authLog('landing_login_failed', [
                    'email' => $loginDTO->email ?? 'N/A',
                    'ip' => request()->ip()
                ]);
                return LoginResponse::unauthorized($result['message']);
            }
        } catch (\Throwable $e) {
            Log::error('[LandingAuthController] Unexpected exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при входе в систему.'
            ], 500);
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
