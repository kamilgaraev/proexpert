<?php

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LandingAdminAuthService;
use App\DTOs\Auth\LoginDTO;
use App\Http\Requests\Api\V1\Landing\Auth\LoginRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class LandingAdminAuthController extends Controller
{
    protected LandingAdminAuthService $authService;
    protected string $guard = 'api_landing_admin';

    public function __construct(LandingAdminAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * РђРІС‚РѕСЂРёР·Р°С†РёСЏ Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°.
     */
    public function login(LoginRequest $request)
    {
        $loginDTO = LoginDTO::fromRequest($request->only('email', 'password'));
        $result = $this->authService->authenticate($loginDTO);
        if ($result['success']) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'token' => $result['token'],
                'landingAdmin' => $result['user'],
            ]);
        }
        return \App\Http\Responses\LandingResponse::fromPayload(['message' => $result['message'] ?? 'Unauthorized'], 401);
    }

    /**
     * Р”Р°РЅРЅС‹Рµ С‚РµРєСѓС‰РµРіРѕ Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°.
     */
    public function me(Request $request)
    {
        $result = $this->authService->me();
        if ($result['success']) {
            return \App\Http\Responses\LandingResponse::fromPayload($result['user']);
        }
        return \App\Http\Responses\LandingResponse::fromPayload(['message' => $result['message'] ?? 'Not found'], 404);
    }

    /**
     * РћР±РЅРѕРІР»РµРЅРёРµ JWT.
     */
    public function refresh()
    {
        $result = $this->authService->refresh();
        if ($result['success']) {
            return \App\Http\Responses\LandingResponse::fromPayload(['token' => $result['token']]);
        }
        return \App\Http\Responses\LandingResponse::fromPayload(['message' => $result['message'] ?? 'Token error'], $result['status_code'] ?? 401);
    }

    /**
     * Logout.
     */
    public function logout()
    {
        $this->authService->logout();
        return \App\Http\Responses\LandingResponse::fromPayload(['message' => 'Logged out']);
    }
} 