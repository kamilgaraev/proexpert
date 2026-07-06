<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Auth\LoginRequest;
use App\Http\Responses\LandingResponse;
use App\Services\Auth\LandingAdminAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function trans_message;

class LandingAdminAuthController extends Controller
{
    protected string $guard = 'api_landing_admin';

    public function __construct(
        protected LandingAdminAuthService $authService
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
        $result = $this->authService->authenticate($loginDTO);

        if ($result['success']) {
            return LandingResponse::success([
                'token' => $result['token'],
                'landingAdmin' => $result['user'],
            ], trans_message('landing.admin_auth.login_success'));
        }

        return LandingResponse::error(
            (string) ($result['message'] ?? trans_message('landing.admin_auth.login_failed')),
            (int) ($result['status_code'] ?? 401)
        );
    }

    public function me(Request $request): JsonResponse
    {
        $result = $this->authService->me();

        if ($result['success']) {
            return LandingResponse::success($result['user'], trans_message('landing.admin_auth.profile_loaded'));
        }

        return LandingResponse::error(trans_message('landing.admin_auth.profile_not_found'), 404);
    }

    public function refresh(): JsonResponse
    {
        $result = $this->authService->refresh();

        if ($result['success']) {
            return LandingResponse::success([
                'token' => $result['token'],
            ], trans_message('landing.admin_auth.token_refreshed'));
        }

        return LandingResponse::error(
            trans_message('landing.admin_auth.token_error'),
            $result['status_code'] ?? 401
        );
    }

    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return LandingResponse::success(null, trans_message('landing.admin_auth.logged_out'));
    }
}
