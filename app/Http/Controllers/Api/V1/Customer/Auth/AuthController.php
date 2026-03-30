<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Landing\Auth\RegisterRequest;
use App\Http\Responses\CustomerResponse;
use App\Services\Auth\JwtAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class AuthController extends Controller
{
    private const GUARD = 'api_landing';

    public function __construct(
        private readonly JwtAuthService $authService
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
                    $result['status_code'] ?? 401
                );
            }

            return CustomerResponse::success(
                [
                    'token' => $result['token'],
                    'user' => $result['user'],
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
                [
                    'token' => $result['token'],
                    'user' => $result['user'],
                    'organization' => $result['organization'],
                ],
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
}
