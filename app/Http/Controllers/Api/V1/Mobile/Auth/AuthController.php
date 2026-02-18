<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\Auth\LoginRequest;
use App\Http\Responses\MobileResponse;
use App\Http\Resources\Api\V1\Mobile\Auth\MobileUserResource;
use App\Services\Auth\JwtAuthService;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    protected JwtAuthService $authService;
    protected string $guard = 'api_mobile';

    public function __construct(JwtAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Вход пользователя.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            Log::info('[MobileAuthController] Login attempt', ['email' => $request->email]);
            
            $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
            $result = $this->authService->authenticate($loginDTO, $this->guard);

            if ($result['success']) {
                /** @var \App\Models\User $user */
                $user = $result['user'];
                $organizationId = $user->current_organization_id;

                if (Gate::forUser($user)->denies('access-mobile-app', [$organizationId])) {
                    $this->authService->logout($this->guard, false);
                    return MobileResponse::error('У вас нет доступа к мобильному приложению', 403);
                }

                LogService::authLog('mobile_login_success', [
                    'user_id' => $user->id,
                    'device_info' => $request->header('User-Agent'),
                    'app_version' => $request->header('X-App-Version', 'unknown')
                ]);

                // Загружаем организации для MobileUserResource
                $user->load('organizations');

                return MobileResponse::success([
                    'token' => $result['token'],
                    'user' => new MobileUserResource($user)
                ], 'Вход выполнен успешно');
            }

            LogService::authLog('mobile_login_failed', [
                'email' => $request->email,
                'reason' => $result['message'] ?? 'auth_failed'
            ]);

            return MobileResponse::error($result['message'] ?? 'Неверные данные для входа', 401);
        } catch (\Throwable $e) {
            Log::error('[MobileAuthController] Login error', ['exception' => $e->getMessage()]);
            return MobileResponse::error('Внутренняя ошибка сервера', 500);
        }
    }

    /**
     * Получение информации о текущем пользователе.
     */
    public function me(): JsonResponse
    {
        return PerformanceMonitor::measure('mobile.me', function() {
            $result = $this->authService->me($this->guard);

            if (!$result['success']) {
                return MobileResponse::error($result['message'], 404);
            }

            /** @var \App\Models\User $user */
            $user = $result['user'];
            $user->load('organizations');

            return MobileResponse::success(new MobileUserResource($user));
        });
    }

    /**
     * Обновление токена.
     */
    public function refresh(): JsonResponse
    {
        return PerformanceMonitor::measure('mobile.refresh_token', function() {
            $result = $this->authService->refresh($this->guard);

            if (!$result['success']) {
                return MobileResponse::error($result['message'], $result['status_code'] ?? 401);
            }

            return MobileResponse::success(['token' => $result['token']], 'Токен успешно обновлен');
        });
    }

    /**
     * Выход пользователя.
     */
    public function logout(): JsonResponse
    {
        return PerformanceMonitor::measure('mobile.logout', function() {
            $result = $this->authService->logout($this->guard);

            if (!$result['success']) {
                return MobileResponse::error($result['message'], $result['status_code'] ?? 401);
            }

            LogService::authLog('mobile_logout', [
                'user_id' => auth()->id(),
                'ip' => request()->ip()
            ]);

            return MobileResponse::success(null, 'Выход выполнен успешно');
        });
    }

    /**
     * Переключение текущей организации.
     */
    public function switchOrganization(Request $request): JsonResponse
    {
        $request->validate(['organization_id' => 'required|integer']);
        $organizationId = (int) $request->organization_id;

        /** @var \App\Models\User $user */
        $user = Auth($this->guard)->user();

        if (!$user->belongsToOrganization($organizationId)) {
            return MobileResponse::error('Вы не состоите в данной организации или доступ заблокирован', 403);
        }

        // Обновляем текущую организацию
        $user->current_organization_id = $organizationId;
        $user->save();

        // Генерируем новый токен с новым claim
        $customClaims = ['organization_id' => $organizationId];
        $token = \Tymon\JWTAuth\Facades\JWTAuth::claims($customClaims)->fromUser($user);

        Log::info('[MobileAuthController] Organization switched', [
            'user_id' => $user->id,
            'new_org_id' => $organizationId
        ]);

        return MobileResponse::success([
            'token' => $token,
            'user' => new MobileUserResource($user->load('organizations'))
        ], 'Организация успешно переключена');
    }
}
