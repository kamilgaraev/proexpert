<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Customer\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Customer\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Customer\Auth\ResetPasswordRequest;
use App\Http\Responses\CustomerResponse;
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
        private readonly CustomerAuthService $authService
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $loginDTO = LoginDTO::fromRequest($request->validated());
            $result = $this->authService->authenticate($loginDTO, self::GUARD);

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.login_error'),
                    $result['status_code'] ?? 401,
                    null,
                    $this->extractExtraFields($result)
                );
            }

            return CustomerResponse::success(
                [
                    'token' => $result['token'],
                    'user' => $result['user'],
                    'organization' => $result['organization'],
                    'email_verified' => $result['email_verified'],
                    'available_interfaces' => $result['available_interfaces'],
                ],
                trans_message('customer.auth.login_success')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.login.failed', [
                'email' => $request->input('email'),
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
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
                'email' => $request->input('email'),
                'organization_name' => $request->input('organization_name'),
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.register_error'), 500);
        }
    }

    public function refresh(Request $request): JsonResponse
    {
        try {
            $result = $this->authService->refresh(self::GUARD);

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.refresh_error'),
                    $result['status_code'] ?? 401
                );
            }

            return CustomerResponse::success(
                ['token' => $result['token']],
                trans_message('customer.auth.refresh_success')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.refresh.failed', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.refresh_error'), 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $result = $this->authService->logout(self::GUARD);

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.logout_error'),
                    $result['status_code'] ?? 400
                );
            }

            return CustomerResponse::success(
                null,
                trans_message('customer.auth.logout_success')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.logout.failed', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
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
                'email' => $request->input('email'),
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
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
                'email' => $request->input('email'),
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
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
