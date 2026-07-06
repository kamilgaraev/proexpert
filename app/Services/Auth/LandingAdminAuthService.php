<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Models\LandingAdmin;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;

use function trans_message;

class LandingAdminAuthService
{
    protected string $guard = 'api_landing_admin';

    public function authenticate(LoginDTO $loginDTO): array
    {
        Auth::shouldUse($this->guard);
        $credentials = $loginDTO->toArray();

        if (!Auth::validate($credentials)) {
            return ['success' => false, 'message' => trans_message('auth.login_failed'), 'status_code' => 401];
        }

        /** @var LandingAdmin|null $admin */
        $admin = Auth::getLastAttempted();

        if (!$admin instanceof LandingAdmin) {
            return ['success' => false, 'message' => trans_message('auth.login_failed'), 'status_code' => 401];
        }

        if (!$admin->is_active) {
            return ['success' => false, 'message' => trans_message('auth.account_disabled'), 'status_code' => 403];
        }

        $token = JWTAuth::fromUser($admin);

        return ['success' => true, 'token' => $token, 'user' => $admin, 'status_code' => 200];
    }

    public function me(): array
    {
        Auth::shouldUse($this->guard);
        $user = Auth::user();

        return $user
            ? ['success' => true, 'user' => $user]
            : ['success' => false, 'message' => trans_message('auth.unauthorized')];
    }

    public function refresh(): array
    {
        try {
            $token = JWTAuth::parseToken()->refresh();

            return ['success' => true, 'token' => $token];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => trans_message('auth.token_error'), 'status_code' => 401];
        }
    }

    public function logout(): void
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Throwable $e) {
        }
    }
}
