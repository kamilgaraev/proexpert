<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Auth\LoginRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Auth\JwtAuthService;
use App\Services\LogService;
use App\Services\Logging\LoggingService;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    protected $authService;
    protected $logging;
    protected $guard = 'api_admin';

    /**
     * Создание нового экземпляра контроллера.
     *
     * @param JwtAuthService $authService
     * @param LoggingService $logging
     */
    public function __construct(JwtAuthService $authService, LoggingService $logging)
    {
        $this->authService = $authService;
        $this->logging = $logging;
    }

    /**
     * Вход пользователя.
     *
     * @param LoginRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        // Логируем попытку входа в админ-панель - критическое security событие
        $this->logging->security('auth.admin.login.attempt', [
            'email' => $request->input('email'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'interface' => 'admin_panel'
        ], 'info');

        try {
            // Создаем DTO из запроса
            $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
            
            // Аутентифицируем пользователя через сервис
            $result = $this->authService->authenticate($loginDTO, $this->guard);

            // Проверяем, имеет ли пользователь необходимые права для веб-админки
            if ($result['success']) {
                /** @var \App\Models\User $user */
                $user = $result['user'];
                $organizationId = $user->current_organization_id;
                
                // Контекст пользователя передается в параметрах security логирования
                
                $this->logging->security('auth.admin.credentials.success', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'organization_id' => $organizationId
                ]);

                // Используем новую систему авторизации
                $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                
                // Проверяем роли пользователя
                try {
                    $userRoles = $authService->getUserRoles($user);
                } catch (\Exception $e) {
                    $this->logging->security('auth.admin.roles.error', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e)
                    ], 'error');
                }
                
                $systemAccess = $authService->can($user, 'admin.access', ['context_type' => 'system']);
                $orgAccess = $authService->can($user, 'admin.access', ['context_type' => 'organization', 'organization_id' => $organizationId]);
                
                $canAccess = $systemAccess || $orgAccess;
                
                if (!$canAccess) {
                    // SECURITY: Попытка доступа в админ-панель без разрешений - критическое событие
                    $this->logging->security('auth.admin.access.denied', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'organization_id' => $organizationId,
                        'system_access' => $systemAccess,
                        'org_access' => $orgAccess,
                        'reason' => 'insufficient_permissions'
                    ], 'warning');
                    
                    // AUDIT: Отказ в доступе нужно записать в audit trail
                    $this->logging->audit('auth.admin.access.denied', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'organization_id' => $organizationId,
                        'interface' => 'admin_panel'
                    ]);
                    
                    return AdminResponse::error(trans_message('auth.access_denied'), Response::HTTP_FORBIDDEN);
                }

                // SECURITY & AUDIT: Успешный вход в админ-панель - критическое событие для compliance
                $this->logging->security('auth.admin.login.success', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'organization_id' => $organizationId,
                    'system_access' => $systemAccess,
                    'org_access' => $orgAccess,
                    'interface' => 'admin_panel'
                ]);
                
                $this->logging->audit('auth.admin.login.success', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'organization_id' => $organizationId,
                    'interface' => 'admin_panel',
                    'token_generated' => true
                ]);

                /** @var \Tymon\JWTAuth\JWTGuard $guard */
                $guard = auth($this->guard);
                
                return AdminResponse::success([
                    'user' => $result['user'],
                    'token' => $result['token'],
                    'token_type' => 'bearer',
                    'expires_in' => $guard->factory()->getTTL() * 60
                ], trans_message('auth.login_success'));
            } else {
                // SECURITY: Неуспешная аутентификация - потенциальная атака
                $this->logging->security('auth.admin.login.failed', [
                    'email' => $request->input('email'),
                    'reason' => 'invalid_credentials',
                    'error_message' => $result['message'] ?? 'Authentication failed'
                ], 'warning');
                
                return AdminResponse::error($result['message'] ?? trans_message('auth.login_failed'), Response::HTTP_UNAUTHORIZED);
            }
        } catch (\Throwable $e) {
            // TECHNICAL: Системная ошибка при аутентификации
            $this->logging->technical('auth.admin.login.exception', [
                'email' => $request->input('email'),
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');
            
            return AdminResponse::error(trans_message('auth.server_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
                return AdminResponse::error($result['message'] ?? trans_message('auth.profile_not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(['user' => $result['user']]);
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
                return AdminResponse::error($result['message'] ?? __('auth.token_error'), $result['status_code'] ?? Response::HTTP_UNAUTHORIZED);
            }

            /** @var \Tymon\JWTAuth\JWTGuard $guard */
            $guard = auth($this->guard);

            return AdminResponse::success([
                'token' => $result['token'],
                'token_type' => 'bearer',
                'expires_in' => $guard->factory()->getTTL() * 60
            ], trans_message('auth.token_refreshed'));
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
                return AdminResponse::error($result['message'] ?? trans_message('auth.token_error'), $result['status_code'] ?? Response::HTTP_UNAUTHORIZED);
            }

            return AdminResponse::success(null, trans_message('auth.logout_success'));
        });
    }
}
