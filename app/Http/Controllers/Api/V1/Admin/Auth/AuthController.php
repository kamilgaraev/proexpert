<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\DTOs\Activity\ActivityEventData;
use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\WebAuthTokenPair;
use App\DTOs\Auth\WebAuthTokenPayload;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivityResultEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Auth\LoginRequest;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\Activity\ActivityEventRecorder;
use App\Services\Auth\WebAuthenticationService;
use App\Services\Auth\WebRefreshCookieService;
use App\Services\Logging\LoggingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    protected $logging;
    protected $guard = 'api_admin';

    public function __construct(
        private readonly WebAuthenticationService $webAuthentication,
        private readonly WebRefreshCookieService $refreshCookies,
        private readonly AuthorizationService $authorization,
        LoggingService $logging,
    ) {
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
        $this->logging->security('auth.admin.login.attempt', [
            'interface' => 'admin',
        ], 'info');

        try {
            $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
            $result = $this->webAuthentication->authenticate(
                $loginDTO,
                'admin',
                $this->guard,
                $request->boolean('remember_me'),
            );

            if ($result['success']) {
                /** @var \App\Models\User $user */
                $user = $result['user'];
                $organizationId = $user->current_organization_id !== null
                    ? (int) $user->current_organization_id
                    : null;
                $tokens = $result['tokens'] ?? null;
                $sessionUuid = $result['session_uuid'] ?? null;
                $systemAccess = $this->authorization->can($user, 'admin.access', ['context_type' => 'system']);
                $orgAccess = $organizationId !== null && $this->authorization->can($user, 'admin.access', [
                    'context_type' => 'organization',
                    'organization_id' => $organizationId,
                ]);

                if (! $tokens instanceof WebAuthTokenPair || ! is_string($sessionUuid)) {
                    if (is_string($sessionUuid)) {
                        $this->webAuthentication->logout($user, 'admin', $sessionUuid);
                    }

                    return AdminResponse::error(trans_message('auth.server_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                $canAccess = $systemAccess || $orgAccess;

                if (!$canAccess) {
                    $this->webAuthentication->logout($user, 'admin', $sessionUuid);
                    $this->logging->security('auth.admin.access.denied', [
                        'user_id' => $user->id,
                        'organization_id' => $organizationId,
                        'system_access' => $systemAccess,
                        'org_access' => $orgAccess,
                        'reason' => 'insufficient_permissions',
                    ], 'warning');

                    if ($organizationId !== null) {
                        $this->recordAuthActivity(
                            'auth.access.denied',
                            $user,
                            $organizationId,
                            ActivityResultEnum::Blocked,
                            ActivitySeverityEnum::Warning,
                            ['reason' => 'insufficient_permissions'],
                        );
                    }

                    return AdminResponse::error(trans_message('auth.access_denied'), Response::HTTP_FORBIDDEN);
                }

                $this->logging->security('auth.admin.login.success', [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'system_access' => $systemAccess,
                    'org_access' => $orgAccess,
                    'interface' => 'admin',
                ]);

                if ($organizationId !== null) {
                    $this->recordAuthActivity(
                        'auth.login.success',
                        $user,
                        $organizationId,
                        ActivityResultEnum::Success,
                        ActivitySeverityEnum::Notice,
                    );
                }

                return AdminResponse::success([
                    'user' => $user,
                    'token' => $tokens->accessToken,
                    'token_type' => 'bearer',
                    'expires_in' => max(0, $tokens->accessExpiresAt->getTimestamp() - time()),
                    'csrf_token' => $tokens->csrfToken,
                ], trans_message('auth.login_success'))
                    ->withCookie($this->refreshCookies->make('admin', $tokens->refreshToken, $tokens->refreshExpiresAt));
            }

            $this->logging->security('auth.admin.login.failed', [
                'status_code' => (int) ($result['status_code'] ?? Response::HTTP_UNAUTHORIZED),
            ], 'warning');

            return AdminResponse::error(
                $result['message'] ?? trans_message('auth.login_failed'),
                $result['status_code'] ?? Response::HTTP_UNAUTHORIZED,
            );
        } catch (\Throwable $e) {
            $this->logging->technical('auth.admin.login.exception', [
                'exception_class' => $e::class,
            ], 'error');

            return AdminResponse::error(trans_message('auth.server_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Получение информации о текущем пользователе.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        if ($request->user() === null) {
            return AdminResponse::error(trans_message('auth.profile_not_found'), Response::HTTP_NOT_FOUND);
        }

        return AdminResponse::success(['user' => $request->user()]);
    }

    /**
     * Обновление токена.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(Request $request)
    {
        $payload = $request->attributes->get('web_refresh_payload');
        $refreshToken = $request->attributes->get('web_refresh_token');

        if (! $payload instanceof WebAuthTokenPayload || ! is_string($refreshToken) || $request->user() === null) {
            return AdminResponse::error(trans_message('auth.token_error'), Response::HTTP_UNAUTHORIZED)
                ->withCookie($this->refreshCookies->clear('admin'));
        }

        try {
            $tokens = $this->webAuthentication->refresh($request->user(), $payload, $refreshToken);

            return AdminResponse::success([
                'token' => $tokens->accessToken,
                'token_type' => 'bearer',
                'expires_in' => max(0, $tokens->accessExpiresAt->getTimestamp() - time()),
                'csrf_token' => $tokens->csrfToken,
            ], trans_message('auth.token_refreshed'))
                ->withCookie($this->refreshCookies->make('admin', $tokens->refreshToken, $tokens->refreshExpiresAt));
        } catch (\Throwable $exception) {
            $this->logging->security('auth.admin.refresh.failed', [
                'exception_class' => $exception::class,
            ], 'warning');

            return AdminResponse::error(trans_message('auth.token_error'), Response::HTTP_UNAUTHORIZED)
                ->withCookie($this->refreshCookies->clear('admin'));
        }
    }

    public function csrf(Request $request)
    {
        $payload = $request->attributes->get('web_refresh_payload');

        if (! $payload instanceof WebAuthTokenPayload || ! is_string($payload->csrfToken)) {
            return AdminResponse::error(trans_message('auth.token_error'), Response::HTTP_UNAUTHORIZED);
        }

        return AdminResponse::success(['csrf_token' => $payload->csrfToken]);
    }

    /**
     * Выход пользователя.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $payload = $request->attributes->get('web_auth_payload');
        $user = $request->user();

        if (! $payload instanceof WebAuthTokenPayload || $user === null) {
            return AdminResponse::error(trans_message('auth.token_error'), Response::HTTP_UNAUTHORIZED)
                ->withCookie($this->refreshCookies->clear('admin'));
        }

        $this->webAuthentication->logout($user, 'admin', $payload->sessionUuid);

        return AdminResponse::success(null, trans_message('auth.logout_success'))
            ->withCookie($this->refreshCookies->clear('admin'));
    }

    private function recordAuthActivity(
        string $eventType,
        User $user,
        int $organizationId,
        ActivityResultEnum $result,
        ActivitySeverityEnum $severity,
        array $context = []
    ): void {
        app(ActivityEventRecorder::class)->record(ActivityEventData::make(
            organizationId: $organizationId,
            module: 'auth',
            eventType: $eventType,
            action: ActivityActionEnum::Login,
            actorUserId: $user->id,
            actorName: $user->name,
            actorEmail: $user->email,
            interface: 'admin',
            result: $result,
            severity: $severity,
            subjectType: 'user',
            subjectId: $user->id,
            subjectLabel: $user->name,
            targetUserId: $user->id,
            context: $context
        ));
    }
}
