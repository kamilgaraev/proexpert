<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Auth\LoginRequest;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\ProfileResponse;
use App\Http\Responses\Auth\TokenResponse;
use App\Services\Auth\JwtAuthService;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

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
        // Логируем попытку входа
        Log::info('[AuthController] Admin panel login attempt', [
            'email' => $request->input('email'),
            'ip_address' => $request->ip()
        ]);

        // Временно убираем PerformanceMonitor для отладки
        // return PerformanceMonitor::measure('admin.login', function() use ($request) {
            Log::info('[AuthController] Before calling authService->authenticate');
            try {
                // Создаем DTO из запроса
                $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
                
                // Аутентифицируем пользователя через сервис
                $result = $this->authService->authenticate($loginDTO, $this->guard);
                Log::info('[AuthController] After calling authService->authenticate', ['result_success' => $result['success'] ?? 'N/A']);

                // Проверяем, имеет ли пользователь необходимые права для веб-админки
                if ($result['success']) {
                    /** @var \App\Models\User $user */
                    $user = $result['user'];
                    $organizationId = $user->current_organization_id;
                    
                    Log::info('[AuthController] Auth successful, checking Admin Panel access via Gate...');

                    // Используем новую систему авторизации
                    $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                    $canAccess = $authService->can($user, 'admin.access', ['context_type' => 'system']) ||
                                $authService->can($user, 'admin.access', ['context_type' => 'organization', 'context_id' => $organizationId]);
                    
                    if (!$canAccess) {
                        LogService::authLog('admin_login_forbidden', [
                            'user_id' => $user->id,
                            'email' => $user->email,
                            'organization_id' => $organizationId,
                            'ip' => request()->ip(),
                            'reason' => 'insufficient_permissions'
                        ]);
                        Log::warning('[AuthController] Новая система авторизации denied access.', ['user_id' => $user->id, 'org_id' => $organizationId]);
                        return LoginResponse::forbidden('У вас нет доступа к панели администратора');
                    }

                    Log::info('[AuthController] Новая система авторизации allowed access.');
                    LogService::authLog('admin_login_success', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'organization_id' => $organizationId,
                        'ip' => $request->ip()
                    ]);
                    return LoginResponse::loginSuccess($result['user'], $result['token']); // <-- Возвращаем здесь
                } else {
                    Log::warning('[AuthController] Authentication failed.');
                    // Логируем неудачную попытку из-за неверных данных
                    LogService::authLog('admin_login_failed', [
                        'email' => $request->input('email'), // Логируем email при неверных данных
                        'ip' => $request->ip(),
                        'reason' => 'invalid_credentials'
                    ]);
                    return LoginResponse::unauthorized($result['message']);
                }
                // Эта строка больше не нужна, так как возвраты происходят внутри if/else
                // return LoginResponse::loginSuccess($result['user'], $result['token']);
            } catch (\Throwable $e) {
                Log::error('[AuthController] Unexpected exception in login method', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['success' => false, 'message' => 'Internal Server Error in Controller'], 500);
            }
        // }); // Конец PerformanceMonitor
    }

    /**
     * Получение информации о текущем пользователе.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return PerformanceMonitor::measure('admin.me', function() {
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
        return PerformanceMonitor::measure('admin.refresh_token', function() {
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
        return PerformanceMonitor::measure('admin.logout', function() {
            $result = $this->authService->logout($this->guard);

            if (!$result['success']) {
                return TokenResponse::tokenError($result['message'], $result['status_code']);
            }

            return TokenResponse::invalidated();
        });
    }
}
