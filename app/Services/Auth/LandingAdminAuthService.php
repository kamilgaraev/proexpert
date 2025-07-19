<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;

class LandingAdminAuthService
{
    protected string $guard = 'api_landing_admin';

    public function authenticate(LoginDTO $loginDTO): array
    {
        Auth::shouldUse($this->guard);
        $credentials = $loginDTO->toArray();
        if (!Auth::validate($credentials)) {
            return ['success' => false, 'message' => 'Неверный email или пароль', 'status_code' => 401];
        }
        $admin = Auth::getLastAttempted();
        $token = JWTAuth::fromUser($admin);
        return ['success' => true, 'token' => $token, 'user' => $admin, 'status_code' => 200];
    }

    public function me(): array
    {
        Auth::shouldUse($this->guard);
        $user = Auth::user();
        return $user ? ['success' => true, 'user' => $user] : ['success' => false, 'message' => 'Not authenticated'];
    }

    public function refresh(): array
    {
        try {
            $token = JWTAuth::parseToken()->refresh();
            return ['success' => true, 'token' => $token];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Token refresh error', 'status_code' => 401];
        }
    }

    public function logout(): void
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Throwable $e) {
            // ignore
        }
    }
} 