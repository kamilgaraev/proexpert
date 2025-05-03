<?php

namespace App\Http\Controllers\Api\V1\Mobile\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\Auth\LoginRequest;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\ProfileResponse;
use App\Http\Responses\Auth\TokenResponse;
use App\Services\Auth\JwtAuthService;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    protected $authService;
    protected $guard = 'api_mobile';

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
        Log::info('[MobileAuthController] Login attempt', [/*...*/]);
        try {
            $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
            $result = $this->authService->authenticate($loginDTO, $this->guard);
            Log::info('[MobileAuthController] Authentication result', ['success' => $result['success'] ?? 'N/A']);

            if ($result['success']) {
                /** @var \App\Models\User $user */
                $user = $result['user'];
                $organizationId = $user->current_organization_id;

                Log::info('[MobileAuthController] Auth successful, checking Mobile App access via Gate...', ['user_id' => $user->id, 'org_id' => $organizationId]);
                // Используем Gate для проверки прав доступа к Мобильному приложению
                // Явно указываем пользователя для Gate
                if (Gate::forUser($user)->denies('access-mobile-app', [$organizationId])) { // Передаем $organizationId в Gate
                    LogService::authLog('mobile_login_forbidden', [/*...*/]);
                    Log::warning('[MobileAuthController] Gate \'access-mobile-app\' denied access.', ['user_id' => $user->id, 'org_id' => $organizationId]);
                    // Используем наш сервис для инвалидации JWT токена без логирования стандартного logout
                    $this->authService->logout($this->guard, false);
                    return LoginResponse::forbidden('У вас нет доступа к мобильному приложению');
                }

                Log::info('[MobileAuthController] Gate \'access-mobile-app\' allowed access.');
                LogService::authLog('mobile_login_success', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'device_info' => $request->header('User-Agent'),
                    'app_version' => $request->header('X-App-Version', 'unknown')
                ]);
                return LoginResponse::loginSuccess($user, $result['token']);
            } else {
                Log::warning('[MobileAuthController] Authentication failed.');
                LogService::authLog('mobile_login_failed', [
                    'email' => $request->email,
                    'device_info' => $request->header('User-Agent'),
                    'app_version' => $request->header('X-App-Version', 'unknown')
                ]);
                return LoginResponse::unauthorized($result['message']);
            }
        } catch (\Throwable $e) {
            Log::error('[MobileAuthController] Unexpected exception', [/*...*/]);
            return response()->json(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }

    /**
     * Получение информации о текущем пользователе.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return PerformanceMonitor::measure('mobile.me', function() {
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
        return PerformanceMonitor::measure('mobile.refresh_token', function() {
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
        return PerformanceMonitor::measure('mobile.logout', function() {
            $result = $this->authService->logout($this->guard);

            if (!$result['success']) {
                return TokenResponse::tokenError($result['message'], $result['status_code']);
            }

            LogService::authLog('mobile_logout', [
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
                'app_version' => request()->header('X-App-Version', 'unknown')
            ]);

            return TokenResponse::invalidated();
        });
    }
}
