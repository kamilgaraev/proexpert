<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\DTOs\Auth\WebAuthTokenPair;
use App\DTOs\Auth\WebAuthTokenPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Customer\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Customer\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Customer\Auth\ResetPasswordRequest;
use App\Http\Responses\CustomerResponse;
use App\Services\Auth\WebAuthenticationService;
use App\Services\Auth\WebRefreshCookieService;
use App\Services\Customer\Auth\CustomerAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class AuthController extends Controller
{
    private const GUARD = 'api_landing';

    public function __construct(
        private readonly CustomerAuthService $authService,
        private readonly WebAuthenticationService $webAuthentication,
        private readonly WebRefreshCookieService $refreshCookies,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $loginDTO = LoginDTO::fromRequest($request->validated());
            $result = $this->webAuthentication->establishFromAuthenticationResult(
                $this->authService->authenticate($loginDTO, self::GUARD),
                'customer',
                $request->boolean('remember_me'),
            );

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.login_error'),
                    $result['status_code'] ?? 401,
                    null,
                    $this->extractExtraFields($result)
                );
            }

            $tokens = $result['tokens'] ?? null;

            if (!$tokens instanceof WebAuthTokenPair) {
                return CustomerResponse::error(trans_message('customer.auth.login_error'), 500);
            }

            return CustomerResponse::success([
                'token' => $tokens->accessToken,
                'token_type' => 'bearer',
                'expires_in' => max(0, $tokens->accessExpiresAt->getTimestamp() - time()),
                'csrf_token' => $tokens->csrfToken,
                'user' => $result['user'],
                'organization' => $result['organization'],
                'email_verified' => $result['email_verified'],
                'available_interfaces' => $result['available_interfaces'],
            ], trans_message('customer.auth.login_success'))
                ->withCookie($this->refreshCookies->make(
                    'customer',
                    $tokens->refreshToken,
                    $tokens->refreshExpiresAt,
                ));
        } catch (Throwable $exception) {
            Log::error('customer.auth.login.failed', [
                'exception_class' => $exception::class,
            ]);

            return CustomerResponse::error(trans_message('customer.auth.login_error'), 500);
        }
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $registerDTO = RegisterDTO::fromRequest($request->safe()->except('avatar'));
            $result = $this->authService->register(
                $registerDTO,
                (string) config('app.customer_frontend_url')
            );

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.register_error'),
                    $result['status_code'] ?? 400
                );
            }

            return CustomerResponse::success(
                $result,
                trans_message('customer.auth.register_success'),
                201
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.register.failed', [
                'exception_class' => $exception::class,
            ]);

            return CustomerResponse::error(trans_message('customer.auth.register_error'), 500);
        }
    }

    public function refresh(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('web_refresh_payload');
        $refreshToken = $request->attributes->get('web_refresh_token');

        if (!$payload instanceof WebAuthTokenPayload || !is_string($refreshToken) || $request->user() === null) {
            return CustomerResponse::error(trans_message('customer.auth.refresh_error'), 401)
                ->withCookie($this->refreshCookies->clear('customer'));
        }

        try {
            $tokens = $this->webAuthentication->refresh($request->user(), $payload, $refreshToken);

            return CustomerResponse::success([
                'token' => $tokens->accessToken,
                'token_type' => 'bearer',
                'expires_in' => max(0, $tokens->accessExpiresAt->getTimestamp() - time()),
                'csrf_token' => $tokens->csrfToken,
            ], trans_message('customer.auth.refresh_success'))
                ->withCookie($this->refreshCookies->make(
                    'customer',
                    $tokens->refreshToken,
                    $tokens->refreshExpiresAt,
                ));
        } catch (Throwable $exception) {
            Log::error('customer.auth.refresh.failed', [
                'user_id' => $request->user()?->id,
                'exception_class' => $exception::class,
            ]);

            return CustomerResponse::error(trans_message('customer.auth.refresh_error'), 401)
                ->withCookie($this->refreshCookies->clear('customer'));
        }
    }

    public function csrf(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('web_refresh_payload');

        if (!$payload instanceof WebAuthTokenPayload || !is_string($payload->csrfToken)) {
            return CustomerResponse::error(trans_message('customer.auth.refresh_error'), 401);
        }

        return CustomerResponse::success(['csrf_token' => $payload->csrfToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $payload = $request->attributes->get('web_auth_payload');
            $user = $request->user();

            if (!$payload instanceof WebAuthTokenPayload || $user === null) {
                return CustomerResponse::error(trans_message('customer.auth.logout_error'), 401)
                    ->withCookie($this->refreshCookies->clear('customer'));
            }

            $this->webAuthentication->logout($user, 'customer', $payload->sessionUuid);

            return CustomerResponse::success(
                null,
                trans_message('customer.auth.logout_success')
            )->withCookie($this->refreshCookies->clear('customer'));
        } catch (Throwable $exception) {
            Log::error('customer.auth.logout.failed', [
                'user_id' => $request->user()?->id,
                'exception_class' => $exception::class,
            ]);

            return CustomerResponse::error(trans_message('customer.auth.logout_error'), 500);
        }
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->sendResetLink((string) $request->validated('email'));

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.forgot_password_error'),
                    $result['status_code'] ?? 400
                );
            }

            return CustomerResponse::success(
                ['sent' => true],
                trans_message('customer.auth.forgot_password_success')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.forgot_password.failed', [
                'exception_class' => $exception::class,
            ]);

            return CustomerResponse::error(trans_message('customer.auth.forgot_password_error'), 500);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->resetPassword($request->validated());

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.reset_password_error'),
                    $result['status_code'] ?? 422
                );
            }

            return CustomerResponse::success(
                ['reset' => true],
                trans_message('customer.auth.reset_password_success')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.reset_password.failed', [
                'exception_class' => $exception::class,
            ]);

            return CustomerResponse::error(trans_message('customer.auth.reset_password_error'), 500);
        }
    }

    private function extractExtraFields(array $result): array
    {
        $extra = [];

        foreach (['status', 'email_verified', 'email', 'can_enter_portal'] as $field) {
            if (array_key_exists($field, $result)) {
                $extra[$field] = $result[$field];
            }
        }

        return $extra;
    }
}
