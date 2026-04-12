<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades\Auth;

use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Brigades\Auth\LoginBrigadeRequest;
use App\Http\Requests\Api\V1\Brigades\Auth\RegisterBrigadeRequest;
use App\Http\Resources\Brigades\BrigadeProfileResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class BrigadeAuthController extends Controller
{
    public function __construct(private readonly BrigadeWorkflowService $workflowService)
    {
    }

    public function register(RegisterBrigadeRequest $request): JsonResponse
    {
        try {
            $result = $this->workflowService->register($request->validated());

            return AdminResponse::success([
                'token' => $result['token'],
                'brigade' => new BrigadeProfileResource($result['brigade']),
            ], trans_message('brigades.registered'), 201);
        } catch (\Throwable $exception) {
            Log::error('Brigade registration failed', ['error' => $exception->getMessage()]);

            return AdminResponse::error(trans_message('brigades.registration_failed'), 500);
        }
    }

    public function login(LoginBrigadeRequest $request): JsonResponse
    {
        $result = $this->workflowService->authenticate($request->validated());

        if (!$result) {
            return AdminResponse::error(trans_message('brigades.login_failed'), 401);
        }

        return AdminResponse::success([
            'token' => $result['token'],
            'brigade' => new BrigadeProfileResource($result['brigade']),
        ], trans_message('brigades.login_success'));
    }

    public function me(Request $request): JsonResponse
    {
        try {
            $brigade = $this->workflowService->getOwnedBrigade($request->user());

            return AdminResponse::success(new BrigadeProfileResource($brigade));
        } catch (\Throwable $exception) {
            return AdminResponse::error(trans_message('brigades.profile_not_found'), 404);
        }
    }

    public function logout(): JsonResponse
    {
        try {
            if (JWTAuth::getToken()) {
                JWTAuth::invalidate(JWTAuth::getToken());
            }

            return AdminResponse::success(null, trans_message('brigades.logout_success'));
        } catch (\Throwable $exception) {
            return AdminResponse::error(trans_message('brigades.logout_failed'), 500);
        }
    }
}
