<?php

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\DTOs\Auth\WebAuthTokenPair;
use App\DTOs\Auth\WebAuthTokenPayload;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Landing\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Landing\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Landing\Auth\ResetPasswordRequest;
use App\Http\Responses\Auth\ProfileResponse;
use App\Http\Responses\Auth\RegisterResponse;
use App\Http\Responses\LandingResponse;
use App\Jobs\Auth\CompleteRegistrationSideEffects;
use App\Models\User;
use App\Services\Auth\JwtAuthService;
use App\Services\Auth\RegistrationIdempotencyService;
use App\Services\Auth\UserConsentService;
use App\Services\Auth\WebAuthenticationService;
use App\Services\Auth\WebRefreshCookieService;
use App\Services\PerformanceMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    protected $authService;

    protected $guard = 'api_landing';

    /**
     * Создание нового экземпляра контроллера.
     */
    public function __construct(
        JwtAuthService $authService,
        private readonly WebAuthenticationService $webAuthentication,
        private readonly WebRefreshCookieService $refreshCookies,
        private readonly RegistrationIdempotencyService $registrationIdempotency,
        private readonly UserConsentService $userConsents,
    ) {
        $this->authService = $authService;
    }

    /**
     * Регистрация нового пользователя и организации.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(RegisterRequest $request)
    {
        return PerformanceMonitor::measure('landing.register', function () use ($request) {
            $registrationData = $request->safe()->except(['avatar', 'idempotency_key']);

            try {
                $result = $this->registrationIdempotency->execute(
                    'lk',
                    (string) $request->input('idempotency_key'),
                    $registrationData,
                    function () use ($registrationData): array {
                        $result = $this->authService->register(RegisterDTO::fromRequest($registrationData));

                        if (($result['success'] ?? false) !== true || ! isset($result['user'])) {
                            return $result;
                        }

                        $acceptedAt = CarbonImmutable::now();
                        $this->userConsents->record(
                            $result['user'],
                            'terms',
                            (string) config('web_auth.registration.terms_version'),
                            $acceptedAt,
                        );
                        $this->userConsents->record(
                            $result['user'],
                            'privacy',
                            (string) config('web_auth.registration.privacy_version'),
                            $acceptedAt,
                        );

                        return $result;
                    },
                );
            } catch (BusinessLogicException $exception) {
                return RegisterResponse::error(
                    $exception->getMessage(),
                    $exception->getCode() >= 400 && $exception->getCode() < 500 ? $exception->getCode() : 409,
                );
            }

            if (! $result['success']) {
                return RegisterResponse::error($result['message'], $result['status_code']);
            }

            // Проверяем, что все необходимые данные присутствуют
            if (! isset($result['user']) || ! isset($result['organization'])) {
                Log::error('[LandingAuthController] Missing data in registration result', [
                    'has_user' => isset($result['user']),
                    'has_organization' => isset($result['organization']),
                    'has_status' => isset($result['status']),
                ]);

                return RegisterResponse::error(trans_message('auth.registration_incomplete_data'), 500);
            }

            /** @var \App\Models\User $user */
            $user = $result['user'];
            $organization = $result['organization'];

            if (($result['idempotent_replay'] ?? false) !== true) {
                CompleteRegistrationSideEffects::dispatch($user->id, $organization->id)->afterCommit();
            }

            if ($request->hasFile('avatar') && ($result['idempotent_replay'] ?? false) !== true) {
                // Вызываем метод из трейта HasImages
                if ($user->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'public')) {
                    // Если uploadImage успешен (путь установлен), сохраняем пользователя
                    // Предполагаем, что authService->register мог не сохранить User или что повторное сохранение безопасно
                    $user->save();
                    Log::info('[LandingAuthController] Avatar uploaded and user saved.', ['user_id' => $user->id, 'avatar_path' => $user->avatar_path]);
                } else {
                    // Логируем ошибку загрузки аватара, но продолжаем регистрацию
                    Log::error('[LandingAuthController] Failed to upload avatar during registration.', ['user_id' => $user->id]);
                    // Можно вернуть ошибку, если загрузка аватара критична, но обычно нет.
                }
            }

            // Возвращаем успешный ответ
            return RegisterResponse::verificationRequired($user, $organization)->toResponse($request);
        });
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $email = Str::lower(trim((string) $validated['email']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->first();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendFrontendEmailVerificationNotification((string) config('app.frontend_url'));
            } catch (Throwable $exception) {
                Log::error('auth.email_verification_resend_dispatch_failed', [
                    'user_id' => $user->id,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return LandingResponse::success(null, trans_message('auth.email_verification_resend_opaque'));
    }

    /**
     * Вход пользователя.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        try {
            $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
            $result = $this->webAuthentication->authenticate(
                $loginDTO,
                'lk',
                $this->guard,
                $request->boolean('remember_me'),
            );

            if ($result['success']) {
                $tokens = $result['tokens'] ?? null;

                if (! $tokens instanceof WebAuthTokenPair) {
                    return LandingResponse::error(trans_message('auth.login_internal_error'), 500);
                }

                return LandingResponse::success([
                    'user' => $result['user'],
                    'token' => $tokens->accessToken,
                    'token_type' => 'bearer',
                    'expires_in' => max(0, $tokens->accessExpiresAt->getTimestamp() - time()),
                    'csrf_token' => $tokens->csrfToken,
                ], trans_message('auth.login_success'))
                    ->withCookie($this->refreshCookies->make('lk', $tokens->refreshToken, $tokens->refreshExpiresAt));
            }

            return LandingResponse::error(
                (string) ($result['message'] ?? trans_message('auth.login_failed')),
                (int) ($result['status_code'] ?? 401),
            );
        } catch (\Throwable $e) {
            Log::error('[LandingAuthController] Unexpected exception', [
                'exception_class' => $e::class,
            ]);

            return LandingResponse::error(trans_message('auth.login_error'), 500);
        }
    }

    /**
     * Получение информации о текущем пользователе.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $this->authService->sendResetLink((string) $request->validated('email'));

            return LandingResponse::success(null, trans_message('auth.password_reset.email_sent'));
        } catch (\Throwable $e) {
            Log::error('[LandingAuthController] Password reset link failed', [
                'exception_class' => $e::class,
            ]);

            return LandingResponse::error(trans_message('auth.password_reset.email_error'), 500);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->resetPassword($request->validated());

            if (! $result['success']) {
                return LandingResponse::error(
                    $result['message'] ?? trans_message('auth.password_reset.invalid'),
                    $result['status_code'] ?? 422
                );
            }

            return LandingResponse::success(['reset' => true], trans_message('auth.password_reset.success'));
        } catch (\Throwable $e) {
            Log::error('[LandingAuthController] Password reset failed', [
                'exception_class' => $e::class,
            ]);

            return LandingResponse::error(trans_message('auth.password_reset.error'), 500);
        }
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if ($user === null) {
            return ProfileResponse::notFound(trans_message('auth.profile_not_found'));
        }

        return ProfileResponse::userProfile($user);
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
            return LandingResponse::error(trans_message('auth.token_error'), 401)
                ->withCookie($this->refreshCookies->clear('lk'));
        }

        try {
            $tokens = $this->webAuthentication->refresh($request->user(), $payload, $refreshToken);

            return LandingResponse::success([
                'token' => $tokens->accessToken,
                'token_type' => 'bearer',
                'expires_in' => max(0, $tokens->accessExpiresAt->getTimestamp() - time()),
                'csrf_token' => $tokens->csrfToken,
            ], trans_message('auth.token_refreshed'))
                ->withCookie($this->refreshCookies->make('lk', $tokens->refreshToken, $tokens->refreshExpiresAt));
        } catch (\Throwable $exception) {
            Log::warning('landing.web_auth.refresh_failed', [
                'exception_class' => $exception::class,
            ]);

            return LandingResponse::error(trans_message('auth.token_error'), 401)
                ->withCookie($this->refreshCookies->clear('lk'));
        }
    }

    public function csrf(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('web_refresh_payload');

        if (! $payload instanceof WebAuthTokenPayload || ! is_string($payload->csrfToken)) {
            return LandingResponse::error(trans_message('auth.token_error'), 401);
        }

        return LandingResponse::success(['csrf_token' => $payload->csrfToken]);
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
            return LandingResponse::error(trans_message('auth.token_error'), 401)
                ->withCookie($this->refreshCookies->clear('lk'));
        }

        $this->webAuthentication->logout($user, 'lk', $payload->sessionUuid);

        return LandingResponse::success(null, trans_message('auth.logout_success'))
            ->withCookie($this->refreshCookies->clear('lk'));
    }
}
