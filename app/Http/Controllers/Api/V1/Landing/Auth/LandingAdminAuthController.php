<?php

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\JwtAuthService;
use App\DTOs\Auth\LoginDTO;
use App\Http\Requests\Api\V1\Landing\Auth\LoginRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class LandingAdminAuthController extends Controller
{
    protected JwtAuthService $authService;
    protected string $guard = 'api_landing_admin';

    public function __construct(JwtAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Авторизация администратора.
     */
    public function login(LoginRequest $request)
    {
        $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
        $result = $this->authService->authenticate($loginDTO, $this->guard);
        if ($result['success']) {
            return response()->json([
                'token' => $result['token'],
                'landingAdmin' => $result['user'],
            ]);
        }
        return response()->json(['message' => $result['message'] ?? 'Unauthorized'], 401);
    }

    /**
     * Данные текущего администратора.
     */
    public function me(Request $request)
    {
        $result = $this->authService->me($this->guard);
        if ($result['success']) {
            return response()->json($result['user']);
        }
        return response()->json(['message' => $result['message'] ?? 'Not found'], 404);
    }

    /**
     * Обновление JWT.
     */
    public function refresh()
    {
        $result = $this->authService->refresh($this->guard);
        if ($result['success']) {
            return response()->json(['token' => $result['token']]);
        }
        return response()->json(['message' => $result['message'] ?? 'Token error'], $result['status_code'] ?? 401);
    }

    /**
     * Logout.
     */
    public function logout()
    {
        $this->authService->logout($this->guard);
        return response()->json(['message' => 'Logged out']);
    }
} 